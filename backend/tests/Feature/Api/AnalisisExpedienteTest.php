<?php

namespace Tests\Feature\Api;

use App\Models\Expediente;
use App\Models\PaginaExpediente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalisisExpedienteTest extends TestCase
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

    public function test_internal_persistence_validates_grounding_and_creates_versioned_normalized_records(): void
    {
        config(['services.intelligence.token' => 'token-sintetico']);
        $owner = User::factory()->create();
        $expediente = $this->createExpediente($owner, 'Caso de prueba');
        $pagina = $this->createPagina($expediente, 'La señora Ana presentó denuncia el 27 de septiembre de 2026.');

        $this->postJson('/api/internal/v1/expedientes/'.$expediente->getKey().'/analisis', $this->resultado($pagina, 'Ana presentó denuncia'))
            ->assertUnauthorized();

        $saved = $this->withHeader('Authorization', 'Bearer token-sintetico')
            ->postJson('/api/internal/v1/expedientes/'.$expediente->getKey().'/analisis', $this->resultado($pagina, 'Ana presentó denuncia'))
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'procesado');

        $analysisId = $saved->json('data.id');
        $this->assertDatabaseHas('analisis_expediente', [
            'id_analisis' => $analysisId,
            'id_expediente' => $expediente->getKey(),
            'version' => 1,
            'modelo_ia' => null,
            'estado' => 'procesado',
        ]);
        $this->assertDatabaseHas('participantes_expediente', [
            'id_analisis' => $analysisId,
            'nombre' => 'Ana',
            'confirmado' => false,
            'nivel_confianza' => null,
        ]);
        $this->assertDatabaseHas('hechos_expediente', [
            'id_analisis' => $analysisId,
            'tipo_hecho' => 'declaracion',
            'fecha_hecho' => '2026-09-27',
            'confirmado' => false,
        ]);
        $this->assertDatabaseHas('historial_procesamiento', [
            'id_analisis' => $analysisId,
            'tipo' => 'analisis_estructurado',
            'estado' => 'procesado',
        ]);
        $this->assertDatabaseCount('referencias_expediente', 3);

        $this->withHeader('Authorization', 'Bearer token-sintetico')
            ->postJson('/api/internal/v1/expedientes/'.$expediente->getKey().'/analisis', $this->resultado($pagina, 'Ana presentó denuncia'))
            ->assertCreated()
            ->assertJsonPath('data.version', 2);
    }

    public function test_internal_persistence_rejects_citations_from_another_case_and_unmatched_excerpts_atomically(): void
    {
        config(['services.intelligence.token' => 'token-sintetico']);
        $owner = User::factory()->create();
        $case = $this->createExpediente($owner, 'Caso propio');
        $otherCase = $this->createExpediente($owner, 'Otro caso');
        $foreignPage = $this->createPagina($otherCase, 'Contenido reservado de otro expediente.');
        $ownPage = $this->createPagina($case, 'Contenido propio de este expediente.');
        $headers = ['Authorization' => 'Bearer token-sintetico'];

        $this->postJson('/api/internal/v1/expedientes/'.$case->getKey().'/analisis', $this->resultado($foreignPage, 'Contenido reservado'), $headers)
            ->assertUnprocessable();
        $this->postJson('/api/internal/v1/expedientes/'.$case->getKey().'/analisis', $this->resultado($ownPage, 'texto que no existe'), $headers)
            ->assertUnprocessable();

        $this->assertDatabaseCount('analisis_expediente', 0);
        $this->assertDatabaseCount('participantes_expediente', 0);
    }

    public function test_owner_can_review_analysis_but_approval_requires_available_citations_and_other_users_get_not_found(): void
    {
        config(['services.intelligence.token' => 'token-sintetico']);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expediente = $this->createExpediente($owner, 'Caso privado');
        $pagina = $this->createPagina($expediente, 'La declaración ubica a Ana en el domicilio.');
        $saved = $this->withHeader('Authorization', 'Bearer token-sintetico')
            ->postJson('/api/internal/v1/expedientes/'.$expediente->getKey().'/analisis', $this->resultado($pagina, 'ubica a Ana'))
            ->assertCreated();
        $analysisId = $saved->json('data.id');

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/expedientes/'.$expediente->getKey().'/analisis')->assertNotFound();
        $this->postJson('/api/v1/expedientes/'.$expediente->getKey().'/analisis/'.$analysisId.'/revisiones', [
            'decision' => 'aprobado',
        ])->assertNotFound();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/expedientes/'.$expediente->getKey().'/analisis')
            ->assertOk()
            ->assertJsonPath('data.review_state', 'pendiente')
            ->assertJsonPath('data.summary.sources.0.available', true)
            ->assertJsonMissingPath('data.summary.sources.0.page_id')
            ->assertJsonPath('data.participants.0.name_as_written', 'Ana');

        $this->postJson('/api/v1/expedientes/'.$expediente->getKey().'/analisis/'.$analysisId.'/revisiones', [
            'decision' => 'requiere_cambios',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/expedientes/'.$expediente->getKey().'/analisis/'.$analysisId.'/revisiones', [
            'decision' => 'requiere_cambios',
            'observacion' => 'Verificar la identificación con el documento fuente.',
        ])->assertCreated()->assertJsonPath('data.review_state', 'requiere_cambios');

        PaginaExpediente::query()->whereKey($pagina->getKey())->delete();
        $this->postJson('/api/v1/expedientes/'.$expediente->getKey().'/analisis/'.$analysisId.'/revisiones', [
            'decision' => 'aprobado',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('revisiones_analisis', 1);
    }

    public function test_removing_a_source_file_also_removes_derived_analyses_that_cite_it(): void
    {
        config(['services.intelligence.token' => 'token-sintetico']);
        Storage::fake('local');
        $owner = User::factory()->create();
        $expediente = $this->createExpediente($owner, 'Caso para retiro');
        $path = 'expedientes/'.$expediente->getKey().'/sintetico.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\ntexto sintético\n%%EOF");
        $file = $expediente->archivos()->create([
            'nombre_original' => 'sintetico.pdf',
            'nombre_almacenado' => 'sintetico.pdf',
            'tipo_mime' => 'application/pdf',
            'extension' => 'pdf',
            'disco' => 'local',
            'ruta_almacenamiento' => $path,
            'tamano_bytes' => 32,
            'estado_procesamiento' => 'procesado',
        ]);
        $pagina = $file->paginas()->create([
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => 'La declaración sintética identifica a Ana.',
            'es_legible' => true,
        ]);
        $this->withHeader('Authorization', 'Bearer token-sintetico')
            ->postJson('/api/internal/v1/expedientes/'.$expediente->getKey().'/analisis', $this->resultado($pagina, 'identifica a Ana'))
            ->assertCreated();

        Sanctum::actingAs($owner);
        $this->deleteJson('/api/v1/expedientes/'.$expediente->getKey().'/archivos/'.$file->getKey())->assertNoContent();

        $this->assertDatabaseCount('analisis_expediente', 0);
        $this->assertDatabaseCount('revisiones_analisis', 0);
        $this->assertDatabaseMissing('archivos_expediente', ['id_archivo' => $file->getKey()]);
        Storage::disk('local')->assertMissing($path);
    }

    private function createExpediente(User $owner, string $title): Expediente
    {
        return $owner->expedientes()->create(['titulo' => $title]);
    }

    private function createPagina(Expediente $expediente, string $text): PaginaExpediente
    {
        $file = $expediente->archivos()->create([
            'nombre_original' => 'declaracion.pdf',
            'nombre_almacenado' => 'declaracion.pdf',
            'tipo_mime' => 'application/pdf',
            'extension' => 'pdf',
            'disco' => 'local',
            'ruta_almacenamiento' => 'expedientes/'.$expediente->getKey().'/declaracion.pdf',
            'tamano_bytes' => 40,
            'estado_procesamiento' => 'procesado',
        ]);

        return $file->paginas()->create([
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => $text,
            'es_legible' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function resultado(PaginaExpediente $pagina, string $excerpt): array
    {
        $cita = ['page_id' => $pagina->getKey(), 'excerpt' => $excerpt];

        return [
            'result' => [
                'schema_version' => '1.0',
                'summary' => ['text' => 'La declaración ubica a Ana.', 'certainty' => 'textual', 'sources' => [$cita]],
                'procedural_stage' => null,
                'participants' => [[
                    'name_as_written' => 'Ana',
                    'role_as_written' => 'declarante',
                    'description' => 'Identidad mencionada en la declaración.',
                    'certainty' => 'textual',
                    'sources' => [$cita],
                ]],
                'offenses' => [],
                'facts' => [[
                    'description' => 'La declaración ubica a Ana en el relato.',
                    'kind' => 'declaracion',
                    'date_as_written' => '2026-09-27',
                    'certainty' => 'textual',
                    'sources' => [$cita],
                ]],
                'evidence' => [],
                'chronology' => [],
                'missing_information' => [[
                    'question' => '¿Consta una identificación adicional?',
                    'relevance' => 'La referencia no basta para confirmar identidad.',
                    'context_sources' => [$cita],
                ]],
                'uncertainties' => [],
            ],
        ];
    }
}
