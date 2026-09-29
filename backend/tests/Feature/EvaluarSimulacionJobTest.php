<?php

namespace Tests\Feature;

use App\Jobs\EvaluarSimulacion;
use App\Models\CriterioRubrica;
use App\Models\EtapaAudiencia;
use App\Models\FragmentoDocumento;
use App\Models\Rubrica;
use App\Models\Simulacion;
use App\Services\Evaluaciones\SolicitarEvaluacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvaluarSimulacionJobTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'jurissim_pruebas'
            || config('database.connections.pgsql.url')) {
            throw new \RuntimeException('Las pruebas destructivas requieren PostgreSQL jurissim_pruebas, sin DB_URL.');
        }
    }

    public function test_job_persists_weighted_score_feedback_and_verified_evidence(): void
    {
        [$simulacion, $criterio] = $this->prepararSimulacion();
        $evaluacion = app(SolicitarEvaluacion::class)->ejecutar($simulacion);
        config(['services.intelligence.url' => 'http://localhost:8100']);
        config(['services.intelligence.token' => 'token-de-prueba']);
        config(['services.intelligence.timeout' => 10]);
        Http::fake([
            'http://localhost:8100/api/v1/evaluations/evaluate' => Http::response([
                'data' => [
                    'summary' => 'La defensa formuló una solicitud comprensible.',
                    'criteria' => [[
                        'criterion_id' => $criterio->getKey(),
                        'score' => 7,
                        'feedback' => 'La petición se comprende y puede vincularse mejor con el criterio.',
                        'strengths' => [[
                            'content' => 'La petición se expresa de forma directa.',
                            'evidence' => [
                                'intervention_order' => 1,
                                'quote' => 'Solicito que se valore la petición',
                            ],
                        ]],
                        'errors' => [],
                        'recommendations' => ['Explica la relación entre el arraigo y la petición.'],
                    ]],
                    'requires_human_review' => true,
                ],
            ], 200, [
                'X-Jurissim-Evaluation-Provider' => 'ollama',
                'X-Jurissim-Evaluation-Model' => 'qwen3.5:2b-q4_K_M',
            ]),
        ]);

        (new EvaluarSimulacion($evaluacion->getKey()))->handle();
        $resultado = $evaluacion->fresh(['rubrica', 'resultados.criterio']);

        $this->assertSame('procesado', $resultado->estado);
        $this->assertSame('70.0000', $resultado->puntaje_total);
        $this->assertSame('qwen3.5:2b-q4_K_M', $resultado->modelo_ia);
        $this->assertTrue($resultado->datos_evaluacion['requires_human_review']);
        $this->assertSame('La defensa formuló una solicitud comprensible.', $resultado->datos_evaluacion['summary']);
        $this->assertSame('Solicito que se valore la petición', $resultado->resultados->first()->evidencia);
        $this->assertSame('La petición se expresa de forma directa.', $resultado->datos_evaluacion['strengths'][0]['content']);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:8100/api/v1/evaluations/evaluate'
            && $request->hasHeader('Authorization', 'Bearer token-de-prueba')
            && $request['transcript'][0]['role'] === 'abogado_defensor'
            && $request['transcript'][0]['sources'][0]['kind'] === 'expediente'
            && $request['transcript'][0]['sources'][0]['excerpt'] === 'El certificado laboral indica empleo continuo.');
    }

    public function test_job_rejects_evidence_that_does_not_match_defense_transcript(): void
    {
        [$simulacion, $criterio] = $this->prepararSimulacion();
        $evaluacion = app(SolicitarEvaluacion::class)->ejecutar($simulacion);
        config(['services.intelligence.url' => 'http://localhost:8100']);
        config(['services.intelligence.token' => 'token-de-prueba']);
        Http::fake([
            'http://localhost:8100/api/v1/evaluations/evaluate' => Http::response([
                'data' => [
                    'summary' => 'Resumen de prueba.',
                    'criteria' => [[
                        'criterion_id' => $criterio->getKey(),
                        'score' => 7,
                        'feedback' => 'Comentario de prueba.',
                        'strengths' => [[
                            'content' => 'Observación no sustentada.',
                            'evidence' => ['intervention_order' => 1, 'quote' => 'Texto inventado.'],
                        ]],
                        'errors' => [],
                        'recommendations' => [],
                    ]],
                    'requires_human_review' => true,
                ],
            ], 200, [
                'X-Jurissim-Evaluation-Provider' => 'ollama',
                'X-Jurissim-Evaluation-Model' => 'qwen3.5:2b-q4_K_M',
            ]),
        ]);

        try {
            (new EvaluarSimulacion($evaluacion->getKey()))->handle();
            $this->fail('El trabajo debió rechazar evidencia que no existe en la intervención.');
        } catch (\UnexpectedValueException) {
            (new EvaluarSimulacion($evaluacion->getKey()))->failed(new \RuntimeException('safe failure'));
        }

        $this->assertSame('error', $evaluacion->fresh()->estado);
        $this->assertSame('No se pudo completar la evaluación local.', $evaluacion->fresh()->datos_evaluacion['error']);
        $this->assertDatabaseCount('resultados_evaluacion', 0);
    }

    /** @return array{0: Simulacion, 1: CriterioRubrica} */
    private function prepararSimulacion(): array
    {
        $simulacion = Simulacion::factory()->create();
        $etapa = EtapaAudiencia::query()->create([
            'id_tipo_audiencia' => $simulacion->id_tipo_audiencia,
            'codigo' => 'fase-trabajo-evaluacion-'.$simulacion->getKey(),
            'nombre' => 'Etapa final de evaluación',
            'orden' => 1,
            'es_final' => true,
            'activo' => true,
        ]);
        $simulacion->update([
            'id_etapa_actual' => $etapa->getKey(),
            'estado' => 'finalizada',
            'fecha_inicio' => now()->subMinute(),
            'fecha_fin' => now(),
        ]);
        $defensa = $simulacion->participantes()->create([
            'id_analisis' => $simulacion->id_analisis,
            'id_expediente' => $simulacion->id_expediente,
            'rol' => 'abogado_defensor',
            'nombre_mostrado' => 'Defensa de prueba',
            'controlado_por' => 'usuario',
            'estado' => 'activo',
        ]);
        $intervencion = $simulacion->intervenciones()->create([
            'id_participante_simulacion' => $defensa->getKey(),
            'id_tipo_audiencia' => $simulacion->id_tipo_audiencia,
            'id_etapa' => $etapa->getKey(),
            'orden' => 1,
            'contenido' => 'Solicito que se valore la petición por el arraigo demostrado.',
            'tipo_entrada' => 'texto',
        ]);
        $archivo = $simulacion->expediente->archivos()->create([
            'nombre_original' => 'certificado-sintetico.pdf',
            'nombre_almacenado' => 'certificado-sintetico.pdf',
            'tipo_mime' => 'application/pdf',
            'extension' => 'pdf',
            'disco' => 'local',
            'ruta_almacenamiento' => 'expedientes/'.$simulacion->id_expediente.'/certificado-sintetico.pdf',
            'tamano_bytes' => 128,
            'estado_procesamiento' => 'error',
        ]);
        $pagina = $archivo->paginas()->create([
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => 'El certificado laboral indica empleo continuo desde marzo.',
            'es_legible' => true,
        ]);
        $fragmento = FragmentoDocumento::query()->create([
            'id_archivo' => $archivo->getKey(),
            'id_pagina' => $pagina->getKey(),
            'contenido' => 'El certificado laboral indica empleo continuo.',
            'numero_pagina' => 1,
            'indice_fragmento' => 0,
        ]);
        $intervencion->fuentes()->create([
            'id_fragmento' => $fragmento->getKey(),
            'fragmento_utilizado' => $fragmento->contenido,
            'metadatos' => [
                'tipo_fuente' => 'expediente',
                'titulo' => 'Certificado laboral sintético',
                'localizador' => 'Página 1',
            ],
        ]);
        $rubrica = Rubrica::query()->create([
            'nombre' => 'Rúbrica de prueba '.$simulacion->getKey(),
            'rol_aplicable' => 'abogado_defensor',
            'id_tipo_audiencia' => $simulacion->id_tipo_audiencia,
            'version' => 1,
            'activo' => true,
        ]);
        $criterio = $rubrica->criterios()->create([
            'nombre' => 'Claridad',
            'descripcion' => 'Expone la petición de forma comprensible.',
            'peso' => 1,
            'puntaje_maximo' => 10,
            'orden' => 1,
        ]);

        return [$simulacion->fresh(), $criterio];
    }
}
