<?php

namespace Tests\Feature;

use App\Models\Intervencion;
use App\Models\Simulacion;
use App\Modules\Simulations\Application\Contracts\AgenteRegladoGateway;
use App\Services\Simulaciones\GestionarActivacionReglas;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\EscenarioPracticaReglada;
use Tests\TestCase;

class PracticaRegladaApiTest extends TestCase
{
    use EscenarioPracticaReglada;

    private function ruta(int $id, string $accion = 'orquestacion'): string
    {
        return "/api/v1/practicas-regladas/{$id}/{$accion}";
    }

    private function entrada(int $id): array
    {
        $d = $this->getJson($this->ruta($id))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertFalse($d['execution_authorized']);
        $this->assertSame([], $d['allowed_actions']);

        return $d;
    }

    private function contexto(array $d): array
    {
        return array_diff_key($d['expected_context'], ['estado_esperado' => true]);
    }

    private function iniciar(array $e): int
    {
        Sanctum::actingAs($e['owner']);
        $id = $this->postJson("/api/v1/practicas-regladas/expedientes/{$e['case']->getKey()}", [
            'id_analisis' => $e['analysis']->getKey(), 'id_tipo_audiencia' => $e['type']->getKey(), 'id_activacion' => $e['activation']->getKey(),
        ])->assertCreated()->json('data.id_simulacion');
        $d = $this->entrada($id);
        $this->assertSame(['iniciar'], $d['technical_candidate_actions']);
        $this->postJson($this->ruta($id, 'movimientos'), ['accion' => 'iniciar', 'estado_esperado' => 'preparando', 'id_etapa_esperada' => null])
            ->assertOk()->assertJsonPath('data.estado', 'activa');

        return $id;
    }

    private function gateway(string $proveedor = 'ollama'): void
    {
        $g = Mockery::mock(AgenteRegladoGateway::class);
        $g->shouldReceive('proveedor')->andReturn(['provider' => $proveedor, 'model' => 'sintetico',
            'requires_external_authorization' => $proveedor === 'openai', 'no_exponer' => 'SECRETO_PASARELA']);
        $g->shouldReceive('proponer')->andReturnUsing(function ($ctx) use ($proveedor): array {
            $this->assertSame(0, DB::transactionLevel());

            return ['speaker_role' => $ctx['actor_role'], 'content' => 'Texto sintético para revisión humana.',
                'used_source_ids' => array_column($ctx['sources'], 'id'), 'requires_human_review' => true,
                '_meta' => ['provider' => $proveedor, 'model' => 'sintetico']];
        });
        $this->app->instance(AgenteRegladoGateway::class, $g);
    }

