<?php

namespace Tests\Feature;

use App\Models\ActivacionReglasAudiencia;
use App\Models\EtapaAudiencia;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\TipoAudiencia;
use App\Models\TransicionAudiencia;
use App\Models\User;
use App\Services\Simulaciones\ConsultarActivacionReglas;
use App\Services\Simulaciones\ContratoEjecucionReglas;
use App\Services\Simulaciones\GestionarActivacionReglas;
use App\Services\Simulaciones\GestionarRevisionJuridicaReglas;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActivacionReglasAudienciaTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'jurissim_pruebas'
            || config('database.connections.pgsql.url')
            || DB::selectOne('select current_database() as nombre')->nombre !== 'jurissim_pruebas') {
            throw new RuntimeException('Estas pruebas requieren PostgreSQL jurissim_pruebas, sin DB_URL ni cache.');
        }
        Http::preventStrayRequests();
    }

    public function test_sello_conserva_instrucciones_explicitas_y_huella_completa(): void
    {
        [$admin, , $v] = $this->escenario();
        $this->assertSame('Instruccion sintetica 1, sin valor juridico.', $v->contenido['contrato_ejecucion']['instrucciones'][0]['texto']);
        $this->assertNotSame($v->contenido['propuestas'][0]['descripcion_propuesta'], $v->contenido['contrato_ejecucion']['instrucciones'][0]['texto']);
        $this->assertSame($admin->getKey(), $v->id_usuario_autor);
        $this->assertTrue($this->sqlValido($v->contenido, $v->id_tipo_audiencia));
        $huella = DB::selectOne("SELECT encode(sha256(convert_to(contenido::text,'UTF8')),'hex') huella FROM versiones_reglas_audiencia WHERE id_version_reglas=?", [$v->getKey()])->huella;
        $this->assertSame($huella, $v->huella_contenido);
        $otra = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $v->id_tipo_audiencia, 'Copia historica sin contrato.');
        $this->assertArrayNotHasKey('contrato_ejecucion', $otra->contenido);
        $this->assertSame($huella, $v->fresh()->huella_contenido);
    }

    public function test_no_inventa_instrucciones_ni_admite_ids_roles_o_confirmacion_ambiguos(): void
    {
        [$admin, , $v] = $this->escenario();
        $i = $v->contenido['contrato_ejecucion']['instrucciones'];
        $this->rechazo(DomainException::class, fn () => app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $v->id_tipo_audiencia, 'Prueba.', $i));
        foreach ([1, 'true', 'false'] as $confirmacion) {
            $this->rechazo(DomainException::class, fn () => app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $v->id_tipo_audiencia, 'Prueba.', $i, $confirmacion));
        }
        foreach ([[$i[0]], [...$i, $i[0]], [...$i, ['id_propuesta_turno' => 999999, 'texto' => 'Ajena.']]] as $datos) {
            $this->rechazo(DomainException::class, fn () => app(ContratoEjecucionReglas::class)->preparar($v->contenido, $v->id_tipo_audiencia, $datos), ValidationException::class);
        }
        foreach ([['id_propuesta_turno' => (string) $i[0]['id_propuesta_turno']], ['rol' => 'fiscal'], ['texto' => "\u{00a0}"], ['texto' => str_repeat('a', 5001)]] as $cambio) {
            $datos = $i;
            $datos[0] = [...$datos[0], ...$cambio];
            $this->rechazo(ValidationException::class, fn () => app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $v->id_tipo_audiencia, 'Prueba.', $datos, true));
        }
        $this->assertDatabaseCount('versiones_reglas_audiencia', 1);
    }

    public function test_revision_del_contrato_exige_confirmar_la_huella_incluso_por_sql(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        foreach ([null, str_repeat('0', 64)] as $huella) {
            $this->rechazo(DomainException::class, fn () => $this->revisar($revisor, $v, [], $huella));
        }
        $revision = $this->revisar($revisor, $v);
        $this->assertSame($v->huella_contenido, $revision->huella_ejecucion_revisada);
        $fila = $this->fila($revision);
        $fila['huella_ejecucion_revisada'] = null;
        $this->rechazoSql(fn () => DB::table('revisiones_reglas_audiencia')->insert($fila));
        $fila['huella_ejecucion_revisada'] = str_repeat('f', 64);
        $this->rechazoSql(fn () => DB::table('revisiones_reglas_audiencia')->insert($fila));
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 1);
    }

    public function test_api_anterior_no_aprueba_instrucciones_que_no_presenta(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        Sanctum::actingAs($revisor);
        $this->postJson('/api/v1/reglas-audiencia/versiones/'.$v->getKey().'/revisiones', [
            ...$this->datosRevision(), 'huella_version_esperada' => $v->huella_contenido,
            'id_ultima_revision_esperada' => null, 'estado_revision_esperado' => 'no_acreditada',
            'huella_ejecucion_revisada' => $v->huella_contenido,
        ])->assertStatus(409)->assertJsonPath('code', 'reglas_conflicto');
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 0);
    }

    public function test_aprobacion_historica_sin_contrato_no_es_activable(): void
    {
        [$admin, $revisor, $v] = $this->escenario(false);
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v, [], null);
        $this->rechazo(DomainException::class, fn () => $this->activar($admin, $v, $r));
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 0);
    }

    public function test_activacion_es_interna_no_modifica_practicas_y_consulta_no_es_permiso(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        $antes = $this->dominio();
        $a = $this->activar($admin, $v, $r, ['id_usuario_responsable' => $revisor->getKey(), 'perfil_validacion' => 'inventado', 'fecha_creacion' => '2000-01-01']);
        $this->assertSame($admin->getKey(), $a->id_usuario_responsable);
        $this->assertSame(ContratoEjecucionReglas::PERFIL, $a->perfil_validacion);
        $this->assertNotSame('2000-01-01', $a->fecha_creacion->toDateString());
        $estado = $this->estado($admin, $v);
        $this->assertSame('vigente', $estado['activation_status']);
        $this->assertTrue($estado['activation_current']);
        $this->assertTrue($estado['read_only']);
        $this->assertFalse($estado['execution_authorized']);
        $this->assertTrue($estado['requires_simulation_binding']);
        $this->assertSame($estado, $this->estado($admin, $v));
        $this->assertSame($antes, $this->dominio());
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 1);
        Http::assertNothingSent();
    }

    public function test_confirmacion_y_precondiciones_son_obligatorias(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        foreach ([false, 1, 'true'] as $confirmacion) {
            $this->rechazo(ValidationException::class, fn () => $this->activar($admin, $v, $r, ['confirmacion_humana' => $confirmacion]));
        }
        foreach ([['huella_version_esperada' => str_repeat('0', 64)], ['id_activacion_previa' => 999999]] as $cambio) {
            $this->rechazo(DomainException::class, fn () => $this->activar($admin, $v, $r, $cambio));
        }
        $datos = $this->datosActivacion($v, $r);
        unset($datos['id_activacion_previa']);
        $this->rechazo(ValidationException::class, fn () => app(GestionarActivacionReglas::class)->activar($admin, $v->getKey(), $datos));
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 0);
    }

    public function test_roles_ajenos_y_precargados_no_autorizan(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        $institucional = User::factory()->create();
        $institucional->assignRole(Role::findOrCreate('administrador_institucional', 'web'));
        foreach ([$revisor, $institucional] as $actor) {
            $this->rechazo(AuthorizationException::class, fn () => $this->activar($actor, $v, $r));
            $this->rechazo(AuthorizationException::class, fn () => $this->estado($actor, $v));
        }
        $admin->load('roles');
        DB::table('model_has_roles')->where('model_id', $admin->getKey())->delete();
        $this->rechazo(AuthorizationException::class, fn () => $this->activar($admin, $v, $r));
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 0);
    }

    public function test_revision_nueva_invalida_activacion_y_exige_otra_activacion_explicita(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        $a = $this->activar($admin, $v, $r);
        $nueva = $this->revisar($revisor, $v);
        $this->assertSame('revision_sustituida', $this->estado($admin, $v)['activation_status']);
        $this->rechazo(DomainException::class, fn () => $this->activar($admin, $v, $r, ['id_activacion_previa' => $a->getKey()]));
        $this->rechazo(DomainException::class, fn () => $this->activar($admin, $v, $nueva));
        $segunda = $this->activar($admin, $v, $nueva, ['id_activacion_previa' => $a->getKey()]);
        $this->assertSame($segunda->getKey(), $this->estado($admin, $v)['activation_id']);
        $this->assertTrue($this->estado($admin, $v)['activation_current']);
    }

    public function test_retiro_idempotente_no_recupera_activacion_anterior_ni_reutiliza_revision(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        $a = $this->activar($admin, $v, $r);
        $nueva = $this->revisar($revisor, $v);
        $segunda = $this->activar($admin, $v, $nueva, ['id_activacion_previa' => $a->getKey()]);
        $gestor = app(GestionarActivacionReglas::class);
        $retiro = $gestor->retirar($admin, $segunda->getKey(), 'Retiro sintetico original.');
        $this->assertSame($retiro->getKey(), $gestor->retirar($admin, $segunda->getKey(), 'No sustituir.')->getKey());
        $this->assertSame('Retiro sintetico original.', $retiro->fresh()->motivo);
        $this->assertSame('retirada', $this->estado($admin, $v)['activation_status']);
        $this->assertSame($segunda->getKey(), $this->estado($admin, $v)['activation_id']);
        $this->rechazoSql(fn () => $this->activar($admin, $v, $nueva, ['id_activacion_previa' => $segunda->getKey()]));
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 2);
        $this->assertDatabaseCount('revocaciones_activaciones_reglas', 1);
    }

    public function test_retirar_revision_o_habilitacion_invalida_sin_eliminar_historial(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $h = $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        $a = $this->activar($admin, $v, $r);
        app(GestionarRevisionJuridicaReglas::class)->revocarRevision($revisor, $r->getKey(), 'Retiro sintetico.');
        $this->assertSame('revision_retirada', $this->estado($admin, $v)['activation_status']);
        $nueva = $this->revisar($revisor, $v);
        $this->activar($admin, $v, $nueva, ['id_activacion_previa' => $a->getKey()]);
        app(GestionarRevisionJuridicaReglas::class)->revocarHabilitacion($admin, $h->getKey(), 'Retiro sintetico.');
        $this->assertSame('habilitacion_retirada', $this->estado($admin, $v)['activation_status']);
        $this->habilitar($admin, $revisor, $v);
        $this->assertSame('habilitacion_retirada', $this->estado($admin, $v)['activation_status']);
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 2);
    }

    public function test_requiere_cambios_no_activa_y_no_restaura_decision_anterior(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        $a = $this->activar($admin, $v, $r);
        $datos = $this->datosRevision();
        unset($datos['fecha_vencimiento']);
        $datos['decision'] = 'requiere_cambios';
        $ultima = app(GestionarRevisionJuridicaReglas::class)->registrar($revisor, $v->getKey(), $datos, null, $v->huella_contenido);
        $this->rechazo(DomainException::class, fn () => $this->activar($admin, $v, $ultima, ['id_activacion_previa' => $a->getKey()]));
        app(GestionarRevisionJuridicaReglas::class)->revocarRevision($revisor, $ultima->getKey(), 'Retiro sintetico.');
        $this->assertSame('revision_sustituida', $this->estado($admin, $v)['activation_status']);
    }

    public function test_tipo_actual_inactivo_bloquea_activacion_y_diagnostico(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        $this->activar($admin, $v, $r);
        TipoAudiencia::query()->whereKey($v->id_tipo_audiencia)->update(['activo' => false]);
        $this->assertSame('tipo_inactivo', $this->estado($admin, $v)['activation_status']);
        $nueva = $this->revisar($revisor, $v);
        $this->rechazoSql(fn () => $this->activar($admin, $v, $nueva, ['id_activacion_previa' => ActivacionReglasAudiencia::query()->first()->getKey()]));
    }

    public function test_reloj_postgresql_invalida_revision_sin_worker(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v, ['fecha_vencimiento' => now()->addSeconds(2)->toIso8601String()]);
        $this->activar($admin, $v, $r);
        $limite = microtime(true) + 5;
        do {
            $estado = $this->estado($admin, $v);
            if ($estado['activation_status'] === 'revision_vencida') {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $limite);
        $this->assertSame('revision_vencida', $estado['activation_status']);
        $this->assertFalse($estado['activation_current']);
    }

    public function test_sql_rechaza_contexto_responsable_perfil_huella_y_precondicion_falsificados(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        $a = $this->activar($admin, $v, $r);
        $nueva = $this->revisar($revisor, $v);
        $fila = [...$this->fila($a), 'id_revision_reglas' => $nueva->getKey(), 'id_activacion_previa' => $a->getKey()];
        foreach ([['id_usuario_responsable' => $revisor->getKey()], ['perfil_validacion' => 'libre'], ['confirmacion_humana' => false],
            ['huella_version' => str_repeat('f', 64)], ['id_activacion_previa' => null], ['id_activacion' => 0], ['id_version_reglas' => 999999], ['motivo' => ' ']] as $cambio) {
            $this->rechazoSql(fn () => DB::table('activaciones_reglas_audiencia')->insert([...$fila, ...$cambio]));
        }
        $id = DB::table('activaciones_reglas_audiencia')->insertGetId($fila, 'id_activacion');
        $this->assertSame($id, $this->estado($admin, $v)['activation_id']);
    }

    public function test_sql_rechaza_aislamiento_no_admitido(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        $a = $this->activar($admin, $v, $r);
        $conexion = DB::build(config('database.connections.pgsql'));
        $this->assertSame('jurissim_pruebas', $conexion->selectOne('SELECT current_database() nombre')->nombre);
        $conexion->beginTransaction();
        try {
            $conexion->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            // La guardia de aislamiento debe rechazar antes de leer las filas no confirmadas de la conexion principal.
            try {
                $conexion->table('activaciones_reglas_audiencia')->insert($this->fila($a));
                $this->fail('Se esperaba rechazo del aislamiento.');
            } catch (QueryException $error) {
                $this->assertSame('23514', $error->errorInfo[0]);
                $this->assertStringContainsString('Activacion exige read committed.', $error->errorInfo[2]);
            }
        } finally {
            $conexion->rollBack();
            $conexion->disconnect();
        }
        $this->assertSame($a->getKey(), $this->estado($admin, $v)['activation_id']);
    }

    public function test_historial_no_se_edita_elimina_trunca_o_revierte(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        $a = $this->activar($admin, $v, $r);
        $retiro = app(GestionarActivacionReglas::class)->retirar($admin, $a->getKey(), 'Retiro sintetico.');
        foreach ([$a, $retiro] as $registro) {
            $this->rechazo(LogicException::class, function () use ($registro): void {
                $registro->motivo = 'Alterado.';
                $registro->save();
            });
            $this->rechazo(LogicException::class, fn () => $registro->delete());
            $this->rechazoSql(fn () => DB::table($registro->getTable())->where($registro->getKeyName(), $registro->getKey())->update(['motivo' => 'Alterado.']));
            $this->rechazoSql(fn () => DB::table($registro->getTable())->delete());
            $this->rechazoSql(fn () => DB::statement('TRUNCATE '.$registro->getTable().' CASCADE'));
        }
        $migracion = require database_path('migrations/2026_10_04_000001_registrar_activaciones_reglas.php');
        $this->rechazo(RuntimeException::class, fn () => $migracion->down());
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 1);
        $this->assertDatabaseCount('revocaciones_activaciones_reglas', 1);
    }

    public function test_contrato_sellado_aun_sin_revision_impide_rollback(): void
    {
        $this->escenario();
        $migracion = require database_path('migrations/2026_10_04_000001_registrar_activaciones_reglas.php');
        $this->rechazo(RuntimeException::class, fn () => $migracion->down());
        $this->assertDatabaseCount('versiones_reglas_audiencia', 1);
    }

    public function test_contrato_php_y_sql_rechazan_el_mismo_contenido_malformado(): void
    {
        [, , $v] = $this->escenario();
        $mutaciones = [
            fn ($c) => [...$c, 'formato' => '1'],
            fn ($c) => [...$c, 'formato' => 1.0],
            fn ($c) => [...$c, 'tipo' => [...$c['tipo'], 'id_tipo_audiencia' => (string) $v->id_tipo_audiencia]],
            fn ($c) => [...$c, 'etapas' => [[...$c['etapas'][0], 'id_tipo_audiencia' => (string) $v->id_tipo_audiencia], $c['etapas'][1]]],
            fn ($c) => [...$c, 'etapas' => [[...$c['etapas'][0], 'orden' => 1.5], $c['etapas'][1]]],
            fn ($c) => [...$c, 'etapas' => [[...$c['etapas'][0], 'nombre' => "\u{00a0}"], $c['etapas'][1]]],
            fn ($c) => [...$c, 'transiciones' => []],
            fn ($c) => [...$c, 'transiciones' => [[...$c['transiciones'][0], 'id_etapa_destino' => $c['etapas'][0]['id_etapa']]]],
            fn ($c) => [...$c, 'transiciones' => [[...$c['transiciones'][0], 'id_tipo_audiencia' => (string) $v->id_tipo_audiencia]]],
            fn ($c) => [...$c, 'propuestas' => [[...$c['propuestas'][0], 'rol' => 'imputado'], $c['propuestas'][1]]],
            fn ($c) => [...$c, 'propuestas' => [[...$c['propuestas'][0], 'id_usuario_creador' => null], $c['propuestas'][1]]],
            fn ($c) => [...$c, 'propuestas' => [[...$c['propuestas'][0], 'id_tipo_audiencia' => (string) $v->id_tipo_audiencia], $c['propuestas'][1]]],
            fn ($c) => [...$c, 'contrato_ejecucion' => [...$c['contrato_ejecucion'], 'formato' => 1.0]],
            fn ($c) => [...$c, 'contrato_ejecucion' => [...$c['contrato_ejecucion'], 'rol_usuario' => 'fiscal']],
            fn ($c) => [...$c, 'contrato_ejecucion' => [...$c['contrato_ejecucion'], 'extra' => true]],
            fn ($c) => [...$c, 'contrato_ejecucion' => [...$c['contrato_ejecucion'], 'instrucciones' => array_reverse($c['contrato_ejecucion']['instrucciones'])]],
            fn ($c) => [...$c, 'contrato_ejecucion' => [...$c['contrato_ejecucion'], 'instrucciones' => [$c['contrato_ejecucion']['instrucciones'][0], $c['contrato_ejecucion']['instrucciones'][0]]]],
            fn ($c) => [...$c, 'contrato_ejecucion' => [...$c['contrato_ejecucion'], 'instrucciones' => [[...$c['contrato_ejecucion']['instrucciones'][0], 'texto' => "\u{2003}"], $c['contrato_ejecucion']['instrucciones'][1]]]],
        ];
        foreach ($mutaciones as $indice => $mutar) {
            $c = $mutar($v->contenido);
            $this->rechazo(DomainException::class, fn () => app(ContratoEjecucionReglas::class)->exigir($c, $v->id_tipo_audiencia), ValidationException::class);
            $this->assertFalse($this->sqlValido($c, $v->id_tipo_audiencia), 'Mutacion '.$indice);
        }
        $this->assertFalse((bool) DB::selectOne("SELECT jurissim_entero_contrato('9223372036854775808'::jsonb) valido")->valido);
    }

    public function test_revision_ajena_no_activa_otra_version_y_tipos_se_consultan_separados(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        [$otroAdmin, $otroRevisor, $otra] = $this->escenario(true, 'otra');
        $this->habilitar($otroAdmin, $otroRevisor, $otra);
        $otroR = $this->revisar($otroRevisor, $otra);
        $this->rechazo(DomainException::class, fn () => $this->activar($admin, $v, $otroR));
        $a = $this->activar($admin, $v, $r);
        $this->assertSame('sin_activacion', $this->estado($admin, $otra)['activation_status']);
        $otroA = $this->activar($otroAdmin, $otra, $otroR);
        app(GestionarActivacionReglas::class)->retirar($otroAdmin, $otroA->getKey(), 'Retiro sintetico.');
        $this->assertSame($a->getKey(), $this->estado($admin, $v)['activation_id']);
        $this->assertTrue($this->estado($admin, $v)['activation_current']);
    }

    public function test_bifurcacion_valida_se_conserva_sin_elegir_destino_y_ciclo_se_rechaza(): void
    {
        [, , $v] = $this->escenario();
        $c = $v->contenido;
        $c['etapas'][] = [...$c['etapas'][0], 'id_etapa' => 100, 'orden' => 3, 'codigo' => 'rama', 'es_inicial' => false];
        $c['transiciones'][] = [...$c['transiciones'][0], 'id_transicion' => 100, 'id_etapa_destino' => 100];
        $c['transiciones'][] = [...$c['transiciones'][0], 'id_transicion' => 101, 'id_etapa_origen' => 100];
        $c['propuestas'][] = [...$c['propuestas'][0], 'id_propuesta_turno' => 100, 'id_etapa' => 100];
        $c['contrato_ejecucion']['instrucciones'][] = ['id_propuesta_turno' => 100, 'texto' => 'Rama sintetica.'];
        $antes = $c;
        app(ContratoEjecucionReglas::class)->exigir($c, $v->id_tipo_audiencia);
        $this->assertSame($antes, $c);
        $this->assertTrue($this->sqlValido($c, $v->id_tipo_audiencia));
        $c['transiciones'][] = [...$c['transiciones'][0], 'id_transicion' => 102, 'id_etapa_origen' => 100, 'id_etapa_destino' => $c['etapas'][0]['id_etapa']];
        $this->rechazo(DomainException::class, fn () => app(ContratoEjecucionReglas::class)->exigir($c, $v->id_tipo_audiencia));
        $this->assertFalse($this->sqlValido($c, $v->id_tipo_audiencia));
    }

    public function test_alteracion_privilegiada_de_huella_invalida_el_diagnostico(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->habilitar($admin, $revisor, $v);
        $r = $this->revisar($revisor, $v);
        $this->activar($admin, $v, $r);
        // Exclusivamente jurissim_pruebas; el propietario SQL puede omitir triggers mediante DDL.
        DB::transaction(function () use ($v): void {
            DB::statement('ALTER TABLE versiones_reglas_audiencia DISABLE TRIGGER versiones_reglas_inmutables');
            try {
                DB::table('versiones_reglas_audiencia')->where('id_version_reglas', $v->getKey())->update(['huella_contenido' => str_repeat('0', 64)]);
            } finally {
                DB::statement('ALTER TABLE versiones_reglas_audiencia ENABLE TRIGGER versiones_reglas_inmutables');
            }
        });
        $this->assertSame('integridad_incompatible', $this->estado($admin, $v)['activation_status']);
        $this->assertFalse($this->estado($admin, $v)['activation_current']);
    }

    private function escenario(bool $conContrato = true, string $codigo = 'sintetica'): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $revisor = User::factory()->create();
        $tipo = TipoAudiencia::create(['nombre' => 'Audiencia sintetica', 'codigo' => $codigo, 'activo' => true]);
        $etapas = $instrucciones = [];
        foreach ([1, 2] as $orden) {
            $etapa = EtapaAudiencia::create(['id_tipo_audiencia' => $tipo->getKey(), 'codigo' => 'sintetica-'.$orden,
                'nombre' => 'Etapa sintetica', 'orden' => $orden, 'es_inicial' => $orden === 1, 'es_final' => $orden === 2, 'activo' => true]);
            $etapas[] = $etapa;
            $p = new PropuestaTurnoAudiencia(['id_tipo_audiencia' => $tipo->getKey(), 'id_etapa' => $etapa->getKey(),
                'orden' => 1, 'rol' => $orden === 1 ? 'juez' : 'abogado_defensor', 'acto_propuesto' => 'Acto sintetico', 'descripcion_propuesta' => 'Propuesta no ejecutable.']);
            $p->id_usuario_creador = $admin->getKey();
            $p->save();
            $instrucciones[] = ['id_propuesta_turno' => $p->getKey(), 'texto' => 'Instruccion sintetica '.$orden.', sin valor juridico.'];
        }
        TransicionAudiencia::create(['id_tipo_audiencia' => $tipo->getKey(), 'id_etapa_origen' => $etapas[0]->getKey(), 'id_etapa_destino' => $etapas[1]->getKey(), 'activo' => true]);
        $v = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $tipo->getKey(), 'Version sintetica.', $conContrato ? $instrucciones : null, $conContrato);

        return [$admin, $revisor, $v];
    }

    private function habilitar($admin, $revisor, $v)
    {
        return app(GestionarRevisionJuridicaReglas::class)->habilitar($admin, $revisor->getKey(), $v->id_tipo_audiencia, [
            'alcance' => 'Ambito sintetico sin valor profesional.', 'referencia_acreditacion' => 'Evidencia sintetica.',
            'huella_acreditacion' => str_repeat('a', 64), 'acreditacion_verificada' => true, 'fecha_vencimiento' => now()->addDays(3)->toIso8601String(),
        ]);
    }

    private function datosRevision(): array
    {
        return ['decision' => 'aprobado', 'observacion' => 'Revision sintetica sin valor juridico.',
            'fuentes_verificadas' => [['referencia' => 'Fuente sintetica.', 'huella_contenido' => str_repeat('b', 64)]],
            'vigencia_comprobada_en' => now('America/La_Paz')->toDateString(), 'confirmacion_humana' => true, 'fecha_vencimiento' => now()->addDays(2)->toIso8601String()];
    }

    private function revisar($revisor, $v, array $cambios = [], ?string $huella = 'version')
    {
        return app(GestionarRevisionJuridicaReglas::class)->registrar($revisor, $v->getKey(), [...$this->datosRevision(), ...$cambios], null, $huella === 'version' ? $v->huella_contenido : $huella);
    }

    private function datosActivacion($v, $r): array
    {
        return ['motivo' => 'Activacion sintetica.', 'confirmacion_humana' => true, 'huella_version_esperada' => $v->huella_contenido,
            'id_revision_esperada' => $r->getKey(), 'id_activacion_previa' => null];
    }

    private function activar($admin, $v, $r, array $cambios = [])
    {
        return app(GestionarActivacionReglas::class)->activar($admin, $v->getKey(), [...$this->datosActivacion($v, $r), ...$cambios]);
    }

    private function estado($admin, $v): array
    {
        return app(ConsultarActivacionReglas::class)->ejecutar($admin, $v->id_tipo_audiencia);
    }

    private function sqlValido(array $c, int $tipo): bool
    {
        return (bool) DB::selectOne('SELECT jurissim_contrato_ejecucion_valido(?::jsonb, ?) valido', [json_encode($c, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $tipo])->valido;
    }

    private function fila($modelo): array
    {
        $fila = $modelo->getAttributes();
        unset($fila[$modelo->getKeyName()]);

        return $fila;
    }

    private function dominio(): array
    {
        return array_map(fn ($t) => DB::table($t)->get()->toJson(), ['tipos_audiencia', 'etapas_audiencia', 'transiciones_audiencia',
            'propuestas_turnos_audiencia', 'turnos_etapa_audiencia', 'simulaciones', 'intervenciones', 'jobs']);
    }

    private function rechazo(string $clase, callable $accion, ?string $alternativa = null): void
    {
        try {
            $accion();
        } catch (\Throwable $error) {
            $this->assertTrue($error instanceof $clase || ($alternativa !== null && $error instanceof $alternativa), get_class($error).': '.$error->getMessage());

            return;
        }
        $this->fail('Se esperaba rechazo: '.$clase);
    }

    private function rechazoSql(callable $accion): void
    {
        $this->rechazo(QueryException::class, fn () => DB::transaction($accion));
    }
}
