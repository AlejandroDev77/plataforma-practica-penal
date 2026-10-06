<?php

namespace Tests\Feature;

use App\Jobs\EvaluarSimulacion;
use App\Models\Rubrica;
use App\Models\Simulacion;
use App\Modules\Simulations\Application\Contracts\AgenteRegladoGateway;
use App\Services\Simulaciones\ConfirmarAgenteReglado;
use App\Services\Simulaciones\GestionarActivacionReglas;
use App\Services\Simulaciones\MoverPracticaReglada;
use App\Services\Simulaciones\OrquestadorPracticaReglada;
use App\Services\Simulaciones\ProponerAgenteReglado;
use App\Services\Simulaciones\RegistrarDefensaReglada;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\EscenarioPracticaReglada;
use Tests\TestCase;

class PreparacionEvaluacionRegladaTest extends TestCase
{
    use EscenarioPracticaReglada;

    private function ruta(int $id): string
    {
        return "/api/v1/practicas-regladas/{$id}/preparacion-evaluacion";
    }

    private function leer(int $id): array
    {
        $d = $this->getJson($this->ruta($id))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertTrue($d['read_only']);
        $this->assertFalse($d['execution_authorized']);
        $this->assertSame([], $d['allowed_actions']);
        $this->assertSame('no_verificable', $d['rubric_review_status']);
        $this->assertSame(['revision_rubrica_no_verificable', 'ejecucion_no_integrada'], $d['blocking_reasons']);

        return $d;
    }

    private function rubrica(array $e, array $extra = [], bool $criterio = true): Rubrica
    {
        $r = Rubrica::create(['nombre' => 'Rúbrica sintética '.Rubrica::count(), 'version' => 1,
            'id_tipo_audiencia' => $e['type']->getKey(), 'rol_aplicable' => 'abogado_defensor', 'activo' => true, ...$extra]);
        if ($criterio) {
            $r->criterios()->create(['nombre' => 'Criterio sintético', 'descripcion' => 'Sin valor jurídico.',
                'peso' => 1, 'puntaje_maximo' => 10, 'orden' => 1]);
        }

        return $r;
    }

    private function cerrar(array $e, array $fuentes = []): Simulacion
    {
        $g = Mockery::mock(AgenteRegladoGateway::class);
        $g->shouldReceive('proveedor')->twice()->andReturn(['provider' => 'ollama', 'model' => 'sintetico', 'requires_external_authorization' => false]);
        $g->shouldReceive('proponer')->twice()->andReturnUsing(function ($ctx): array {
            $this->assertSame(0, DB::transactionLevel());

            return ['speaker_role' => $ctx['actor_role'], 'content' => 'Texto.', 'used_source_ids' => [],
                'requires_human_review' => true, '_meta' => ['provider' => 'ollama', 'model' => 'sintetico']];
        });
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
            $c['id_etapa_esperada'], $c['id_turno_esperado'], $c['orden_esperado'], 'Texto.', $fuentes);
        Sanctum::actingAs($e['owner']);
        Queue::fake();

