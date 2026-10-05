<?php

namespace Tests\Feature;

use App\Models\EtapaAudiencia;
use App\Models\Expediente;
use App\Models\Intervencion;
use App\Models\MovimientoPracticaReglada;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\Simulacion;
use App\Models\TipoAudiencia;
use App\Models\TransicionAudiencia;
use App\Models\User;
use App\Services\Simulaciones\AvanzarEtapaAudiencia;
use App\Services\Simulaciones\CrearPracticaConReglas;
use App\Services\Simulaciones\GestionarActivacionReglas;
use App\Services\Simulaciones\GestionarRevisionJuridicaReglas;
use App\Services\Simulaciones\MoverPracticaReglada;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MovimientoPracticaRegladaTest extends TestCase
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

    public function test_inicio_interno_registra_movimiento_y_reloj_sin_generar_intervenciones(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $actual = $this->mover($e, $s, 'iniciar');
        $m = MovimientoPracticaReglada::query()->sole();
        $this->assertSame('activa', $actual->estado);
        $this->assertSame($e['stages'][0]->getKey(), $actual->id_etapa_actual);
        $this->assertSame('iniciar', $m->accion);
        $this->assertSame('preparando', $m->estado_origen);
        $this->assertSame('activa', $m->estado_destino);
        $this->assertSame(1, $m->orden);
        $this->assertSame($e['owner']->getKey(), $m->id_usuario_responsable);
        $this->assertNull($m->id_etapa_origen);
        $this->assertTrue($m->fecha_creacion->equalTo($actual->fecha_inicio));
        $this->assertNull($actual->fecha_fin);
        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_inicio_repetido_con_contexto_viejo_no_duplica_ni_avanza(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $actual = $this->mover($e, $s, 'iniciar');
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'iniciar'));
        $this->assertDatabaseCount('movimientos_practicas_regladas', 1);
        $this->assertSame($actual->getAttributes(), $s->fresh()->getAttributes());
    }

    public function test_propietario_ajeno_o_legacy_no_pueden_usar_el_comando(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $this->rechazo(ModelNotFoundException::class, fn () => $this->mover([...$e, 'owner' => $e['admin']], $s, 'iniciar'));
        $legacy = $e['owner']->simulaciones()->create(['id_expediente' => $e['case']->getKey(),
            'id_analisis' => $e['analysis']->getKey(), 'id_tipo_audiencia' => $e['type']->getKey(), 'estado' => 'preparando']);
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $legacy, 'iniciar'));
        $this->assertDatabaseCount('movimientos_practicas_regladas', 0);
    }

    public function test_catalogo_vivo_no_decide_inicio_o_transicion(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $e['stages'][0]->update(['activo' => false, 'es_inicial' => false, 'nombre' => 'Catalogo cambiado.']);
        TransicionAudiencia::query()->where('id_tipo_audiencia', $e['type']->getKey())->update(['activo' => false]);
        $s = $this->mover($e, $s, 'iniciar');
        $this->assertSame($e['stages'][0]->getKey(), $s->id_etapa_actual);
        $this->completarEtapaSintetica($s);
        $s = $this->mover($e, $s, 'avanzar');
        $this->assertSame($e['stages'][1]->getKey(), $s->id_etapa_actual);
        $this->assertDatabaseCount('movimientos_practicas_regladas', 2);
    }

    public function test_avance_y_cierre_preservan_secuencia_y_fechas(): void
    {
        $e = $this->escenario();
        $s = $this->mover($e, $this->crear($e), 'iniciar');
        $inicio = $s->fecha_inicio;
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'avanzar'));
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'finalizar'));
        $this->completarEtapaSintetica($s);
        $s = $this->mover($e, $s, 'avanzar');
        $this->assertSame('activa', $s->estado);
        $this->assertTrue($inicio->equalTo($s->fecha_inicio));
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'finalizar'));
        $this->completarEtapaSintetica($s);
        $s = $this->mover($e, $s, 'finalizar');
        $this->assertSame('finalizada', $s->estado);
        $this->assertTrue($inicio->equalTo($s->fecha_inicio));
        $this->assertTrue($s->fecha_fin->greaterThanOrEqualTo($inicio));
        $this->assertSame(['iniciar', 'avanzar', 'finalizar'], MovimientoPracticaReglada::query()->orderBy('orden')->pluck('accion')->all());
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'avanzar'));
        $this->assertDatabaseCount('movimientos_practicas_regladas', 3);
    }

    public function test_destino_ajeno_y_entrada_incompatible_no_escriben(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'iniciar', $e['stages'][0]->getKey()));
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'inventada'));
        $s = $this->mover($e, $s, 'iniciar');
        $this->completarEtapaSintetica($s);
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'avanzar', 999999));
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'finalizar', $e['stages'][1]->getKey()));
        $this->assertDatabaseCount('movimientos_practicas_regladas', 1);
    }

    public function test_retiro_de_activacion_revalida_incluso_con_modelo_precargado(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        app(GestionarActivacionReglas::class)->retirar($e['admin'], $e['activation']->getKey(), 'Retiro sintetico.');
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'iniciar'));
        $this->assertSame('preparando', $s->fresh()->estado);
        $this->assertDatabaseCount('movimientos_practicas_regladas', 0);
    }

    public function test_revision_nueva_o_habilitacion_retirada_bloquean_avance(): void
    {
        $e = $this->escenario();
        $s = $this->mover($e, $this->crear($e), 'iniciar');
        $this->completarEtapaSintetica($s);
        $this->revisar($e);
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'avanzar'));
        app(GestionarRevisionJuridicaReglas::class)->revocarHabilitacion($e['admin'], $e['grant']->getKey(), 'Retiro sintetico.');
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'avanzar'));
        $this->assertDatabaseCount('movimientos_practicas_regladas', 1);
    }

    public function test_cambio_de_analisis_o_tipo_inactivo_bloquean_movimiento(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $this->aprobarAnalisis($e, 'requiere_cambios');
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'iniciar'));
        $this->aprobarAnalisis($e);
        $e['type']->update(['activo' => false]);
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'iniciar'));
        $this->assertDatabaseCount('movimientos_practicas_regladas', 0);
    }

    public function test_vencimiento_entre_consulta_y_comando_no_autoriza(): void
    {
        $e = $this->escenario(2);
        $s = $this->crear($e);
        $limite = microtime(true) + 5;
        do {
            $estado = DB::selectOne('SELECT jurissim_estado_activacion_practica(?) estado', [$e['activation']->getKey()])->estado;
            if ($estado === 'revision_vencida') {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $limite);
        $this->assertSame('revision_vencida', $estado);
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'iniciar'));
        $this->assertDatabaseCount('movimientos_practicas_regladas', 0);
    }

    public function test_historial_con_rol_equivocado_no_pasa_solo_por_cantidad(): void
    {
        $e = $this->escenario();
        $s = $this->mover($e, $this->crear($e), 'iniciar');
        $this->completarEtapaSintetica($s, 'abogado_defensor');
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'avanzar'));
        $this->assertDatabaseCount('movimientos_practicas_regladas', 1);
    }

    public function test_historial_con_orden_global_inconsistente_bloquea(): void
    {
        $e = $this->escenario();
        $s = $this->mover($e, $this->crear($e), 'iniciar');
        $this->completarEtapaSintetica($s, null, 3);
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'avanzar'));
    }

    public function test_sql_contexto_destino_orden_y_responsable_falsos_se_rechazan(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $l = DB::table('vinculaciones_reglas_practica')->sole();
        $fila = ['id_simulacion' => $s->getKey(), 'id_vinculacion' => $l->id_vinculacion,
            'id_usuario_responsable' => $e['owner']->getKey(), 'orden' => 1, 'accion' => 'iniciar',
            'estado_origen' => 'preparando', 'estado_destino' => 'activa', 'id_etapa_origen' => null,
            'id_etapa_destino' => $e['stages'][0]->getKey()];
        foreach ([['id_vinculacion' => 999999], ['id_usuario_responsable' => $e['admin']->getKey()], ['orden' => 2],
            ['estado_origen' => 'activa'], ['id_etapa_origen' => $e['stages'][0]->getKey()],
            ['id_etapa_destino' => $e['stages'][1]->getKey()], ['estado_destino' => 'finalizada']] as $cambio) {
            $this->rechazoSql(fn () => DB::table('movimientos_practicas_regladas')->insert([...$fila, ...$cambio]));
        }
        $this->assertSame('preparando', $s->fresh()->estado);
        $this->assertDatabaseCount('movimientos_practicas_regladas', 0);
    }

    public function test_sql_movimiento_valido_aplica_estado_atomico_y_fecha_del_servidor(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $l = DB::table('vinculaciones_reglas_practica')->sole();
        DB::table('movimientos_practicas_regladas')->insert(['id_simulacion' => $s->getKey(), 'id_vinculacion' => $l->id_vinculacion,
            'id_usuario_responsable' => $e['owner']->getKey(), 'orden' => 1, 'accion' => 'iniciar', 'estado_origen' => 'preparando',
            'estado_destino' => 'activa', 'id_etapa_origen' => null, 'id_etapa_destino' => $e['stages'][0]->getKey(),
            'fecha_creacion' => '2000-01-01']);
        $this->assertSame('activa', $s->fresh()->estado);
        $this->assertNotSame('2000-01-01', $s->fresh()->fecha_inicio->toDateString());
        $this->assertTrue(MovimientoPracticaReglada::query()->sole()->fecha_creacion->equalTo($s->fresh()->fecha_inicio));
    }

    public function test_estado_directo_y_motor_antiguo_no_eluden_movimientos(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $this->rechazoSql(fn () => app(AvanzarEtapaAudiencia::class)->ejecutar($s));
        $s = $this->mover($e, $s, 'iniciar');
        $this->completarEtapaSintetica($s);
        $this->rechazoSql(fn () => DB::table('simulaciones')->where('id_simulacion', $s->getKey())->update(['id_etapa_actual' => $e['stages'][1]->getKey()]));
        $this->rechazoSql(fn () => app(AvanzarEtapaAudiencia::class)->ejecutar($s));
        $this->assertDatabaseCount('movimientos_practicas_regladas', 1);
    }

    public function test_intervenciones_siguen_bloqueadas_sin_integracion_de_ese_comando(): void
    {
        $e = $this->escenario();
        $s = $this->mover($e, $this->crear($e), 'iniciar');
        $this->rechazoSql(fn () => Intervencion::create(['id_simulacion' => $s->getKey(), 'id_participante_simulacion' => $s->participantes()->first()->getKey(),
            'id_tipo_audiencia' => $s->id_tipo_audiencia, 'id_etapa' => $s->id_etapa_actual, 'orden' => 1, 'contenido' => 'Sintetico.', 'tipo_entrada' => 'texto']));
        $this->assertDatabaseCount('intervenciones', 0);
    }

    public function test_movimientos_no_se_editan_borran_truncan_o_revierten(): void
    {
        $e = $this->escenario();
        $s = $this->mover($e, $this->crear($e), 'iniciar');
        $m = MovimientoPracticaReglada::query()->sole();
        $this->rechazo(LogicException::class, fn () => $m->forceFill(['orden' => 99])->save());
        $this->rechazo(LogicException::class, fn () => $m->delete());
        foreach ([fn () => DB::table('movimientos_practicas_regladas')->update(['orden' => 99]),
            fn () => DB::table('movimientos_practicas_regladas')->delete(),
            fn () => DB::statement('TRUNCATE movimientos_practicas_regladas'),
            fn () => $s->delete()] as $accion) {
            $this->rechazoSql($accion);
        }
        $migracion = require database_path('migrations/2026_10_04_000003_registrar_movimientos_practicas.php');
        $this->rechazo(RuntimeException::class, fn () => $migracion->down());
        $this->assertDatabaseCount('movimientos_practicas_regladas', 1);
    }

    public function test_rollback_vacio_reinstala_y_restauracion_bloquea_comandos_nuevos(): void
    {
        $defensa = require database_path('migrations/2026_10_04_000004_registrar_defensa_reglada.php');
        $defensa->down();
        $m = require database_path('migrations/2026_10_04_000003_registrar_movimientos_practicas.php');
        $m->down();
        $m->up();
        $e = $this->escenario();
        $s = $this->crear($e);
        $m->down();
        $this->rechazoSql(fn () => app(AvanzarEtapaAudiencia::class)->ejecutar($s));
        $m->up();
        $this->assertSame('activa', $this->mover($e, $s, 'iniciar')->estado);
        $defensa->up();
    }

    public function test_segunda_conexion_con_snapshot_obsoleto_es_rechazada(): void
    {
        $conexion = DB::build(config('database.connections.pgsql'));
        $this->assertSame('jurissim_pruebas', $conexion->selectOne('SELECT current_database() nombre')->nombre);
        $conexion->beginTransaction();
        try {
            $conexion->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            try {
                $conexion->selectOne('SELECT jurissim_contexto_practica_bloqueado(1,1)');
                $this->fail('Se esperaba rechazo de aislamiento.');
            } catch (QueryException $error) {
                $this->assertSame('23514', $error->errorInfo[0]);
                $this->assertStringContainsString('Movimiento exige read committed.', $error->errorInfo[2]);
            }
        } finally {
            $conexion->rollBack();
            $conexion->disconnect();
        }
    }

    private function mover(array $e, Simulacion $s, string $accion, ?int $destino = null): Simulacion
    {
        return app(MoverPracticaReglada::class)->ejecutar($e['owner'], $s->getKey(), $accion, $s->estado, $s->id_etapa_actual, $destino);
    }

    public function test_bifurcacion_exige_destino_explicito_y_no_recupera_rama_no_elegida(): void
    {
        $e = $this->escenario(null, true);
        $s = $this->mover($e, $this->crear($e), 'iniciar');
        $this->completarEtapaSintetica($s);
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'avanzar'));
        $s = $this->mover($e, $s, 'avanzar', $e['stages'][2]->getKey());
        $this->assertSame($e['stages'][2]->getKey(), $s->id_etapa_actual);
        $this->completarEtapaSintetica($s);
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'avanzar', $e['stages'][1]->getKey()));
        $s = $this->mover($e, $s, 'avanzar');
        $this->assertSame($e['stages'][3]->getKey(), $s->id_etapa_actual);
        $this->assertDatabaseCount('movimientos_practicas_regladas', 3);
    }

    public function test_habilitacion_retirada_se_reconsulta_antes_de_avanzar(): void
    {
        $e = $this->escenario();
        $s = $this->mover($e, $this->crear($e), 'iniciar');
        $this->completarEtapaSintetica($s);
        app(GestionarRevisionJuridicaReglas::class)->revocarHabilitacion($e['admin'], $e['grant']->getKey(), 'Retiro sintetico.');
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'avanzar'));
        $this->assertDatabaseCount('movimientos_practicas_regladas', 1);
    }

    public function test_activacion_sustituida_no_reasigna_la_practica(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $this->activar($e, $this->revisar($e), $e['activation']->getKey());
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'iniciar'));
        $this->assertSame($e['activation']->getKey(), DB::table('vinculaciones_reglas_practica')->sole()->id_activacion);
        $this->assertDatabaseCount('movimientos_practicas_regladas', 0);
    }

    public function test_historia_en_una_rama_no_visitada_bloquea_aunque_la_etapa_actual_este_completa(): void
    {
        $e = $this->escenario(null, true);
        $s = $this->mover($e, $this->crear($e), 'iniciar');
        $this->completarEtapaSintetica($s);
        $s = $this->mover($e, $s, 'avanzar', $e['stages'][1]->getKey());
        $ajena = clone $s;
        $ajena->id_etapa_actual = $e['stages'][2]->getKey();
        $this->completarEtapaSintetica($ajena);
        $this->completarEtapaSintetica($s);
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'avanzar'));
        $this->assertDatabaseCount('movimientos_practicas_regladas', 2);
    }

    public function test_borrar_un_turno_pasado_no_permite_cerrar_con_historia_incompleta(): void
    {
        $e = $this->escenario();
        $s = $this->mover($e, $this->crear($e), 'iniciar');
        $this->completarEtapaSintetica($s);
        $s = $this->mover($e, $s, 'avanzar');
        $this->completarEtapaSintetica($s);
        // Mutacion SQL sintetica; el cierre debe volver a comprobar todo el recorrido.
        DB::table('intervenciones')->where('id_simulacion', $s->getKey())->where('id_etapa', $e['stages'][0]->getKey())->delete();
        $this->rechazo(DomainException::class, fn () => $this->mover($e, $s, 'finalizar'));
        $this->assertSame('activa', $s->fresh()->estado);
        $this->assertDatabaseCount('movimientos_practicas_regladas', 2);
    }

    private function completarEtapaSintetica(Simulacion $s, ?string $rol = null, ?int $orden = null): void
    {
        // Preparar historia futura SOLO con DDL en jurissim_pruebas. No abre el comando de intervencion.
        $turno = $s->configuracion['turnos_por_etapa'][$s->id_etapa_actual][0];
        $participante = $s->participantes()->where('rol', $rol ?? $turno['rol'])->sole();
        DB::transaction(function () use ($s, $participante, $orden): void {
            DB::statement('ALTER TABLE intervenciones DISABLE TRIGGER impedir_intervencion_vinculada');
            try {
                Intervencion::create(['id_simulacion' => $s->getKey(), 'id_participante_simulacion' => $participante->getKey(),
                    'id_tipo_audiencia' => $s->id_tipo_audiencia, 'id_etapa' => $s->id_etapa_actual,
                    'orden' => $orden ?? (int) $s->intervenciones()->max('orden') + 1, 'contenido' => 'Intervencion sintetica.', 'tipo_entrada' => 'texto']);
            } finally {
                DB::statement('ALTER TABLE intervenciones ENABLE TRIGGER impedir_intervencion_vinculada');
            }
        });
    }

    protected function escenario(?int $segundosRevision = null, bool $ramificada = false): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $type = TipoAudiencia::create(['nombre' => 'Practica sintetica', 'codigo' => 'sintetica', 'activo' => true]);
        $stages = $instructions = [];
        foreach ($ramificada ? [1, 2, 3, 4] : [1, 2] as $orden) {
            $etapa = EtapaAudiencia::create(['id_tipo_audiencia' => $type->getKey(), 'codigo' => 'sintetica-'.$orden,
                'nombre' => 'Etapa sintetica', 'orden' => $orden, 'es_inicial' => $orden === 1, 'es_final' => $orden === ($ramificada ? 4 : 2), 'activo' => true]);
            $stages[] = $etapa;
            $p = new PropuestaTurnoAudiencia(['id_tipo_audiencia' => $type->getKey(), 'id_etapa' => $etapa->getKey(),
                'orden' => 1, 'rol' => $orden === 1 ? 'juez' : 'abogado_defensor', 'acto_propuesto' => 'Acto sintetico', 'descripcion_propuesta' => 'No es una instruccion.']);
            $p->id_usuario_creador = $admin->getKey();
            $p->save();
            $instructions[] = ['id_propuesta_turno' => $p->getKey(), 'texto' => "  Instruccion sintetica {$orden}.\nSin valor juridico.  "];
        }
        foreach ($ramificada ? [[0, 1], [0, 2], [1, 3], [2, 3]] : [[0, 1]] as [$origen, $destino]) {
            TransicionAudiencia::create(['id_tipo_audiencia' => $type->getKey(), 'id_etapa_origen' => $stages[$origen]->getKey(), 'id_etapa_destino' => $stages[$destino]->getKey(), 'activo' => true]);
        }
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

    protected function revisar(array $e, ?int $segundos = null)
    {
        return app(GestionarRevisionJuridicaReglas::class)->registrar($e['reviewer'], $e['version']->getKey(), [
            'decision' => 'aprobado', 'observacion' => 'Revision sintetica sin valor juridico.',
            'fuentes_verificadas' => [['referencia' => 'Fuente sintetica.', 'huella_contenido' => str_repeat('b', 64)]],
            'vigencia_comprobada_en' => now('America/La_Paz')->toDateString(), 'confirmacion_humana' => true,
            'fecha_vencimiento' => ($segundos === null ? now()->addDays(2) : now()->addSeconds($segundos))->toIso8601String(),
        ], null, $e['version']->huella_contenido);
    }

    protected function activar(array $e, $r, ?int $previa = null)
    {
        return app(GestionarActivacionReglas::class)->activar($e['admin'], $e['version']->getKey(), [
            'motivo' => 'Activacion sintetica.', 'confirmacion_humana' => true, 'huella_version_esperada' => $e['version']->huella_contenido,
            'id_revision_esperada' => $r->getKey(), 'id_activacion_previa' => $previa,
        ]);
    }

    protected function aprobarAnalisis(array $e, string $decision = 'aprobado'): void
    {
        $e['analysis']->revisiones()->create(['id_expediente' => $e['case']->getKey(), 'id_usuario' => $e['owner']->getKey(),
            'decision' => $decision, 'observacion' => 'Revision sintetica.']);
    }

    protected function crear(array $e): Simulacion
    {
        return app(CrearPracticaConReglas::class)->ejecutar($e['owner'], $e['case']->getKey(), $e['analysis']->getKey(), $e['type']->getKey(), $e['activation']->getKey());
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
