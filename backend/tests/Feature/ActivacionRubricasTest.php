<?php

namespace Tests\Feature;

use App\Models\ActivacionRubrica;
use App\Models\Rubrica;
use App\Models\TipoAudiencia;
use App\Models\User;
use App\Modules\Simulations\Application\Contracts\AgenteRegladoGateway;
use App\Services\Evaluaciones\ConsultarRubricaActivada;
use App\Services\Evaluaciones\GestionarActivacionRubricas;
use App\Services\Evaluaciones\GestionarRevisionRubricas;
use App\Services\Simulaciones\ConfirmarAgenteReglado;
use App\Services\Simulaciones\MoverPracticaReglada;
use App\Services\Simulaciones\OrquestadorPracticaReglada;
use App\Services\Simulaciones\ProponerAgenteReglado;
use App\Services\Simulaciones\RegistrarDefensaReglada;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Mockery;
use PDO;
use PDOException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Support\EscenarioPracticaReglada;
use Tests\TestCase;

class ActivacionRubricasTest extends TestCase
{
    use EscenarioPracticaReglada;

    private function datos(?TipoAudiencia $tipo = null): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $autor = User::factory()->create();
        $revisor = User::factory()->create();
        $tipo ??= TipoAudiencia::create(['nombre' => 'Tipo sintético', 'codigo' => 'sintetico-'.TipoAudiencia::count(), 'activo' => true]);
        $rubrica = Rubrica::create(['nombre' => 'Rúbrica sintética '.Rubrica::count(), 'id_tipo_audiencia' => $tipo->getKey(),
            'rol_aplicable' => 'abogado_defensor', 'version' => 1, 'activo' => false]);
        $criterio = $rubrica->criterios()->create(['nombre' => 'Criterio sintético', 'descripcion' => 'Sin valor jurídico.',
            'peso' => '1.2500', 'puntaje_maximo' => '10.0000', 'orden' => 1]);
        $g = app(GestionarRevisionRubricas::class);
        $v = $g->sellar($admin, $rubrica->getKey(), 'Copia sintética.', [$autor->getKey()], true);
        $h = $g->habilitar($admin, $revisor->getKey(), $v->getKey(), ['alcance' => 'Ámbito sintético sin valor profesional.',
            'referencia_acreditacion' => 'Referencia sintética.', 'huella_acreditacion' => str_repeat('a', 64),
            'acreditacion_verificada' => true, 'fecha_vencimiento' => now()->addDays(2)->toIso8601String()]);
        $r = $g->revisar($revisor, $v->getKey(), $h->getKey(), $this->revision($v));

