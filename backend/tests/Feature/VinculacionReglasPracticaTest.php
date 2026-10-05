<?php

namespace Tests\Feature;

use App\Models\EtapaAudiencia;
use App\Models\Expediente;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\Simulacion;
use App\Models\TipoAudiencia;
use App\Models\TransicionAudiencia;
use App\Models\User;
use App\Models\VinculacionReglasPractica;
use App\Services\Simulaciones\AvanzarEtapaAudiencia;
use App\Services\Simulaciones\ConsultarVinculacionPractica;
use App\Services\Simulaciones\CrearPracticaConReglas;
use App\Services\Simulaciones\GestionarActivacionReglas;
use App\Services\Simulaciones\GestionarRevisionJuridicaReglas;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VinculacionReglasPracticaTest extends TestCase
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

    public function test_crea_solo_un_borrador_nuevo_con_copia_exacta_sin_iniciar_ni_llamar_ia(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $l = VinculacionReglasPractica::query()->sole();
        $this->assertSame($e['owner']->getKey(), $l->id_usuario_responsable);
        $this->assertSame($e['activation']->getKey(), $l->id_activacion);
        $this->assertSame($e['version']->getKey(), $l->id_version_reglas);
        $this->assertSame($e['version']->huella_contenido, $l->huella_version);
        $this->assertSame($s->getKey(), $l->id_simulacion);
        $this->assertSame('preparando', $s->estado);
        $this->assertNull($s->id_etapa_actual);
        $this->assertNull($s->fecha_inicio);
        $this->assertNull($s->fecha_fin);
        $turno = $s->configuracion['turnos_por_etapa'][$e['stages'][0]->getKey()][0];
        $this->assertSame($e['instructions'][0]['texto'], $turno['instruccion']);
        $this->assertSame($e['instructions'][0]['id_propuesta_turno'], $turno['id_propuesta_turno']);
        $this->assertSame('juez', $turno['rol']);
        $this->assertSame(['abogado_defensor', 'juez'], $s->participantes()->orderBy('rol')->pluck('rol')->all());
        $this->assertSame('usuario', $s->participantes()->where('rol', 'abogado_defensor')->sole()->controlado_por);
        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_consulta_privada_no_reserva_ni_autoriza_ejecucion(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $antes = $this->dominio();
        $estado = $this->estado($e, $s);
        $this->assertSame('vigente', $estado['binding_status']);
        $this->assertTrue($estado['binding_current']);
        $this->assertTrue($estado['read_only']);
        $this->assertFalse($estado['execution_authorized']);
        $this->assertTrue($estado['requires_command_integration']);
        $this->assertArrayNotHasKey('instrucciones', $estado);
        $this->assertSame($estado, $this->estado($e, $s));
        $this->assertSame($antes, $this->dominio());
        $this->rechazo(ModelNotFoundException::class, fn () => app(ConsultarVinculacionPractica::class)->ejecutar($e['admin'], $s->getKey()));
    }

    public function test_propietario_analisis_y_tipo_se_resuelven_del_servidor(): void
    {
        $e = $this->escenario();
        $otro = Expediente::factory()->create();
        $tipo = TipoAudiencia::create(['nombre' => 'Otra sintetica', 'codigo' => 'otra', 'activo' => true]);
        foreach ([['owner' => $e['admin']], ['case' => $otro], ['analysis' => (object) ['id' => 999999]], ['type' => $tipo]] as $cambio) {
            if (isset($cambio['analysis'])) {
                $this->rechazo(ModelNotFoundException::class, fn () => app(CrearPracticaConReglas::class)->ejecutar($e['owner'], $e['case']->getKey(), 999999, $e['type']->getKey(), $e['activation']->getKey()));
            } else {
                $this->rechazo(ModelNotFoundException::class, fn () => $this->crear([...$e, ...$cambio]));
            }
        }
        $this->assertDatabaseCount('simulaciones', 0);
        $this->assertDatabaseCount('vinculaciones_reglas_practica', 0);
        $this->assertDatabaseCount('participantes_simulacion', 0);
    }

    public function test_analisis_sin_aprobacion_o_no_procesado_rechaza_sin_crear_practica(): void
    {
        $e = $this->escenario();
        $e['analysis']->revisiones()->delete();
        $this->rechazo(ValidationException::class, fn () => $this->crear($e));
        $this->aprobarAnalisis($e);
        $e['analysis']->update(['estado' => 'error']);
        $this->rechazo(ValidationException::class, fn () => $this->crear($e));
        $this->assertDatabaseCount('simulaciones', 0);
    }

    public function test_ultima_revision_del_analisis_invalida_vinculacion_sin_reescribirla(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $this->aprobarAnalisis($e, 'requiere_cambios');
        $this->assertSame('analisis_no_aprobado', $this->estado($e, $s)['binding_status']);
        $this->rechazo(ValidationException::class, fn () => $this->crear($e));
        $this->assertDatabaseCount('vinculaciones_reglas_practica', 1);
        $this->assertSame('preparando', $s->fresh()->estado);
    }

    public function test_retiro_de_activacion_bloquea_creacion_y_diagnostico_conserva_id(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        app(GestionarActivacionReglas::class)->retirar($e['admin'], $e['activation']->getKey(), 'Retiro sintetico.');
        $this->assertSame('retirada', $this->estado($e, $s)['binding_status']);
        $this->assertSame($e['activation']->getKey(), $this->estado($e, $s)['activation_id']);
        $this->rechazo(ValidationException::class, fn () => $this->crear($e));
        $this->assertDatabaseCount('simulaciones', 1);
    }

    public function test_revision_sustituida_no_se_actualiza_automaticamente(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $nuevaRevision = $this->revisar($e);
        $this->assertSame('revision_sustituida', $this->estado($e, $s)['binding_status']);
        $this->rechazo(ValidationException::class, fn () => $this->crear($e));
        $nuevaActivacion = $this->activar($e, $nuevaRevision, $e['activation']->getKey());
        $this->assertSame('activacion_sustituida', $this->estado($e, $s)['binding_status']);
        $this->rechazo(ValidationException::class, fn () => $this->crear($e));
        $otra = $this->crear([...$e, 'activation' => $nuevaActivacion]);
        $this->assertSame($nuevaActivacion->getKey(), $this->estado($e, $otra)['activation_id']);
        $this->assertSame($e['activation']->getKey(), $this->estado($e, $s)['activation_id']);
    }

    public function test_habilitacion_retirada_bloquea_diagnostico_y_creacion(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        app(GestionarRevisionJuridicaReglas::class)->revocarHabilitacion($e['admin'], $e['grant']->getKey(), 'Retiro sintetico.');
        $this->assertSame('habilitacion_retirada', $this->estado($e, $s)['binding_status']);
        $this->rechazo(ValidationException::class, fn () => $this->crear($e));
    }

    public function test_revision_retirada_bloquea_la_activacion_exacta(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        app(GestionarRevisionJuridicaReglas::class)->revocarRevision($e['reviewer'], $e['review']->getKey(), 'Retiro sintetico.');
        $this->assertSame('revision_retirada', $this->estado($e, $s)['binding_status']);
        $this->rechazo(ValidationException::class, fn () => $this->crear($e));
    }

    public function test_reloj_postgresql_reconsulta_vencimiento_sin_worker(): void
    {
        $e = $this->escenario(2);
        $s = $this->crear($e);
        $limite = microtime(true) + 5;
        do {
            $estado = $this->estado($e, $s);
            if ($estado['binding_status'] === 'revision_vencida') {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $limite);
        $this->assertSame('revision_vencida', $estado['binding_status']);
        $this->assertFalse($estado['binding_current']);
        $this->rechazo(ValidationException::class, fn () => $this->crear($e));
    }

    public function test_ediciones_del_catalogo_no_alteran_instrucciones_selladas_y_tipo_inactivo_bloquea(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        PropuestaTurnoAudiencia::query()->where('id_tipo_audiencia', $e['type']->getKey())->update(['descripcion_propuesta' => 'Cambio vivo no revisado.']);
        $e['stages'][0]->update(['nombre' => 'Cambio vivo', 'activo' => false]);
        $otra = $this->crear($e);
        $this->assertSame($s->configuracion, $otra->configuracion);
        $this->assertSame('vigente', $this->estado($e, $s)['binding_status']);
        $e['type']->update(['activo' => false]);
        $this->assertSame('tipo_inactivo', $this->estado($e, $s)['binding_status']);
        $this->rechazo(ValidationException::class, fn () => $this->crear($e));
    }

    public function test_practica_legacy_no_recibe_vinculacion_ni_modificaciones(): void
    {
        $e = $this->escenario();
        $legacy = $this->borrador($e, ['configuracion' => ['legacy' => true]]);
        $antes = $legacy->fresh()->getAttributes();
        $estado = $this->estado($e, $legacy);
        $this->assertSame('sin_vinculacion', $estado['binding_status']);
        $this->assertFalse($estado['execution_authorized']);
        $nuevo = $this->crear($e);
        $this->assertNotSame($legacy->getKey(), $nuevo->getKey());
        $this->assertSame($antes, $legacy->fresh()->getAttributes());
        $this->assertDatabaseCount('vinculaciones_reglas_practica', 1);
    }

    public function test_inmutabilidad_modelo_sql_y_retencion_en_cascadas(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $l = VinculacionReglasPractica::query()->sole();
        $this->rechazo(LogicException::class, fn () => $l->forceFill(['id_usuario_responsable' => $e['admin']->getKey()])->save());
        $this->rechazo(LogicException::class, fn () => $l->delete());
        foreach ([fn () => DB::table('vinculaciones_reglas_practica')->update(['huella_version' => str_repeat('f', 64)]),
            fn () => DB::table('vinculaciones_reglas_practica')->delete(),
            fn () => DB::statement('TRUNCATE vinculaciones_reglas_practica'),
            fn () => $s->delete(), fn () => $e['case']->delete(), fn () => $e['owner']->delete()] as $accion) {
            $this->rechazoSql($accion);
        }
        $this->assertDatabaseCount('vinculaciones_reglas_practica', 1);
        $this->assertNotNull($s->fresh());
    }

    public function test_contexto_y_estado_vinculados_no_se_reasignan_ni_avanzan_con_motor_antiguo(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        foreach ([['configuracion' => '{}'], ['estado' => 'activa'], ['id_etapa_actual' => $e['stages'][0]->getKey()],
            ['rol_usuario' => 'fiscal'], ['fecha_inicio' => now()], ['fecha_creacion' => now()->subDay()]] as $cambio) {
            $this->rechazoSql(fn () => DB::table('simulaciones')->where('id_simulacion', $s->getKey())->update($cambio));
        }
        $this->rechazoSql(fn () => app(AvanzarEtapaAudiencia::class)->ejecutar($s));
        $this->assertSame('preparando', $s->fresh()->estado);
        $this->assertNull($s->fresh()->fecha_inicio);
    }

    public function test_intervencion_sql_no_elude_el_bloqueo_del_hito_interno(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $this->rechazoSql(fn () => DB::table('intervenciones')->insert([
            'id_simulacion' => $s->getKey(), 'id_participante_simulacion' => $s->participantes()->first()->getKey(),
            'id_tipo_audiencia' => $e['type']->getKey(), 'id_etapa' => $e['stages'][0]->getKey(),
            'orden' => 1, 'contenido' => 'Texto sintetico.', 'tipo_entrada' => 'texto',
        ]));
        $this->assertDatabaseCount('intervenciones', 0);
    }

    public function test_sql_rechaza_asociaciones_huella_y_configuracion_falsificadas(): void
    {
        $e = $this->escenario();
        $s = $this->borrador($e);
        $fila = $this->fila($e, $s);
        foreach ([['id_usuario_responsable' => $e['admin']->getKey()], ['huella_version' => str_repeat('0', 64)],
            ['id_activacion' => 999999], ['id_version_reglas' => 999999], ['id_tipo_audiencia' => 999999], ['perfil_validacion' => 'libre']] as $cambio) {
            $this->rechazoSql(fn () => DB::table('vinculaciones_reglas_practica')->insert([...$fila, ...$cambio]));
        }
        $s->update(['configuracion' => ['turnos_por_etapa' => []]]);
        $this->rechazoSql(fn () => DB::table('vinculaciones_reglas_practica')->insert($fila));
        $this->assertDatabaseCount('vinculaciones_reglas_practica', 0);
    }

    public function test_sql_rechaza_practica_ya_iniciada_y_revision_de_analisis_obsoleta(): void
    {
        $e = $this->escenario();
        $s = $this->borrador($e);
        $s->update(['estado' => 'activa', 'fecha_inicio' => now(), 'id_etapa_actual' => $e['stages'][0]->getKey()]);
        $this->rechazoSql(fn () => DB::table('vinculaciones_reglas_practica')->insert($this->fila($e, $s)));
        $otro = $this->borrador($e);
        $this->aprobarAnalisis($e, 'requiere_cambios');
        $this->rechazoSql(fn () => DB::table('vinculaciones_reglas_practica')->insert($this->fila($e, $otro)));
    }

    public function test_alteracion_privilegiada_de_huella_se_detecta_sin_conceder_permiso(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        // DDL privilegiado exclusivamente sobre la base de pruebas.
        DB::transaction(function () use ($e): void {
            DB::statement('ALTER TABLE versiones_reglas_audiencia DISABLE TRIGGER versiones_reglas_inmutables');
            try {
                DB::table('versiones_reglas_audiencia')->where('id_version_reglas', $e['version']->getKey())->update(['huella_contenido' => str_repeat('0', 64)]);
            } finally {
                DB::statement('ALTER TABLE versiones_reglas_audiencia ENABLE TRIGGER versiones_reglas_inmutables');
            }
        });
        $estado = $this->estado($e, $s);
        $this->assertSame('integridad_incompatible', $estado['binding_status']);
        $this->assertFalse($estado['execution_authorized']);
        $this->rechazo(ValidationException::class, fn () => $this->crear($e));
    }

    public function test_rollback_vacio_es_reversible_pero_no_elimina_evidencia(): void
    {
        $movimientos = require database_path('migrations/2026_10_04_000003_registrar_movimientos_practicas.php');
        $defensa = require database_path('migrations/2026_10_04_000004_registrar_defensa_reglada.php');
        $defensa->down();
        $movimientos->down();
        $m = require database_path('migrations/2026_10_04_000002_vincular_practicas_reglas.php');
        $m->down();
        $m->up();
        $movimientos->up();
        $defensa->up();
        $e = $this->escenario();
        $s = $this->crear($e);
        $this->rechazo(RuntimeException::class, fn () => $m->down());
        $this->assertSame('vigente', $this->estado($e, $s)['binding_status']);
        $this->assertDatabaseCount('vinculaciones_reglas_practica', 1);
    }

    public function test_sql_rechaza_aislamiento_obsoleto_antes_de_esperar_filas(): void
    {
        $e = $this->escenario();
        $s = $this->borrador($e);
        $conexion = DB::build(config('database.connections.pgsql'));
        $this->assertSame('jurissim_pruebas', $conexion->selectOne('SELECT current_database() nombre')->nombre);
        $conexion->beginTransaction();
        try {
            $conexion->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            try {
                $conexion->table('vinculaciones_reglas_practica')->insert($this->fila($e, $s));
                $this->fail('Se esperaba rechazo del aislamiento.');
            } catch (QueryException $error) {
                $this->assertSame('23514', $error->errorInfo[0]);
                $this->assertStringContainsString('Vinculacion exige read committed.', $error->errorInfo[2]);
            }
        } finally {
            $conexion->rollBack();
            $conexion->disconnect();
        }
        $this->assertDatabaseCount('vinculaciones_reglas_practica', 0);
    }

    private function escenario(?int $segundosRevision = null): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $type = TipoAudiencia::create(['nombre' => 'Practica sintetica', 'codigo' => 'sintetica', 'activo' => true]);
        $stages = $instructions = [];
        foreach ([1, 2] as $orden) {
            $etapa = EtapaAudiencia::create(['id_tipo_audiencia' => $type->getKey(), 'codigo' => 'sintetica-'.$orden,
                'nombre' => 'Etapa sintetica', 'orden' => $orden, 'es_inicial' => $orden === 1, 'es_final' => $orden === 2, 'activo' => true]);
            $stages[] = $etapa;
            $p = new PropuestaTurnoAudiencia(['id_tipo_audiencia' => $type->getKey(), 'id_etapa' => $etapa->getKey(),
                'orden' => 1, 'rol' => $orden === 1 ? 'juez' : 'abogado_defensor', 'acto_propuesto' => 'Acto sintetico', 'descripcion_propuesta' => 'No es una instruccion.']);
            $p->id_usuario_creador = $admin->getKey();
            $p->save();
            $instructions[] = ['id_propuesta_turno' => $p->getKey(), 'texto' => "  Instruccion sintetica {$orden}.\nSin valor juridico.  "];
        }
        TransicionAudiencia::create(['id_tipo_audiencia' => $type->getKey(), 'id_etapa_origen' => $stages[0]->getKey(), 'id_etapa_destino' => $stages[1]->getKey(), 'activo' => true]);
        $version = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $type->getKey(), 'Version sintetica.', $instructions, true);
        $grant = app(GestionarRevisionJuridicaReglas::class)->habilitar($admin, $reviewer->getKey(), $type->getKey(), [
            'alcance' => 'Ambito sintetico sin valor profesional.', 'referencia_acreditacion' => 'Evidencia sintetica.',
            'huella_acreditacion' => str_repeat('a', 64), 'acreditacion_verificada' => true, 'fecha_vencimiento' => now()->addDays(3)->toIso8601String(),
        ]);
        $case = Expediente::factory()->for($owner, 'usuario')->create();
        $analysis = $case->analisis()->create(['estado' => 'procesado', 'version' => 1]);
        $e = compact('admin', 'owner', 'reviewer', 'type', 'stages', 'instructions', 'version', 'grant', 'case', 'analysis');
        $this->aprobarAnalisis($e);
        $review = $this->revisar($e, $segundosRevision);
        $activation = $this->activar($e, $review);

        return [...$e, ...compact('review', 'activation')];
    }

    private function revisar(array $e, ?int $segundos = null)
    {
        return app(GestionarRevisionJuridicaReglas::class)->registrar($e['reviewer'], $e['version']->getKey(), [
            'decision' => 'aprobado', 'observacion' => 'Revision sintetica sin valor juridico.',
            'fuentes_verificadas' => [['referencia' => 'Fuente sintetica.', 'huella_contenido' => str_repeat('b', 64)]],
            'vigencia_comprobada_en' => now('America/La_Paz')->toDateString(), 'confirmacion_humana' => true,
            'fecha_vencimiento' => ($segundos === null ? now()->addDays(2) : now()->addSeconds($segundos))->toIso8601String(),
        ], null, $e['version']->huella_contenido);
    }

    private function activar(array $e, $r, ?int $previa = null)
    {
        return app(GestionarActivacionReglas::class)->activar($e['admin'], $e['version']->getKey(), [
            'motivo' => 'Activacion sintetica.', 'confirmacion_humana' => true, 'huella_version_esperada' => $e['version']->huella_contenido,
            'id_revision_esperada' => $r->getKey(), 'id_activacion_previa' => $previa,
        ]);
    }

    private function aprobarAnalisis(array $e, string $decision = 'aprobado'): void
    {
        $e['analysis']->revisiones()->create(['id_expediente' => $e['case']->getKey(), 'id_usuario' => $e['owner']->getKey(),
            'decision' => $decision, 'observacion' => 'Revision sintetica.']);
    }

    private function crear(array $e): Simulacion
    {
        return app(CrearPracticaConReglas::class)->ejecutar($e['owner'], $e['case']->getKey(), $e['analysis']->getKey(), $e['type']->getKey(), $e['activation']->getKey());
    }

    private function estado(array $e, Simulacion $s): array
    {
        return app(ConsultarVinculacionPractica::class)->ejecutar($e['owner'], $s->getKey());
    }

    private function borrador(array $e, array $cambios = []): Simulacion
    {
        $json = DB::selectOne('SELECT jurissim_configuracion_practica(contenido) c FROM versiones_reglas_audiencia WHERE id_version_reglas=?', [$e['version']->getKey()])->c;

        return $e['owner']->simulaciones()->create([...[
            'id_expediente' => $e['case']->getKey(), 'id_analisis' => $e['analysis']->getKey(), 'id_tipo_audiencia' => $e['type']->getKey(),
            'estado' => 'preparando', 'configuracion' => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
        ], ...$cambios]);
    }

    private function fila(array $e, Simulacion $s): array
    {
        return ['id_simulacion' => $s->getKey(), 'id_activacion' => $e['activation']->getKey(),
            'id_version_reglas' => $e['version']->getKey(), 'id_tipo_audiencia' => $e['type']->getKey(),
            'id_usuario_responsable' => $e['owner']->getKey(), 'huella_version' => $e['version']->huella_contenido,
            'perfil_validacion' => 'mvp-defensa-aciclico-ejecucion-v1'];
    }

    private function dominio(): array
    {
        return array_map(fn ($t) => DB::table($t)->get()->toJson(), ['simulaciones', 'vinculaciones_reglas_practica', 'participantes_simulacion', 'intervenciones', 'jobs']);
    }

    private function rechazo(string $clase, callable $accion): void
    {
        try {
            $accion();
        } catch (\Throwable $error) {
            $this->assertInstanceOf($clase, $error, $error->getMessage());

            return;
        }
        $this->fail('Se esperaba rechazo: '.$clase);
    }

    private function rechazoSql(callable $accion): void
    {
        $this->rechazo(QueryException::class, fn () => DB::transaction($accion));
    }
}
