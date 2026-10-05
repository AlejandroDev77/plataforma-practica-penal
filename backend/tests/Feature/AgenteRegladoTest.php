<?php

namespace Tests\Feature;

use App\Integrations\Intelligence\FastApiAgenteRegladoGateway;
use App\Models\BorradorAgenteReglado;
use App\Models\EtapaAudiencia;
use App\Models\Expediente;
use App\Models\FuenteJuridica;
use App\Models\Intervencion;
use App\Models\MovimientoPracticaReglada;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\RegistroIntervencionReglada;
use App\Models\Simulacion;
use App\Models\TipoAudiencia;
use App\Models\TransicionAudiencia;
use App\Models\User;
use App\Modules\Simulations\Application\Contracts\AgenteRegladoGateway;
use App\Services\Simulaciones\ConfirmarAgenteReglado;
use App\Services\Simulaciones\CrearPracticaConReglas;
use App\Services\Simulaciones\GestionarActivacionReglas;
use App\Services\Simulaciones\GestionarRevisionJuridicaReglas;
use App\Services\Simulaciones\MoverPracticaReglada;
use App\Services\Simulaciones\PrepararAgenteReglado;
use App\Services\Simulaciones\ProponerAgenteReglado;
use App\Services\Simulaciones\RegistrarDefensaReglada;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AgenteRegladoTest extends TestCase
{
    private bool $baseVerificada = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comprobarBase();
        $this->baseVerificada = true;
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
        RefreshDatabaseState::$migrated = true;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->baseVerificada) {
                $this->comprobarBase();
                Artisan::call('db:wipe', ['--force' => true, '--no-interaction' => true]);
                RefreshDatabaseState::$migrated = false;
            }
        } finally {
            parent::tearDown();
        }
    }

    private function comprobarBase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'jurissim_pruebas'
            || config('database.connections.pgsql.url')
            || DB::selectOne('SELECT current_database() nombre')->nombre !== 'jurissim_pruebas') {
            throw new RuntimeException('Estas pruebas requieren PostgreSQL jurissim_pruebas, sin DB_URL ni cache.');
        }
        Http::preventStrayRequests();
    }

    public function test_borrador_no_consume_turno_y_confirmacion_conserva_fuentes_autor_y_modelo(): void
    {
        [$e, $s] = $this->activa();
        $f = $this->fuente($e);
        $b = $this->proponer($e, $s, [$f->getKey()]);
        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertDatabaseCount('registros_intervenciones_regladas', 0);
        $this->assertSame('juez', $b->respuesta['speaker_role']);
        $this->assertSame([$f->getKey()], $b->respuesta['used_source_ids']);
        $this->assertTrue($b->fecha_vencimiento->equalTo($b->fecha_creacion->addMinutes(10)));
        $i = $this->confirmar($e, $s, $b);
        $r = RegistroIntervencionReglada::query()->sole();
        $this->assertSame(1, $i->orden);
        $this->assertSame('modelo-sintetico', $i->modelo_ia);
        $this->assertSame($e['owner']->getKey(), $i->metadatos['id_usuario_responsable']);
        $this->assertSame($b->getKey(), $i->metadatos['id_borrador']);
        $this->assertTrue($i->metadatos['confirmacion_humana']);
        $this->assertTrue($r->fecha_creacion->equalTo($i->fecha_intervencion));
        $this->assertSame($f->contenido, $i->fuentes()->sole()->fragmento_utilizado);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_recorrido_juez_fiscal_defensa_y_cierre_sin_desactivar_triggers(): void
    {
        [$e, $s] = $this->activa();
        foreach (['juez', 'fiscal'] as $rol) {
            $b = $this->proponer($e, $s);
            $this->assertSame($rol, $b->respuesta['speaker_role']);
            $this->confirmar($e, $s, $b);
            $s = app(MoverPracticaReglada::class)->ejecutar($e['owner'], $s->getKey(), 'avanzar', 'activa', $s->id_etapa_actual);
        }
        $x = $this->entrada($s);
        app(RegistrarDefensaReglada::class)->ejecutar($e['owner'], $s->getKey(), $x['movimiento'], $x['etapa'], $x['turno'], $x['orden'], 'Defensa sintetica.');
        $final = app(MoverPracticaReglada::class)->ejecutar($e['owner'], $s->getKey(), 'finalizar', 'activa', $s->id_etapa_actual);
        $this->assertSame('finalizada', $final->estado);
        $this->assertSame(['juez', 'fiscal', 'abogado_defensor'], $s->intervenciones()->with('participante')->orderBy('orden')->get()->map(fn ($i) => $i->participante->rol)->all());
        $this->assertDatabaseCount('registros_intervenciones_regladas', 3);
        $this->assertDatabaseCount('borradores_agentes_reglados', 2);
    }

    public function test_confirmacion_exige_revision_explicita_y_repetir_no_duplica(): void
    {
        [$e, $s] = $this->activa();
        $b = $this->proponer($e, $s);
        $this->rechazo(DomainException::class, fn () => $this->confirmar($e, $s, $b, false));
        $primera = $this->confirmar($e, $s, $b);
        $segunda = $this->confirmar($e, $s, $b);
        $this->assertSame($primera->getKey(), $segunda->getKey());
        $this->assertDatabaseCount('intervenciones', 1);
        $this->assertDatabaseCount('registros_intervenciones_regladas', 1);
    }

    public function test_contexto_externo_solo_contiene_seleccion_y_no_hechos_historial_o_rutas(): void
    {
        [$e, $s] = $this->activa();
        $e['analysis']->update(['datos_estructurados' => ['secreto' => 'NO_ENVIAR_ANALISIS']]);
        $f = $this->fuente($e);
        $gateway = Mockery::mock(AgenteRegladoGateway::class);
        $gateway->shouldReceive('proveedor')->once()->andReturn(['provider' => 'openai', 'model' => 'modelo-sintetico', 'requires_external_authorization' => true]);
        $gateway->shouldReceive('proponer')->once()->withArgs(function ($ctx) use ($f): bool {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue($ctx['allow_external_processing']);
            $this->assertSame([], $ctx['visible_facts']);
            $this->assertSame([], $ctx['transcript']);
            $this->assertSame($f->getKey(), $ctx['sources'][0]['id']);
            $this->assertStringNotContainsString('NO_ENVIAR_ANALISIS', json_encode($ctx));
            $this->assertStringNotContainsString('ruta_almacenamiento', json_encode($ctx));

            return true;
        })->andReturn($this->respuesta('juez', [$f->getKey()], 'openai'));
        $this->app->instance(AgenteRegladoGateway::class, $gateway);
        $b = $this->proponer($e, $s, [$f->getKey()], externo: true, prepararGateway: false);
        $this->assertTrue($b->autorizar_externo);
    }

    public function test_openai_sin_autorizacion_no_recibe_contexto(): void
    {
        [$e, $s] = $this->activa();
        $gateway = Mockery::mock(AgenteRegladoGateway::class);
        $gateway->shouldReceive('proveedor')->once()->andReturn(['provider' => 'openai', 'model' => 'modelo-sintetico', 'requires_external_authorization' => true]);
        $gateway->shouldNotReceive('proponer');
        $this->app->instance(AgenteRegladoGateway::class, $gateway);
        $this->rechazo(DomainException::class, fn () => $this->proponer($e, $s, prepararGateway: false));
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
    }

    public function test_propietario_ajeno_no_prepara_ni_confirma_borradores(): void
    {
        [$e, $s] = $this->activa();
        $this->rechazo(ModelNotFoundException::class, fn () => $this->proponer([...$e, 'owner' => $e['admin']], $s));
        $b = $this->proponer($e, $s);
        $this->rechazo(ModelNotFoundException::class, fn () => $this->confirmar([...$e, 'owner' => $e['admin']], $s, $b));
        $otro = $this->crear($e);
        $this->rechazo(ModelNotFoundException::class, fn () => $this->confirmar($e, $otro, $b));
        $this->assertDatabaseCount('intervenciones', 0);
    }

    public function test_precondiciones_obsoletas_no_invocan_proveedor(): void
    {
        [$e, $s] = $this->activa();
        $gateway = Mockery::mock(AgenteRegladoGateway::class);
        $gateway->shouldNotReceive('proveedor');
        $gateway->shouldNotReceive('proponer');
        $this->app->instance(AgenteRegladoGateway::class, $gateway);
        foreach ([['movimiento' => 9999], ['etapa' => $e['stages'][1]->getKey()], ['turno' => 9999], ['orden' => 2]] as $cambio) {
            $this->rechazo(DomainException::class, fn () => $this->proponer($e, $s, cambio: $cambio, prepararGateway: false));
        }
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
    }

    public function test_fuente_ajena_o_seleccion_invalida_no_invocan_proveedor(): void
    {
        [$e, $s] = $this->activa();
        $case = Expediente::factory()->for($e['owner'], 'usuario')->create();
        $f = $this->fuente([...$e, 'case' => $case]);
        $gateway = Mockery::mock(AgenteRegladoGateway::class);
        $gateway->shouldNotReceive('proveedor');
        $gateway->shouldNotReceive('proponer');
        $this->app->instance(AgenteRegladoGateway::class, $gateway);
        foreach ([[$f->getKey()], [9999], [1, 1], ['1'], range(1, 9)] as $ids) {
            $this->rechazo(DomainException::class, fn () => $this->proponer($e, $s, $ids, prepararGateway: false));
        }
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
    }

    public function test_cambio_de_fuente_durante_generacion_no_guarda_borrador(): void
    {
        [$e, $s] = $this->activa();
        $f = $this->fuente($e);
        $this->gateway('juez', [$f->getKey()], function () use ($f): void {
            $this->assertSame(0, DB::transactionLevel());
            $f->pagina->update(['texto_extraido' => $f->pagina->texto_extraido.' Cambio posterior.']);
        });
        $this->rechazo(DomainException::class, fn () => $this->proponer($e, $s, [$f->getKey()], prepararGateway: false));
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
        $this->assertDatabaseCount('intervenciones', 0);
    }

    public function test_cambio_de_revision_durante_generacion_no_guarda_borrador(): void
    {
        [$e, $s] = $this->activa();
        $this->gateway('juez', [], fn () => $this->aprobarAnalisis($e));
        $this->rechazo(DomainException::class, fn () => $this->proponer($e, $s, prepararGateway: false));
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
    }

    public function test_retiro_de_reglas_impide_confirmacion_aunque_borrador_siga_abierto(): void
    {
        [$e, $s] = $this->activa();
        $b = $this->proponer($e, $s);
        app(GestionarActivacionReglas::class)->retirar($e['admin'], $e['activation']->getKey(), 'Retiro sintetico.');
        $this->rechazo(DomainException::class, fn () => $this->confirmar($e, $s, $b));
        $this->assertDatabaseCount('intervenciones', 0);
    }

    public function test_fuente_cambiada_no_pasa_confirmacion_y_borrador_original_se_conserva(): void
    {
        [$e, $s] = $this->activa();
        $f = $this->fuente($e);
        $b = $this->proponer($e, $s, [$f->getKey()]);
        $original = $b->preparacion;
        $f->pagina->update(['texto_extraido' => $f->pagina->texto_extraido.' Otra version.']);
        $this->rechazo(DomainException::class, fn () => $this->confirmar($e, $s, $b));
        $this->assertSame($original, $b->fresh()->preparacion);
        $this->assertDatabaseCount('registros_intervenciones_regladas', 0);
    }

    public function test_participante_ambiguo_inactivo_o_modificado_no_autoriza(): void
    {
        [$e, $s] = $this->activa();
        $b = $this->proponer($e, $s);
        $p = $s->participantes()->where('rol', 'juez')->sole();
        $p->update(['estado' => 'inactivo']);
        $this->rechazo(DomainException::class, fn () => $this->confirmar($e, $s, $b));
        $p->update(['estado' => 'activo', 'nombre_mostrado' => 'Otro nombre.']);
        $this->rechazo(DomainException::class, fn () => $this->confirmar($e, $s, $b));
        $p->replicate()->save();
        $this->rechazo(DomainException::class, fn () => $this->proponer($e, $s));
    }

    public function test_respuesta_fuera_de_contrato_no_se_guarda(): void
    {
        [$e, $s] = $this->activa();
        foreach ([['speaker_role' => 'fiscal'], ['content' => ' '], ['content' => str_repeat('a', 2001)],
            ['requires_human_review' => false], ['used_source_ids' => [99999]], ['used_source_ids' => ['1']],
            ['used_source_ids' => [1, 1]], ['used_source_ids' => new \stdClass], ['extra' => 'No permitido']] as $cambio) {
            $this->gateway('juez', [], respuesta: [...$this->respuesta('juez'), ...$cambio]);
            $this->rechazo(DomainException::class, fn () => $this->proponer($e, $s, prepararGateway: false));
        }
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
        $this->assertDatabaseCount('intervenciones', 0);
    }

    public function test_dos_borradores_del_mismo_turno_solo_permiten_una_confirmacion(): void
    {
        [$e, $s] = $this->activa();
        $a = $this->proponer($e, $s);
        $b = $this->proponer($e, $s);
        $this->confirmar($e, $s, $a);
        $this->rechazo(DomainException::class, fn () => $this->confirmar($e, $s, $b));
        $this->assertDatabaseCount('borradores_agentes_reglados', 2);
        $this->assertDatabaseCount('intervenciones', 1);
    }

    public function test_borrador_vencido_no_se_confirma_con_reloj_php_modificado(): void
    {
        [$e, $s] = $this->activa();
        $b = $this->proponer($e, $s);
        // DDL y fechas sinteticas solo en jurissim_pruebas; no ofrece una ruta de edicion en produccion.
        DB::transaction(function () use ($b): void {
            DB::statement('ALTER TABLE borradores_agentes_reglados DISABLE TRIGGER borrador_agente_inmutable');
            try {
                DB::table('borradores_agentes_reglados')->where('id_borrador', $b->getKey())->update([
                    'fecha_creacion' => now()->subMinutes(20), 'fecha_vencimiento' => now()->subMinutes(10)]);
            } finally {
                DB::statement('ALTER TABLE borradores_agentes_reglados ENABLE TRIGGER borrador_agente_inmutable');
            }
        });
        $this->travel(-1)->days();
        try {
            $this->rechazo(DomainException::class, fn () => $this->confirmar($e, $s, $b));
        } finally {
            $this->travelBack();
        }
        $this->assertDatabaseCount('intervenciones', 0);
    }

    public function test_sql_confirmacion_sin_revision_o_con_contexto_falso_no_pasa(): void
    {
        [$e, $s] = $this->activa();
        $b = $this->proponer($e, $s);
        $entrada = ['id_simulacion' => $s->getKey(), 'id_usuario_responsable' => $e['owner']->getKey(), 'id_borrador' => $b->getKey(),
            'id_movimiento' => $b->id_movimiento, 'id_etapa' => $b->id_etapa, 'id_propuesta_turno' => $b->id_propuesta_turno, 'orden' => $b->orden,
            'confirmacion_humana' => true];
        foreach ([['confirmacion_humana' => false], ['id_usuario_responsable' => $e['admin']->getKey()],
            ['id_movimiento' => 9999], ['id_etapa' => 9999], ['orden' => 2]] as $cambio) {
            $this->rechazo(QueryException::class, fn () => DB::transaction(fn () => DB::table('registros_intervenciones_regladas')->insert([...$entrada, ...$cambio])));
        }
        DB::table('registros_intervenciones_regladas')->insert([...$entrada, 'contenido' => 'Texto falso del navegador',
            'id_participante_simulacion' => 9999, 'ids_fragmento' => '[9999]', 'fuentes' => '[]', 'fecha_creacion' => '2000-01-01']);
        $i = Intervencion::query()->sole();
        $this->assertSame($b->respuesta['content'], $i->contenido);
        $this->assertNotSame('2000-01-01', $i->fecha_intervencion->toDateString());
    }

    public function test_borradores_y_acta_auditada_no_se_editan_eliminan_o_revierten(): void
    {
        [$e, $s] = $this->activa();
        $f = $this->fuente($e);
        $b = $this->proponer($e, $s, [$f->getKey()]);
        $i = $this->confirmar($e, $s, $b);
        $this->rechazo(LogicException::class, fn () => $b->delete());
        $this->rechazo(LogicException::class, fn () => $b->forceFill(['modelo_ia' => 'otro'])->save());
        foreach ([fn () => DB::table('borradores_agentes_reglados')->update(['modelo_ia' => 'otro']),
            fn () => DB::table('borradores_agentes_reglados')->delete(),
            fn () => DB::statement('TRUNCATE borradores_agentes_reglados CASCADE'),
            fn () => $i->delete(), fn () => $i->update(['modelo_ia' => 'otro']),
            fn () => DB::table('fuentes_intervencion')->delete(),
            fn () => $s->participantes()->where('rol', 'juez')->update(['controlado_por' => 'usuario']),
            fn () => $s->delete()] as $accion) {
            $this->rechazo(QueryException::class, fn () => DB::transaction($accion));
        }
        $m = require database_path('migrations/2026_10_04_000005_confirmar_agentes_reglados.php');
        $this->rechazo(RuntimeException::class, fn () => $m->down());
        $this->assertDatabaseCount('borradores_agentes_reglados', 1);
        $this->assertDatabaseCount('intervenciones', 1);
    }

    public function test_rollback_tampoco_borra_borrador_aun_sin_confirmar(): void
    {
        [$e, $s] = $this->activa();
        $this->proponer($e, $s);
        $m = require database_path('migrations/2026_10_04_000005_confirmar_agentes_reglados.php');
        $this->rechazo(RuntimeException::class, fn () => $m->down());
        $this->assertDatabaseCount('registros_intervenciones_regladas', 0);
    }

    public function test_rollback_vacio_reinstala_y_cierra_la_insercion_manual(): void
    {
        $m = require database_path('migrations/2026_10_04_000005_confirmar_agentes_reglados.php');
        $m->down();
        $m->up();
        [$e, $s] = $this->activa();
        $this->rechazo(QueryException::class, fn () => DB::transaction(fn () => Intervencion::create([
            'id_simulacion' => $s->getKey(), 'id_participante_simulacion' => $s->participantes()->where('rol', 'juez')->sole()->getKey(),
            'id_tipo_audiencia' => $s->id_tipo_audiencia, 'id_etapa' => $s->id_etapa_actual,
            'orden' => 1, 'contenido' => 'Manual.', 'tipo_entrada' => 'texto', 'modelo_ia' => 'otro'])));
        $this->assertSame(1, $this->confirmar($e, $s, $this->proponer($e, $s))->orden);
    }

    public function test_catalogo_editable_no_decide_el_rol_o_la_instruccion(): void
    {
        [$e, $s] = $this->activa();
        PropuestaTurnoAudiencia::query()->where('id_etapa', $s->id_etapa_actual)->update(['rol' => 'fiscal']);
        $e['stages'][0]->update(['activo' => false, 'nombre' => 'Nombre cambiado.']);
        $b = $this->proponer($e, $s);
        $this->assertSame('juez', $b->respuesta['speaker_role']);
        $this->assertSame('Etapa sintetica', $b->preparacion['contexto']['phase']);
        $this->assertStringContainsString('Instruccion sintetica 1.', $b->preparacion['contexto']['turn_instruction']);
    }

    public function test_fuentes_no_utilizadas_no_se_agregan_al_acta(): void
    {
        [$e, $s] = $this->activa();
        $f = $this->fuente($e);
        $this->gateway('juez', [], respuesta: $this->respuesta('juez'));
        $b = $this->proponer($e, $s, [$f->getKey()], prepararGateway: false);
        $this->confirmar($e, $s, $b);
        $this->assertCount(1, $b->preparacion['fuentes']);
        $this->assertDatabaseCount('fuentes_intervencion', 0);
    }

    public function test_proveedor_cambiado_no_se_atribuye_al_borrador(): void
    {
        [$e, $s] = $this->activa();
        $this->gateway('juez', [], respuesta: $this->respuesta('juez', [], 'openai'));
        $this->rechazo(DomainException::class, fn () => $this->proponer($e, $s, prepararGateway: false));
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
    }

    public function test_preparacion_necesita_transaccion_generacion_prohibe_transaccion_externa(): void
    {
        [$e, $s] = $this->activa();
        $this->rechazo(LogicException::class, fn () => app(PrepararAgenteReglado::class)->ejecutar($e['owner'], $s->getKey(), 1, 1, 1, 1, []));
        DB::beginTransaction();
        try {
            $this->rechazo(LogicException::class, fn () => app(ProponerAgenteReglado::class)->ejecutar($e['owner'], $s->getKey(), 1, 1, 1, 1));
        } finally {
            DB::rollBack();
        }
    }

    public function test_pasarela_fastapi_verifica_capacidad_y_propaga_autorizacion_sin_llamar_ia(): void
    {
        config(['services.intelligence.url' => 'http://fastapi.test']);
        Http::fake(['fastapi.test/api/v1/simulations/provider' => Http::response(['data' => [
            'provider' => 'ollama', 'model' => 'modelo-sintetico', 'requires_external_authorization' => false]]),
            'fastapi.test/api/v1/simulations/proposals' => Http::response(['data' => $this->respuesta('juez')], 200, [
                'X-Jurissim-Simulation-Provider' => 'ollama', 'X-Jurissim-Simulation-Model' => 'modelo-sintetico'])]);
        $gateway = app(FastApiAgenteRegladoGateway::class);
        $this->assertSame('ollama', $gateway->proveedor()['provider']);
        $result = $gateway->proponer(['allow_external_processing' => false]);
        $this->assertSame('modelo-sintetico', $result['_meta']['model']);
        Http::assertSent(fn ($r) => $r->url() === 'http://fastapi.test/api/v1/simulations/proposals' && $r['allow_external_processing'] === false);
        Http::assertSentCount(2);
    }

    public function test_sql_preparacion_manipulada_o_respuesta_extra_no_guardan_borrador(): void
    {
        [$e, $s] = $this->activa();
        $x = $this->entrada($s);
        $p = DB::transaction(fn () => app(PrepararAgenteReglado::class)->ejecutar($e['owner'], $s->getKey(), $x['movimiento'], $x['etapa'], $x['turno'], $x['orden'], []));
        $fila = ['id_simulacion' => $s->getKey(), 'id_usuario_responsable' => $e['owner']->getKey(), 'id_movimiento' => $x['movimiento'],
            'id_etapa' => $x['etapa'], 'id_propuesta_turno' => $x['turno'], 'orden' => $x['orden'], 'ids_fragmento' => '[]',
            'preparacion' => json_encode($p), 'respuesta' => json_encode(array_diff_key($this->respuesta('juez'), ['_meta' => true])),
            'proveedor' => 'ollama', 'modelo_ia' => 'modelo-sintetico'];
        $falso = $p;
        $falso['contexto']['turn_instruction'] = 'Instruccion suministrada por el cliente.';
        foreach ([['preparacion' => json_encode($falso)], ['proveedor' => 'openai'],
            ['respuesta' => json_encode(['speaker_role' => 'juez', 'content' => 'Sintetico.', 'used_source_ids' => [], 'requires_human_review' => true, 'otro' => 'No permitido'])]] as $cambio) {
            $this->rechazo(QueryException::class, fn () => DB::transaction(fn () => DB::table('borradores_agentes_reglados')->insert([...$fila, ...$cambio])));
        }
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
    }

    public function test_norma_retirada_despues_del_borrador_no_se_incorpora(): void
    {
        [$e, $s] = $this->activa();
        $texto = 'Norma sintetica sin valor juridico.';
        $j = FuenteJuridica::create(['titulo' => 'Norma sintetica', 'tipo_fuente' => 'ley', 'estado' => 'vigente', 'validada' => true,
            'fecha_vigencia' => now('America/La_Paz')->subDay()->toDateString(), 'contenido_original' => $texto]);
        $f = $j->fragmentos()->create(['contenido' => $texto, 'indice_fragmento' => 0, 'metadatos' => ['md5_contenido_fuente' => md5($texto)]]);
        $b = $this->proponer($e, $s, [$f->getKey()]);
        $this->assertSame('juridica', $b->preparacion['contexto']['sources'][0]['kind']);
        $j->update(['validada' => false]);
        $this->rechazo(DomainException::class, fn () => $this->confirmar($e, $s, $b));
        $this->assertDatabaseCount('intervenciones', 0);
    }

    public function test_turno_de_defensa_y_legacy_no_generan_propuestas_de_agentes(): void
    {
        [$e, $s] = $this->activa();
        foreach (['juez', 'fiscal'] as $rol) {
            $this->confirmar($e, $s, $this->proponer($e, $s));
            $s = app(MoverPracticaReglada::class)->ejecutar($e['owner'], $s->getKey(), 'avanzar', 'activa', $s->id_etapa_actual);
        }
        $gateway = Mockery::mock(AgenteRegladoGateway::class);
        $gateway->shouldNotReceive('proveedor');
        $gateway->shouldNotReceive('proponer');
        $this->app->instance(AgenteRegladoGateway::class, $gateway);
        $this->rechazo(DomainException::class, fn () => $this->proponer($e, $s, prepararGateway: false));
        $legacy = $e['owner']->simulaciones()->create(['id_expediente' => $e['case']->getKey(), 'id_analisis' => $e['analysis']->getKey(),
            'id_tipo_audiencia' => $e['type']->getKey(), 'estado' => 'preparando']);
        $this->rechazo(DomainException::class, fn () => app(ProponerAgenteReglado::class)->ejecutar($e['owner'], $legacy->getKey(), 1, 1, 1, 1));
    }

    public function test_instruccion_demasiado_larga_no_se_recorta_para_el_proveedor(): void
    {
        $e = $this->escenario(null, str_repeat('a', 1501));
        $s = app(MoverPracticaReglada::class)->ejecutar($e['owner'], $this->crear($e)->getKey(), 'iniciar', 'preparando', null);
        $gateway = Mockery::mock(AgenteRegladoGateway::class);
        $gateway->shouldNotReceive('proveedor');
        $gateway->shouldNotReceive('proponer');
        $this->app->instance(AgenteRegladoGateway::class, $gateway);
        $this->rechazo(DomainException::class, fn () => $this->proponer($e, $s, prepararGateway: false));
    }

    public function test_error_http_del_proveedor_no_filtra_cuerpo_de_respuesta(): void
    {
        config(['services.intelligence.url' => 'http://fastapi.test']);
        Http::fake(['fastapi.test/*' => Http::response(['detalle' => 'MARCADOR_PRIVADO'], 503)]);
        try {
            app(FastApiAgenteRegladoGateway::class)->proveedor();
            $this->fail('Se esperaba indisponibilidad.');
        } catch (DomainException $error) {
            $this->assertStringNotContainsString('MARCADOR_PRIVADO', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_fragmento_del_indice_se_proyecta_y_cita_solo_el_extracto_enviado(): void
    {
        [$e, $s] = $this->activa();
        $f = $this->fuente($e);
        $texto = str_repeat('á', 1600);
        $f->pagina->update(['texto_extraido' => $texto]);
        $f->update(['contenido' => $texto]);
        $b = $this->proponer($e, $s, [$f->getKey()]);
        $this->assertSame(1600, mb_strlen($b->preparacion['fuentes'][0]['fragmento_utilizado']));
        $this->assertSame(1500, mb_strlen($b->preparacion['contexto']['sources'][0]['excerpt']));
        $i = $this->confirmar($e, $s, $b);
        $this->assertSame($b->preparacion['contexto']['sources'][0]['excerpt'], $i->fuentes()->sole()->fragmento_utilizado);
    }

    private function entrada(Simulacion $s): array
    {
        $n = $s->intervenciones()->where('id_etapa', $s->id_etapa_actual)->count();

        return ['movimiento' => (int) MovimientoPracticaReglada::query()->where('id_simulacion', $s->getKey())->max('id_movimiento'),
            'etapa' => $s->id_etapa_actual, 'turno' => $s->configuracion['turnos_por_etapa'][$s->id_etapa_actual][$n]['id_propuesta_turno'] ?? 1,
            'orden' => $s->intervenciones()->count() + 1];
    }

    private function proponer(array $e, Simulacion $s, array $ids = [], array $cambio = [], bool $externo = false, bool $prepararGateway = true): BorradorAgenteReglado
    {
        if ($prepararGateway) {
            $n = $s->intervenciones()->where('id_etapa', $s->id_etapa_actual)->count();
            $this->gateway($s->configuracion['turnos_por_etapa'][$s->id_etapa_actual][$n]['rol'] ?? 'juez', $ids);
        }
        $x = [...$this->entrada($s), ...$cambio];

        return app(ProponerAgenteReglado::class)->ejecutar($e['owner'], $s->getKey(), $x['movimiento'], $x['etapa'], $x['turno'], $x['orden'], $ids, $externo);
    }

    private function confirmar(array $e, Simulacion $s, BorradorAgenteReglado $b, bool $humana = true): Intervencion
    {
        return app(ConfirmarAgenteReglado::class)->ejecutar($e['owner'], $s->getKey(), $b->getKey(), $humana);
    }

    private function respuesta(string $rol, array $ids = [], string $proveedor = 'ollama'): array
    {
        return ['speaker_role' => $rol, 'content' => 'Intervencion sintetica sin valor juridico.',
            'used_source_ids' => $ids, 'requires_human_review' => true,
            '_meta' => ['provider' => $proveedor, 'model' => 'modelo-sintetico']];
    }

    private function gateway(string $rol, array $ids = [], ?callable $durante = null, ?array $respuesta = null): void
    {
        $gateway = Mockery::mock(AgenteRegladoGateway::class);
        $gateway->shouldReceive('proveedor')->andReturn(['provider' => 'ollama', 'model' => 'modelo-sintetico', 'requires_external_authorization' => false]);
        $gateway->shouldReceive('proponer')->andReturnUsing(function ($ctx) use ($rol, $ids, $durante, $respuesta) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($rol, $ctx['actor_role']);
            if ($durante !== null) {
                $durante();
            }

            return $respuesta ?? $this->respuesta($rol, $ids);
        });
        $this->app->instance(AgenteRegladoGateway::class, $gateway);
    }

    private function activa(): array
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $s = app(MoverPracticaReglada::class)->ejecutar($e['owner'], $s->getKey(), 'iniciar', 'preparando', null);

        return [$e, $s];
    }

    private function fuente(array $e)
    {
        $archivo = $e['case']->archivos()->create(['nombre_original' => 'sintetico.pdf', 'nombre_almacenado' => 'sintetico.pdf',
            'tipo_mime' => 'application/pdf', 'extension' => 'pdf', 'disco' => 'local', 'ruta_almacenamiento' => 'sintetico.pdf',
            'tamano_bytes' => 128, 'estado_procesamiento' => 'procesado']);
        $pagina = $archivo->paginas()->create(['numero_pagina' => 1, 'localizador' => 'Pagina 1',
            'texto_extraido' => 'Documento sintético para pruebas. Sin valor jurídico.', 'es_legible' => true]);

        return $archivo->fragmentos()->create(['id_pagina' => $pagina->getKey(), 'contenido' => 'Documento sintético para pruebas.',
            'indice_fragmento' => 0, 'numero_pagina' => 1]);
    }

    protected function escenario(?int $segundosRevision = null, ?string $instruccionLarga = null): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $type = TipoAudiencia::create(['nombre' => 'Practica sintetica', 'codigo' => 'sintetica', 'activo' => true]);
        $stages = $instructions = [];
        foreach ([1, 2, 3] as $orden) {
            $etapa = EtapaAudiencia::create(['id_tipo_audiencia' => $type->getKey(), 'codigo' => 'sintetica-'.$orden,
                'nombre' => 'Etapa sintetica', 'orden' => $orden, 'es_inicial' => $orden === 1, 'es_final' => $orden === 3, 'activo' => true]);
            $stages[] = $etapa;
            $p = new PropuestaTurnoAudiencia(['id_tipo_audiencia' => $type->getKey(), 'id_etapa' => $etapa->getKey(),
                'orden' => 1, 'rol' => [1 => 'juez', 2 => 'fiscal', 3 => 'abogado_defensor'][$orden], 'acto_propuesto' => 'Acto sintetico', 'descripcion_propuesta' => 'No es una instruccion.']);
            $p->id_usuario_creador = $admin->getKey();
            $p->save();
            $instructions[] = ['id_propuesta_turno' => $p->getKey(), 'texto' => "  Instruccion sintetica {$orden}.\nSin valor juridico.  "];
        }
        foreach ([[0, 1], [1, 2]] as [$origen, $destino]) {
            TransicionAudiencia::create(['id_tipo_audiencia' => $type->getKey(), 'id_etapa_origen' => $stages[$origen]->getKey(), 'id_etapa_destino' => $stages[$destino]->getKey(), 'activo' => true]);
        }
        if ($instruccionLarga !== null) {
            $instructions[0]['texto'] = $instruccionLarga;
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
}