    public function test_api_recorrido_completo_y_confirmacion_repetida_no_avanza(): void
    {
        $e = $this->escenario();
        $this->gateway();
        $id = $this->iniciar($e);
        foreach (['juez', 'fiscal'] as $rol) {
            $d = $this->entrada($id);
            $this->assertSame('esperando_agente', $d['status']);
            $this->assertSame($rol, $d['current_turn']['rol']);
            $b = $this->postJson($this->ruta($id, 'borradores'), $this->contexto($d))->assertCreated()->json('data');
            $this->assertFalse($b['confirmation_authorized']);
            $this->assertTrue($b['requires_human_review']);
            $this->assertFalse($b['vencido']);
            $this->assertSame($d['expected_context'], $this->entrada($id)['expected_context']);
            $url = $this->ruta($id, "borradores/{$b['id_borrador']}/confirmacion");
            $i = $this->postJson($url, ['confirmacion_humana' => true])->assertOk()->json('data.id_intervencion');
            $this->postJson($url, ['confirmacion_humana' => true])->assertOk()->assertJsonPath('data.id_intervencion', $i);
            $d = $this->entrada($id);
            $this->assertSame(['avanzar'], $d['technical_candidate_actions']);
            $this->postJson($this->ruta($id, 'movimientos'), [
                'accion' => 'avanzar', 'estado_esperado' => 'activa', 'id_etapa_esperada' => $d['stage']['id_etapa'],
            ])->assertOk();
        }
        $d = $this->entrada($id);
        $this->assertSame('esperando_defensa', $d['status']);
        $this->postJson($this->ruta($id, 'defensa'), [...$this->contexto($d), 'contenido' => 'Defensa sintética.'])
            ->assertCreated()->assertJsonPath('data.orden', 3);
        $d = $this->entrada($id);
        $this->assertSame(['finalizar'], $d['technical_candidate_actions']);
        $this->postJson($this->ruta($id, 'movimientos'), ['accion' => 'finalizar', 'estado_esperado' => 'activa',
            'id_etapa_esperada' => $d['stage']['id_etapa']])->assertOk()->assertJsonPath('data.estado', 'finalizada');
        $d = $this->entrada($id);
        $this->assertSame('finalizada', $d['status']);
        $this->assertSame([], $d['technical_candidate_actions']);
        $this->assertDatabaseCount('intervenciones', 3);
        $this->assertDatabaseCount('registros_intervenciones_regladas', 3);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_todas_las_operaciones_requieren_autenticacion_y_no_cache(): void
    {
        foreach (['orquestacion', 'proveedor', 'borradores/1'] as $accion) {
            $this->getJson($this->ruta(1, $accion))->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        }
        foreach (['movimientos', 'defensa', 'borradores', 'borradores/1/confirmacion'] as $accion) {
            $this->postJson($this->ruta(1, $accion), [])->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->postJson('/api/v1/practicas-regladas/expedientes/1')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_propiedad_de_practica_y_expediente_en_todas_las_rutas(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        $this->gateway();
        $d = $this->entrada($id);
        $b = $this->postJson($this->ruta($id, 'borradores'), $this->contexto($d))->assertCreated()->json('data.id_borrador');
        Sanctum::actingAs($e['admin']);
        foreach (['orquestacion', 'proveedor', "borradores/{$b}"] as $accion) {
            $this->getJson($this->ruta($id, $accion))->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->postJson($this->ruta($id, 'movimientos'), ['accion' => 'avanzar', 'estado_esperado' => 'activa',
            'id_etapa_esperada' => $d['stage']['id_etapa']])->assertNotFound();
        $this->postJson($this->ruta($id, 'defensa'), [...$this->contexto($d), 'contenido' => 'Texto sintético.'])->assertNotFound();
        $this->postJson($this->ruta($id, 'borradores'), $this->contexto($d))->assertNotFound();
        $this->postJson($this->ruta($id, "borradores/{$b}/confirmacion"), ['confirmacion_humana' => true])->assertNotFound();
        $this->postJson("/api/v1/practicas-regladas/expedientes/{$e['case']->getKey()}", [
            'id_analisis' => $e['analysis']->getKey(), 'id_tipo_audiencia' => $e['type']->getKey(), 'id_activacion' => $e['activation']->getKey(),
        ])->assertNotFound();
        $this->assertDatabaseCount('intervenciones', 0);
    }

    public function test_confirmacion_y_seleccion_son_explicitas_sin_campos_adicionales(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        $d = $this->entrada($id);
        foreach ([['id_usuario' => $e['owner']->getKey()], ['autorizar_externo' => 'true'],
            ['ids_fragmento' => ['1']], ['ids_fragmento' => [1, 1]], ['ids_fragmento' => range(1, 9)],
            ['ids_fragmento' => ['x' => 1]]] as $extra) {
            $this->withoutMiddleware(ThrottleRequests::class);
            $this->postJson($this->ruta($id, 'borradores'), [...$this->contexto($d), ...$extra])
                ->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->gateway();
        $b = $this->postJson($this->ruta($id, 'borradores'), $this->contexto($d))->assertCreated()->json('data.id_borrador');
        foreach ([[], ['confirmacion_humana' => false], ['confirmacion_humana' => 'true'],
            ['confirmacion_humana' => 1], ['confirmacion_humana' => true, 'contenido' => 'Cambiar borrador']] as $campos) {
            $this->postJson($this->ruta($id, "borradores/{$b}/confirmacion"), $campos)->assertUnprocessable();
        }
        $this->assertDatabaseCount('intervenciones', 0);
        Http::assertNothingSent();
    }

    public function test_turno_obsoleto_no_llama_proveedor_y_no_se_salta_etapa(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        $d = $this->entrada($id);
        $g = Mockery::mock(AgenteRegladoGateway::class);
        $g->shouldNotReceive('proveedor');
        $g->shouldNotReceive('proponer');
        $this->app->instance(AgenteRegladoGateway::class, $g);
        $this->postJson($this->ruta($id, 'borradores'), [...$this->contexto($d), 'orden_esperado' => 8])->assertConflict();
        $this->postJson($this->ruta($id, 'defensa'), [...$this->contexto($d), 'contenido' => 'Saltarse juez.'])->assertConflict();
        foreach (['avanzar', 'finalizar'] as $accion) {
            $this->postJson($this->ruta($id, 'movimientos'), ['accion' => $accion, 'estado_esperado' => 'activa',
                'id_etapa_esperada' => $d['stage']['id_etapa']])->assertConflict();
        }
        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
    }

    public function test_retiro_bloquea_consulta_y_comandos_sin_borrar_borradores(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        $this->gateway();
        $d = $this->entrada($id);
        $b = $this->postJson($this->ruta($id, 'borradores'), $this->contexto($d))->assertCreated()->json('data.id_borrador');
        app(GestionarActivacionReglas::class)->retirar($e['admin'], $e['activation']->getKey(), 'Retiro sintético.');
        $bloqueada = $this->entrada($id);
        $this->assertSame('bloqueada', $bloqueada['status']);
        $this->assertSame([], $bloqueada['technical_candidate_actions']);
        $this->assertNull($bloqueada['expected_context']);
        $this->getJson($this->ruta($id, "borradores/{$b}"))->assertOk()->assertJsonPath('data.confirmation_authorized', false);
        $this->postJson($this->ruta($id, "borradores/{$b}/confirmacion"), ['confirmacion_humana' => true])->assertConflict();
        $this->postJson($this->ruta($id, 'borradores'), $this->contexto($d))->assertConflict();
        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertDatabaseCount('borradores_agentes_reglados', 1);
    }

    public function test_copia_sellada_no_cambia_al_editar_catalogo_y_consulta_no_llama_ia(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        $e['stages'][0]->update(['nombre' => 'Nombre editable NO_USAR']);
        $e['type']->update(['nombre' => 'Tipo editable NO_USAR']);
        $d = $this->entrada($id);
        $this->assertSame('Etapa sintetica', $d['stage']['nombre']);
        $this->assertSame('Practica sintetica', $d['type']['nombre']);
        $this->assertSame($e['instructions'][0]['texto'], $d['current_turn']['instruccion']);
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
        $this->assertDatabaseCount('intervenciones', 0);
        Http::assertNothingSent();
    }

    public function test_openai_requiere_consentimiento_booleano_en_esa_solicitud(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        $d = $this->entrada($id);
        $g = Mockery::mock(AgenteRegladoGateway::class);
        $g->shouldReceive('proveedor')->once()->andReturn(['provider' => 'openai', 'model' => 'sintetico', 'requires_external_authorization' => true]);
        $g->shouldNotReceive('proponer');
        $this->app->instance(AgenteRegladoGateway::class, $g);
        $this->postJson($this->ruta($id, 'borradores'), $this->contexto($d))->assertConflict();
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
        $this->gateway('openai');
        $this->postJson($this->ruta($id, 'borradores'), [...$this->contexto($d), 'autorizar_externo' => true])
            ->assertCreated()->assertJsonPath('data.autorizar_externo', true);
        $this->assertDatabaseCount('intervenciones', 0);
        Http::assertNothingSent();
    }

    public function test_proveedor_no_envia_documentos_y_proyecta_solo_metadatos_publicos(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        $this->gateway('openai');
        $this->getJson($this->ruta($id, 'proveedor'))->assertOk()->assertExactJson([
            'data' => ['provider' => 'openai', 'model' => 'sintetico', 'requires_external_authorization' => true],
        ]);
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
        Http::assertNothingSent();
    }

    public function test_borrador_no_filtra_preparacion_analisis_rutas_o_huellas(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        $e['analysis']->update(['datos_estructurados' => ['secreto' => 'NO_PUBLICAR']]);
        $this->gateway();
        $b = $this->postJson($this->ruta($id, 'borradores'), $this->contexto($this->entrada($id)))->assertCreated()->json('data.id_borrador');
        $r = $this->getJson($this->ruta($id, "borradores/{$b}"))->assertOk();
        foreach (['NO_PUBLICAR', 'preparacion', 'huella', 'ruta_almacenamiento', 'SECRETO_PASARELA'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $r->getContent());
        }
        $this->assertSame([], $r->json('data.fuentes_enviadas'));
    }

    public function test_legacy_no_adquiere_turnos_o_revision_juridica_por_consultarse(): void
    {
        $e = $this->escenario();
        Sanctum::actingAs($e['owner']);
        $s = Simulacion::factory()->create(['id_usuario' => $e['owner']->getKey(), 'id_expediente' => $e['case']->getKey(),
            'id_analisis' => $e['analysis']->getKey(), 'id_tipo_audiencia' => $e['type']->getKey()]);
        $d = $this->entrada($s->getKey());
        $this->assertSame('sin_vinculacion', $d['binding_status']);
        $this->assertSame([], $d['technical_candidate_actions']);
        $this->assertDatabaseCount('vinculaciones_reglas_practica', 0);
    }

    public function test_errores_sql_no_filtran_detalles_con_debug_activo(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        config(['app.debug' => true]);
        $g = Mockery::mock(AgenteRegladoGateway::class);
        $g->shouldReceive('proveedor')->andThrow(new QueryException('pgsql', 'SQL_SECRETO', ['PARAM_SECRETO'], new \RuntimeException('DETALLE_SECRETO')));
        $this->app->instance(AgenteRegladoGateway::class, $g);
        $r = $this->getJson($this->ruta($id, 'proveedor'))->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        foreach (['SQL_SECRETO', 'PARAM_SECRETO', 'DETALLE_SECRETO', 'trace'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $r->getContent());
        }
    }

    public function test_rate_limit_no_genera_borradores_ni_filtra_detalles(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        for ($n = 0; $n < 5; $n++) {
            $this->postJson($this->ruta($id, 'borradores'), [])->assertUnprocessable();
        }
        $this->postJson($this->ruta($id, 'borradores'), [])->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('borradores_agentes_reglados', 0);
        Http::assertNothingSent();
    }

    public function test_fuentes_enviadas_y_utilizadas_se_pueden_revisar_sin_rutas_privadas(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        $a = $e['case']->archivos()->create(['nombre_original' => 'sintetico.pdf', 'nombre_almacenado' => 'sintetico.pdf',
            'tipo_mime' => 'application/pdf', 'extension' => 'pdf', 'disco' => 'local', 'ruta_almacenamiento' => 'RUTA_PRIVADA',
            'tamano_bytes' => 128, 'estado_procesamiento' => 'procesado']);
        $p = $a->paginas()->create(['numero_pagina' => 1, 'localizador' => 'Pagina 1', 'texto_extraido' => str_repeat('x', 1600), 'es_legible' => true]);
        $f = $a->fragmentos()->create(['id_pagina' => $p->getKey(), 'contenido' => $p->texto_extraido, 'indice_fragmento' => 0, 'numero_pagina' => 1]);
        $this->gateway();
        $r = $this->postJson($this->ruta($id, 'borradores'), [...$this->contexto($this->entrada($id)), 'ids_fragmento' => [$f->getKey()]])->assertCreated();
        $this->assertStringNotContainsString('RUTA_PRIVADA', $r->getContent());
        $this->assertSame(str_repeat('x', 1500), $r->json('data.fuentes_enviadas.0.excerpt'));
        $this->assertSame([$f->getKey()], $r->json('data.respuesta.used_source_ids'));
        $this->assertDatabaseCount('intervenciones', 0);
        $b = $r->json('data.id_borrador');
        $this->postJson($this->ruta($id, "borradores/{$b}/confirmacion"), ['confirmacion_humana' => true])->assertOk();
        $this->assertSame(str_repeat('x', 1500), Intervencion::query()->sole()->fuentes()->sole()->fragmento_utilizado);
    }

    public function test_borrador_de_otra_practica_del_mismo_usuario_no_se_cruza(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        $this->gateway();
        $b = $this->postJson($this->ruta($id, 'borradores'), $this->contexto($this->entrada($id)))->assertCreated()->json('data.id_borrador');
        $otra = $this->crear($e)->getKey();
        $this->getJson($this->ruta($otra, "borradores/{$b}"))->assertNotFound();
        $this->postJson($this->ruta($otra, "borradores/{$b}/confirmacion"), ['confirmacion_humana' => true])->assertNotFound();
        $this->assertDatabaseCount('intervenciones', 0);
    }

    public function test_crear_no_aprueba_analisis_ni_sustituye_activacion_retirada(): void
    {
        $e = $this->escenario();
        Sanctum::actingAs($e['owner']);
        $url = "/api/v1/practicas-regladas/expedientes/{$e['case']->getKey()}";
        $d = ['id_analisis' => $e['analysis']->getKey(), 'id_tipo_audiencia' => $e['type']->getKey(), 'id_activacion' => $e['activation']->getKey()];
        $this->aprobarAnalisis($e, 'requiere_cambios');
        $this->postJson($url, $d)->assertUnprocessable();
        $this->aprobarAnalisis($e);
        app(GestionarActivacionReglas::class)->retirar($e['admin'], $e['activation']->getKey(), 'Retiro sintético.');
        $this->postJson($url, $d)->assertUnprocessable();
        $this->assertDatabaseCount('simulaciones', 0);
        $this->assertDatabaseCount('vinculaciones_reglas_practica', 0);
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 1);
    }

    public function test_vencimiento_se_consulta_con_reloj_db_y_no_confirmacion_optimista(): void
    {
        $e = $this->escenario();
        $id = $this->iniciar($e);
        $this->gateway();
        $b = $this->postJson($this->ruta($id, 'borradores'), $this->contexto($this->entrada($id)))->assertCreated()->json('data.id_borrador');
        // Solo fechas sintéticas en jurissim_pruebas, con restauración inmediata del guardia.
        DB::transaction(function () use ($b): void {
            DB::statement('ALTER TABLE borradores_agentes_reglados DISABLE TRIGGER borrador_agente_inmutable');
            try {
                DB::update("UPDATE borradores_agentes_reglados SET fecha_creacion=clock_timestamp()-interval '11 minutes', fecha_vencimiento=clock_timestamp()-interval '1 minute' WHERE id_borrador=?", [$b]);
            } finally {
                DB::statement('ALTER TABLE borradores_agentes_reglados ENABLE TRIGGER borrador_agente_inmutable');
            }
        });
        $this->getJson($this->ruta($id, "borradores/{$b}"))->assertOk()->assertJsonPath('data.vencido', true)->assertJsonPath('data.confirmation_authorized', false);
        $this->postJson($this->ruta($id, "borradores/{$b}/confirmacion"), ['confirmacion_humana' => true])->assertConflict();
        $this->assertDatabaseCount('intervenciones', 0);
    }
}