        return $m->ejecutar($e['owner'], $s->getKey(), 'finalizar', 'activa', $s->id_etapa_actual);
    }

    public function test_consulta_privada_no_admite_campos_y_no_ejecuta(): void
    {
        $this->getJson($this->ruta(1))->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $e = $this->escenario();
        $s = $this->crear($e);
        Sanctum::actingAs($e['owner']);
        foreach (['id_usuario=1', 'id_rubrica=1', 'autorizar_externo=true', 'contenido=ajeno'] as $consulta) {
            $this->getJson($this->ruta($s->getKey()).'?'.$consulta)->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->getJson($this->ruta(999999))->assertNotFound();
        $this->postJson($this->ruta($s->getKey()))->assertStatus(405)->assertHeader('Cache-Control', 'no-store, private');
        Sanctum::actingAs($e['admin']);
        $this->getJson($this->ruta($s->getKey()))->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('evaluaciones', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_preparacion_y_practica_antigua_no_se_convierten_en_evaluables(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        Sanctum::actingAs($e['owner']);
        $d = $this->leer($s->getKey());
        $this->assertSame(['practica_no_finalizada'], $d['technical_blocking_reasons']);
        $this->assertNull($d['rubric']);
        $this->assertNull($d['transcript']);
        $vieja = Simulacion::factory()->create(['id_usuario' => $e['owner']->getKey(), 'id_expediente' => $e['case']->getKey(),
            'id_analisis' => $e['analysis']->getKey(), 'id_tipo_audiencia' => $e['type']->getKey(), 'estado' => 'finalizada']);
        $this->assertSame(['practica_no_vigente'], $this->leer($vieja->getKey())['technical_blocking_reasons']);
        $this->assertDatabaseCount('vinculaciones_reglas_practica', 1);
    }

    public function test_cierre_con_rubrica_tecnica_no_acredita_revision_ni_envia_contexto(): void
    {
        $e = $this->escenario();
        $s = $this->cerrar($e);
        $r = $this->rubrica($e);
        $e['analysis']->update(['datos_estructurados' => ['secreto' => 'NO_PUBLICAR_ANALISIS']]);
        $d = $this->leer($s->getKey());
        $this->assertTrue($d['technical_ready']);
        $this->assertSame([], $d['technical_blocking_reasons']);
        $this->assertSame(['id_rubrica' => $r->getKey(), 'version' => 1, 'criterion_count' => 1], $d['rubric']);
        $this->assertSame(3, $d['transcript']['intervention_count']);
        $this->assertSame(1, $d['transcript']['defense_count']);
        $this->assertTrue($d['transcript']['complete_count']);
        $contenido = $this->getJson($this->ruta($s->getKey()))->getContent();
        foreach (['Texto.', 'NO_PUBLICAR_ANALISIS', 'Sin valor jurídico.', 'huella', 'id_usuario', 'preparacion'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $contenido);
        }
        $this->assertDatabaseCount('evaluaciones', 0);
        $this->assertDatabaseCount('resultados_evaluacion', 0);
        $this->assertDatabaseCount('registros_intervenciones_regladas', 3);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_sin_rubrica_o_ambiguas_nunca_elige_una_arbitraria(): void
    {
        $e = $this->escenario();
        $s = $this->cerrar($e);
        $this->assertSame(['sin_rubrica'], $this->leer($s->getKey())['technical_blocking_reasons']);
        $this->rubrica($e);
        $this->rubrica($e);
        $d = $this->leer($s->getKey());
        $this->assertSame(['rubrica_ambigua'], $d['technical_blocking_reasons']);
        $this->assertNull($d['rubric']);
    }

    public function test_especificidad_igual_al_evaluador_y_reconsulta_catalogo_sin_congelarlo(): void
    {
        $e = $this->escenario();
        $s = $this->cerrar($e);
        $this->rubrica($e, ['rol_aplicable' => null, 'id_tipo_audiencia' => null]);
        $this->rubrica($e, ['rol_aplicable' => null]);
        $rol = $this->rubrica($e, ['id_tipo_audiencia' => null]);
        $this->assertSame($rol->getKey(), $this->leer($s->getKey())['rubric']['id_rubrica']);
        $exacta = $this->rubrica($e);
        $this->assertSame($exacta->getKey(), $this->leer($s->getKey())['rubric']['id_rubrica']);
        $exacta->criterios()->update(['descripcion' => ' ']);
        $this->assertSame(['rubrica_incompatible'], $this->leer($s->getKey())['technical_blocking_reasons']);
        $exacta->update(['activo' => false]);
        $this->assertSame($rol->getKey(), $this->leer($s->getKey())['rubric']['id_rubrica']);
        $this->assertDatabaseCount('evaluaciones', 0);
    }

    public function test_criterios_vacios_texto_y_cantidad_fuera_de_contrato_bloquean(): void
    {
        $e = $this->escenario();
        $s = $this->cerrar($e);
        $r = $this->rubrica($e, [], false);
        $this->assertSame(['rubrica_incompatible'], $this->leer($s->getKey())['technical_blocking_reasons']);
        $c = $r->criterios()->create(['nombre' => ' ', 'descripcion' => 'Texto', 'peso' => 1, 'puntaje_maximo' => 10, 'orden' => 1]);
        $this->assertSame(['rubrica_incompatible'], $this->leer($s->getKey())['technical_blocking_reasons']);
        $c->update(['nombre' => "\u{00A0}\u{2003}"]);
        $this->assertSame(['rubrica_incompatible'], $this->leer($s->getKey())['technical_blocking_reasons']);
        $c->update(['nombre' => 'Criterio', 'descripcion' => str_repeat('a', 2001)]);
        $this->assertSame(['rubrica_incompatible'], $this->leer($s->getKey())['technical_blocking_reasons']);
        $c->update(['descripcion' => 'Texto']);
        for ($n = 2; $n <= 21; $n++) {
            $r->criterios()->create(['nombre' => 'Criterio', 'descripcion' => 'Texto', 'peso' => 1, 'puntaje_maximo' => 10, 'orden' => $n]);
        }
        $this->assertSame(['rubrica_incompatible'], $this->leer($s->getKey())['technical_blocking_reasons']);
    }

    public function test_limite_contexto_20000_y_unicode_sin_truncado_silencioso(): void
    {
        $e = $this->escenario();
        $s = $this->cerrar($e);
        $r = $this->rubrica($e, [], false);
        for ($n = 1; $n <= 20; $n++) {
            $ultimo = $r->criterios()->create(['nombre' => 'ñ', 'descripcion' => str_repeat('😀', 980),
                'peso' => 1, 'puntaje_maximo' => 10, 'orden' => $n]);
        }
        $d = $this->leer($s->getKey());
        $this->assertSame(19638, $d['transcript']['context_character_count']);
        $ultimo->update(['descripcion' => str_repeat('😀', 1342)]);
        $this->assertTrue($this->leer($s->getKey())['technical_ready']);
        $ultimo->update(['descripcion' => str_repeat('😀', 1343)]);
        $d = $this->leer($s->getKey());
        $this->assertSame(20001, $d['transcript']['context_character_count']);
        $this->assertSame(['limite_contexto'], $d['technical_blocking_reasons']);
        $this->assertFalse($d['technical_ready']);
    }

    public function test_revision_o_activacion_retirada_bloquea_incluso_despues_del_cierre(): void
    {
        $e = $this->escenario();
        $s = $this->cerrar($e);
        $this->rubrica($e);
        $this->aprobarAnalisis($e, 'requiere_cambios');
        $d = $this->leer($s->getKey());
        $this->assertSame(['practica_no_vigente'], $d['technical_blocking_reasons']);
        $this->assertNull($d['transcript']);
        $this->aprobarAnalisis($e);
        $this->assertTrue($this->leer($s->getKey())['technical_ready']);
        app(GestionarActivacionReglas::class)->retirar($e['admin'], $e['activation']->getKey(), 'Retiro sintético.');
        $this->assertFalse($this->leer($s->getKey())['technical_ready']);
        $this->assertDatabaseCount('evaluaciones', 0);
        Http::assertNothingSent();
    }

    public function test_evaluacion_en_curso_impide_duplicados_sin_reencolarla(): void
    {
        $e = $this->escenario();
        $s = $this->cerrar($e);
        $r = $this->rubrica($e);
        $ev = $s->evaluaciones()->create(['id_rubrica' => $r->getKey(), 'estado' => 'pendiente']);
        foreach (['pendiente', 'procesando'] as $estado) {
            $ev->update(['estado' => $estado]);
            $this->assertSame(['evaluacion_en_curso'], $this->leer($s->getKey())['technical_blocking_reasons']);
        }
        $ev->update(['estado' => 'error']);
        $this->assertTrue($this->leer($s->getKey())['technical_ready']);
        $this->assertDatabaseCount('evaluaciones', 1);
        Queue::assertNothingPushed();
    }

    public function test_acta_cerrada_conserva_proteccion_sql_y_consulta_no_cambia_estado(): void
    {
        $e = $this->escenario();
        $s = $this->cerrar($e);
        $this->rubrica($e);
        try {
            DB::transaction(fn () => DB::table('intervenciones')->where('id_simulacion', $s->getKey())->update(['contenido' => 'Manipulado']));
            $this->fail('El acta reglada es inmutable.');
        } catch (QueryException $error) {
            $this->assertSame('23514', $error->errorInfo[0]);
        }
        $antes = $s->fresh()->getAttributes();
        $this->assertTrue($this->leer($s->getKey())['technical_ready']);
        $this->assertSame($antes, $s->fresh()->getAttributes());
        $this->assertDatabaseCount('movimientos_practicas_regladas', 4);
        $this->assertDatabaseCount('evaluaciones', 0);
    }

    public function test_fuentes_de_defensa_no_se_recortan_para_ocultar_exceso(): void
    {
        $e = $this->escenario();
        $this->rubrica($e);
        foreach ([1000, 1001] as $longitud) {
            $a = $e['case']->archivos()->create(['nombre_original' => 'sintetico.pdf', 'nombre_almacenado' => 'sintetico.pdf',
                'tipo_mime' => 'application/pdf', 'extension' => 'pdf', 'disco' => 'local', 'ruta_almacenamiento' => 'RUTA_PRIVADA_'.$longitud,
                'tamano_bytes' => 128, 'estado_procesamiento' => 'procesado']);
            $p = $a->paginas()->create(['numero_pagina' => 1, 'localizador' => 'Página 1',
                'texto_extraido' => str_repeat('ñ', $longitud), 'es_legible' => true]);
            $f = $a->fragmentos()->create(['id_pagina' => $p->getKey(), 'contenido' => $p->texto_extraido,
                'indice_fragmento' => 0, 'numero_pagina' => 1]);
            $s = $this->cerrar($e, [$f->getKey()]);
            $d = $this->leer($s->getKey());
            $this->assertSame($longitud === 1000, $d['technical_ready']);
            $this->assertSame($longitud === 1000 ? [] : ['fuentes_incompatibles'], $d['technical_blocking_reasons']);
            $respuesta = $this->getJson($this->ruta($s->getKey()))->getContent();
            $this->assertStringNotContainsString('RUTA_PRIVADA', $respuesta);
            $this->assertStringNotContainsString(str_repeat('ñ', 50), $respuesta);
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_evaluador_antiguo_no_elude_el_circuito_reglado(): void
    {
        $e = $this->escenario();
        $s = $this->cerrar($e);
        $r = $this->rubrica($e);
        $this->postJson('/api/v1/simulaciones/'.$s->getKey().'/evaluacion')
            ->assertUnprocessable()->assertJsonValidationErrors('simulacion');
        $this->assertDatabaseCount('evaluaciones', 0);
        Queue::assertNothingPushed();
        // Una entrada de cola anterior tampoco debe alcanzar el servicio de generación.
        $ev = $s->evaluaciones()->create(['id_rubrica' => $r->getKey(), 'estado' => 'pendiente']);
        $job = new EvaluarSimulacion($ev->getKey());
        try {
            $job->handle();
            $this->fail('No debe procesar una práctica reglada mediante el evaluador antiguo.');
        } catch (\UnexpectedValueException $error) {
            $job->failed($error);
        }
        $this->assertSame('error', $ev->fresh()->estado);
        $this->assertDatabaseCount('resultados_evaluacion', 0);
        Http::assertNothingSent();
    }
}