        return compact('admin', 'autor', 'revisor', 'tipo', 'rubrica', 'criterio', 'g', 'v', 'h', 'r');
    }

    private function revision($v, array $extra = []): array
    {
        return ['decision' => 'aprobado', 'observacion' => 'Revisión sintética sin valor jurídico.',
            'huella_version_revisada' => $v->huella_contenido, 'id_revision_previa' => null,
            'confirmacion_humana' => true, 'autoria_revisada' => true,
            'vigencia_comprobada_en' => now('America/La_Paz')->toDateString(),
            'fecha_vencimiento' => now()->addDay()->toIso8601String(),
            'fuentes_verificadas' => [['referencia' => 'Fuente sintética.', 'huella_contenido' => str_repeat('b', 64)]], ...$extra];
    }

    private function activarRubrica(array $e, ?int $previa = null, array $extra = []): ActivacionRubrica
    {
        return app(GestionarActivacionRubricas::class)->activar($e['admin'], $e['v']->getKey(), [
            'motivo' => 'Activación sintética.', 'confirmacion_humana' => true,
            'huella_version_esperada' => $e['v']->huella_contenido, 'id_revision_esperada' => $e['r']->getKey(),
            'id_activacion_previa' => $previa, ...$extra]);
    }

    private function consulta(array $e): ?array
    {
        return app(ConsultarRubricaActivada::class)->ejecutar($e['tipo']->getKey(), 'abogado_defensor');
    }

    private function rechazar(string $clase, \Closure $operacion): void
    {
        try {
            $operacion();
            $this->fail('La operación debía rechazarse.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($clase, $error);
        }
    }

    public function test_activacion_usa_copia_revisada_sin_cambiar_catalogo_ni_ejecutar(): void
    {
        $e = $this->datos();
        $this->assertNull($this->consulta($e));
        $a = $this->activarRubrica($e);
        $d = $this->consulta($e);
        $this->assertSame('vigente', $d['estado_activacion']);
        $this->assertSame('aprobado', $d['estado_revision']);
        $this->assertSame($a->getKey(), $d['rubrica']['id_activacion_rubrica']);
        $this->assertSame($e['v']->getKey(), $d['rubrica']['id_version_rubrica']);
        $e['rubrica']->update(['version' => 99, 'nombre' => 'Catálogo cambiado.', 'rol_aplicable' => 'fiscal']);
        $e['criterio']->update(['descripcion' => str_repeat('X', 2001), 'peso' => 999]);
        $this->assertSame($d, $this->consulta($e));
        $this->assertFalse($e['rubrica']->fresh()->activo);
        $this->assertNull(app(ConsultarRubricaActivada::class)->ejecutar($e['tipo']->getKey(), 'fiscal'));
        $this->assertDatabaseCount('evaluaciones', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_confirmacion_ids_y_campos_son_nativos_y_cerrados(): void
    {
        $e = $this->datos();
        foreach ([['confirmacion_humana' => 'true'], ['id_revision_esperada' => (string) $e['r']->getKey()],
            ['id_activacion_previa' => '1'], ['id_usuario_responsable' => 1], ['motivo' => "\u{00A0}\u{2003}"],
            ['perfil_validacion' => 'otro'], ['huella_version_esperada' => 'mala']] as $extra) {
            $this->rechazar(ValidationException::class, fn () => $this->activarRubrica($e, null, $extra));
        }
        $this->assertDatabaseCount('activaciones_rubricas', 0);
    }

    public function test_admin_actual_es_obligatorio_para_activar_y_retirar(): void
    {
        $e = $this->datos();
        $this->rechazar(DomainException::class, fn () => $this->activarRubrica([...$e, 'admin' => $e['revisor']]));
        $a = $this->activarRubrica($e);
        $this->rechazar(DomainException::class, fn () => app(GestionarActivacionRubricas::class)->retirar($e['revisor'], $a->getKey(), 'Retiro.'));
        $e['admin']->load('roles');
        DB::table('model_has_roles')->where('model_id', $e['admin']->getKey())->delete();
        $this->rechazar(DomainException::class, fn () => app(GestionarActivacionRubricas::class)->retirar($e['admin'], $a->getKey(), 'Retiro.'));
        $this->assertSame('vigente', $this->consulta($e)['estado_activacion']);
    }

    public function test_hash_revision_y_lectura_previa_deben_coincidir(): void
    {
        $e = $this->datos();
        $otra = $this->datos();
        $this->rechazar(DomainException::class, fn () => $this->activarRubrica($e, null, ['huella_version_esperada' => str_repeat('f', 64)]));
        $this->rechazar(DomainException::class, fn () => $this->activarRubrica($e, null, ['id_revision_esperada' => $otra['r']->getKey()]));
        $this->rechazar(DomainException::class, fn () => $this->activarRubrica($e, 9999));
        $this->activarRubrica($e);
        $this->rechazar(DomainException::class, fn () => $this->activarRubrica($e));
        $this->assertDatabaseCount('activaciones_rubricas', 1);
    }

    public function test_sql_rechaza_tuplas_falsas_y_sella_fecha_de_base(): void
    {
        $e = $this->datos();
        $otra = $this->datos();
        $a = $this->activarRubrica($e);
        $fila = $a->getAttributes();
        unset($fila['id_activacion_rubrica']);
        foreach ([['id_tipo_audiencia' => $otra['tipo']->getKey()], ['id_rubrica' => $otra['rubrica']->getKey()],
            ['id_habilitacion_rubrica' => $otra['h']->getKey()], ['perfil_validacion' => 'otro'],
            ['confirmacion_humana' => false], ['id_usuario_responsable' => $e['autor']->getKey()]] as $extra) {
            $this->rechazar(QueryException::class, fn () => DB::transaction(fn () => DB::table('activaciones_rubricas')->insert([...$fila,
                'id_activacion_previa' => $a->getKey(), ...$extra])));
        }
        $r = $e['g']->revisar($e['revisor'], $e['v']->getKey(), $e['h']->getKey(), $this->revision($e['v'], ['id_revision_previa' => $e['r']->getKey()]));
        DB::table('activaciones_rubricas')->insert([...$fila, 'id_revision_rubrica' => $r->getKey(), 'id_activacion_previa' => $a->getKey(),
            'fecha_creacion' => '2000-01-01T00:00:00Z']);
        $nueva = ActivacionRubrica::query()->orderByDesc('id_activacion_rubrica')->firstOrFail();
        $this->assertTrue($nueva->fecha_creacion->greaterThan(now()->subMinute()));
        $this->assertSame('sustituida', DB::selectOne('SELECT jurissim_estado_activacion_rubrica(?) estado', [$a->getKey()])->estado);
    }

    public function test_eleccion_explicita_serializa_tipo_y_retiro_no_recupera_anterior(): void
    {
        $e = $this->datos();
        $otra = $this->datos($e['tipo']);
        $a = $this->activarRubrica($e);
        $this->rechazar(DomainException::class, fn () => $this->activarRubrica($otra));
        $b = $this->activarRubrica($otra, $a->getKey());
        $this->assertSame($otra['v']->getKey(), $this->consulta($e)['rubrica']['id_version_rubrica']);
        app(GestionarActivacionRubricas::class)->retirar($e['admin'], $b->getKey(), 'Retiro sintético.');
        $this->assertSame('retirada', $this->consulta($e)['estado_activacion']);
        $this->assertNull($this->consulta($e)['rubrica']);
        $this->rechazar(DomainException::class, fn () => $this->activarRubrica($e, $b->getKey()));
        $this->rechazar(DomainException::class, fn () => app(GestionarActivacionRubricas::class)->retirar($e['admin'], $b->getKey(), 'Duplicado.'));
    }

    public function test_nueva_revision_exige_nueva_activacion_incluso_si_aprueba(): void
    {
        $e = $this->datos();
        $a = $this->activarRubrica($e);
        $r = $e['g']->revisar($e['revisor'], $e['v']->getKey(), $e['h']->getKey(), $this->revision($e['v'], ['id_revision_previa' => $e['r']->getKey()]));
        $this->assertSame('revision_sustituida', $this->consulta($e)['estado_activacion']);
        $this->assertNull($this->consulta($e)['rubrica']);
        $this->rechazar(DomainException::class, fn () => $this->activarRubrica($e, $a->getKey()));
        $b = $this->activarRubrica([...$e, 'r' => $r], $a->getKey());
        $this->assertSame($b->getKey(), $this->consulta($e)['rubrica']['id_activacion_rubrica']);
        $cambios = $e['g']->revisar($e['revisor'], $e['v']->getKey(), $e['h']->getKey(), $this->revision($e['v'],
            ['id_revision_previa' => $r->getKey(), 'decision' => 'requiere_cambios', 'fecha_vencimiento' => null]));
        $this->assertSame('requiere_cambios', $this->consulta($e)['estado_revision']);
        $e['g']->retirarRevision($e['revisor'], $cambios->getKey(), 'Retiro sintético.');
        $this->assertNull($this->consulta($e)['rubrica']);
        $this->rechazar(DomainException::class, fn () => $this->activarRubrica([...$e, 'r' => $cambios], $b->getKey()));
    }

    public function test_retiros_profesionales_y_tipo_inactivo_bloquean_sin_tocar_historial(): void
    {
        foreach (['revision', 'habilitacion', 'tipo'] as $accion) {
            $e = $this->datos();
            $this->activarRubrica($e);
            if ($accion === 'revision') {
                $e['g']->retirarRevision($e['revisor'], $e['r']->getKey(), 'Retiro sintético.');
            } elseif ($accion === 'habilitacion') {
                $e['g']->retirarHabilitacion($e['admin'], $e['h']->getKey(), 'Retiro sintético.');
            } else {
                $e['tipo']->update(['activo' => false]);
            }
            $this->assertSame($accion === 'tipo' ? 'tipo_inactivo' : 'revision_no_vigente', $this->consulta($e)['estado_activacion']);
            $this->assertNull($this->consulta($e)['rubrica']);
        }
        $this->assertDatabaseCount('activaciones_rubricas', 3);
    }

    public function test_vencimiento_real_no_autoriza_copia_activada(): void
    {
        $e = $this->datos();
        $fecha = CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp()+interval \'3 seconds\' fecha')->fecha)->toIso8601String();
        $r = $e['g']->revisar($e['revisor'], $e['v']->getKey(), $e['h']->getKey(), $this->revision($e['v'],
            ['id_revision_previa' => $e['r']->getKey(), 'fecha_vencimiento' => $fecha]));
        $this->activarRubrica([...$e, 'r' => $r]);
        $this->assertSame('vigente', $this->consulta($e)['estado_activacion']);
        DB::selectOne('SELECT pg_sleep(4)');
        $this->assertSame('revision_vencida', $this->consulta($e)['estado_revision']);
        $this->assertNull($this->consulta($e)['rubrica']);
    }

    public function test_concurrencia_bloquea_tipo_antes_de_elegir_otra_rubrica(): void
    {
        $e = $this->datos();
        $otra = $this->datos($e['tipo']);
        $c = config('database.connections.pgsql');
        $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname=jurissim_pruebas", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('jurissim_pruebas', $pdo->query('SELECT current_database()')->fetchColumn());
        $pdo->exec("SET lock_timeout='75ms'");
        DB::transaction(function () use ($e, $otra, $pdo): void {
            DB::selectOne('SELECT id_tipo_audiencia FROM tipos_audiencia WHERE id_tipo_audiencia=? FOR NO KEY UPDATE', [$e['tipo']->getKey()]);
            $pdo->beginTransaction();
            try {
                $q = $pdo->prepare("INSERT INTO activaciones_rubricas (id_tipo_audiencia,id_rubrica,id_version_rubrica,id_revision_rubrica,id_habilitacion_rubrica,id_usuario_responsable,huella_version,perfil_validacion,motivo,confirmacion_humana,id_activacion_previa) VALUES (?,?,?,?,?,?,?,'mvp-evaluacion-defensa-v1','Activación sintética.',true,NULL)");
                $q->execute([$otra['tipo']->getKey(), $otra['rubrica']->getKey(), $otra['v']->getKey(), $otra['r']->getKey(),
                    $otra['h']->getKey(), $otra['admin']->getKey(), $otra['v']->huella_contenido]);
                $this->fail('La segunda elección debe esperar.');
            } catch (PDOException $error) {
                $this->assertSame('55P03', $error->errorInfo[0]);
            } finally {
                $pdo->rollBack();
            }
            $this->activarRubrica($e);
        });
        $this->rechazar(DomainException::class, fn () => $this->activarRubrica($otra));
        $this->assertDatabaseCount('activaciones_rubricas', 1);
    }

    public function test_sql_modelos_y_rollback_no_borran_historial(): void
    {
        $e = $this->datos();
        $a = $this->activarRubrica($e);
        $retiro = app(GestionarActivacionRubricas::class)->retirar($e['admin'], $a->getKey(), 'Retiro sintético.');
        foreach ([$a, $retiro] as $registro) {
            $registro->motivo = 'Cambio no permitido.';
            $this->rechazar(LogicException::class, fn () => $registro->save());
            $this->rechazar(LogicException::class, fn () => $registro->delete());
            foreach (['update', 'delete', 'truncate'] as $accion) {
                $this->rechazar(QueryException::class, fn () => DB::transaction(function () use ($registro, $accion): void {
                    if ($accion === 'truncate') {
                        DB::statement('TRUNCATE '.$registro->getTable().' CASCADE');
                    } elseif ($accion === 'delete') {
                        DB::table($registro->getTable())->delete();
                    } else {
                        DB::table($registro->getTable())->update(['motivo' => 'Cambio.']);
                    }
                }));
            }
            $this->assertDatabaseCount($registro->getTable(), 1);
        }
        $m = require database_path('migrations/2026_10_06_000002_activar_copias_rubricas.php');
        $this->rechazar(RuntimeException::class, fn () => $m->down());
        $this->assertDatabaseCount('activaciones_rubricas', 1);
        $this->assertDatabaseCount('retiros_activaciones_rubricas', 1);
    }

    public function test_rollback_vacio_preserva_revision_y_permite_reaplicar(): void
    {
        $m = require database_path('migrations/2026_10_06_000002_activar_copias_rubricas.php');
        $m->down();
        $this->assertFalse(Schema::hasTable('activaciones_rubricas'));
        $this->assertTrue(Schema::hasTable('versiones_rubricas_evaluacion'));
        $m->up();
        $e = $this->datos();
        $this->activarRubrica($e);
        $this->assertSame('vigente', $this->consulta($e)['estado_activacion']);
    }

    private function cerrarPractica(array $e): int
    {
        $g = Mockery::mock(AgenteRegladoGateway::class);
        $g->shouldReceive('proveedor')->twice()->andReturn(['provider' => 'ollama', 'model' => 'sintetico', 'requires_external_authorization' => false]);
        $g->shouldReceive('proponer')->twice()->andReturnUsing(fn ($ctx) => ['speaker_role' => $ctx['actor_role'],
            'content' => 'Texto sintético privado.', 'used_source_ids' => [], 'requires_human_review' => true,
            '_meta' => ['provider' => 'ollama', 'model' => 'sintetico']]);
        $this->app->instance(AgenteRegladoGateway::class, $g);
        $s = $this->crear($e);
        $m = app(MoverPracticaReglada::class);
        $s = $m->ejecutar($e['owner'], $s->getKey(), 'iniciar', 'preparando', null);
        foreach (['juez', 'fiscal'] as $rol) {
            $c = app(OrquestadorPracticaReglada::class)->ejecutar($e['owner'], $s->getKey())['expected_context'];
            $b = app(ProponerAgenteReglado::class)->ejecutar($e['owner'], $s->getKey(), $c['id_movimiento_esperado'],
                $c['id_etapa_esperada'], $c['id_turno_esperado'], $c['orden_esperado']);
            $this->assertSame($rol, $b->respuesta['speaker_role']);
            app(ConfirmarAgenteReglado::class)->ejecutar($e['owner'], $s->getKey(), $b->getKey(), true);
            $s = $m->ejecutar($e['owner'], $s->getKey(), 'avanzar', 'activa', $s->id_etapa_actual);
        }
        $c = app(OrquestadorPracticaReglada::class)->ejecutar($e['owner'], $s->getKey())['expected_context'];
        app(RegistrarDefensaReglada::class)->ejecutar($e['owner'], $s->getKey(), $c['id_movimiento_esperado'],
            $c['id_etapa_esperada'], $c['id_turno_esperado'], $c['orden_esperado'], 'Defensa sintética privada.', []);
        Queue::fake();

        return $m->ejecutar($e['owner'], $s->getKey(), 'finalizar', 'activa', $s->id_etapa_actual)->getKey();
    }

    public function test_consulta_privada_identifica_copia_pero_no_autoriza_generacion(): void
    {
        $practica = $this->escenario();
        $id = $this->cerrarPractica($practica);
        $e = $this->datos($practica['type']);
        $a = $this->activarRubrica($e);
        Sanctum::actingAs($practica['owner']);
        $ruta = "/api/v1/practicas-regladas/{$id}/preparacion-evaluacion";
        $r = $this->getJson($ruta)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $d = $r->json('data');
        $this->assertTrue($d['technical_ready']);
        $this->assertSame('aprobado', $d['rubric_review_status']);
        $this->assertSame('vigente', $d['rubric_activation_status']);
        $this->assertSame($a->getKey(), $d['rubric']['id_activacion_rubrica']);
        $this->assertFalse($d['execution_authorized']);
        $this->assertTrue($d['read_only']);
        $this->assertSame([], $d['allowed_actions']);
        $this->assertSame(['contexto_inmutable_no_integrado', 'ejecucion_no_integrada'], $d['blocking_reasons']);
        foreach (['Defensa sintética privada.', 'Texto sintético privado.', 'Sin valor jurídico.', 'huella', 'acreditacion', 'id_usuario'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $r->getContent());
        }
        $this->getJson($ruta.'?id_version_rubrica='.$e['v']->getKey())->assertUnprocessable();
        $this->postJson('/api/v1/simulaciones/'.$id.'/evaluacion')->assertUnprocessable();
        app(GestionarActivacionRubricas::class)->retirar($e['admin'], $a->getKey(), 'Retiro sintético.');
        $e['rubrica']->update(['activo' => true]);
        $d = $this->getJson($ruta)->assertOk()->json('data');
        $this->assertSame('retirada', $d['rubric_activation_status']);
        $this->assertNull($d['rubric']);
        $this->assertFalse($d['technical_ready']);
        $this->assertContains('activacion_rubrica_no_vigente', $d['blocking_reasons']);
        Sanctum::actingAs($e['admin']);
        $this->getJson($ruta)->assertNotFound();
        $this->assertDatabaseCount('evaluaciones', 0);
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
