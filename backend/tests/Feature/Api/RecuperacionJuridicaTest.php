<?php

namespace Tests\Feature\Api;

use App\Jobs\IndexarFuenteJuridica;
use App\Models\ArchivoExpediente;
use App\Models\Expediente;
use App\Models\FuenteJuridica;
use App\Models\User;
use App\Services\Recuperacion\FragmentadorTexto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecuperacionJuridicaTest extends TestCase
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

    public function test_legal_indexing_creates_fragments_only_for_validated_current_sources(): void
    {
        $vigente = $this->createFuente([
            'titulo' => 'Norma sintética vigente',
            'contenido_original' => 'Artículo 12. La detención preventiva requiere una resolución fundamentada.',
        ]);
        $borrador = $this->createFuente([
            'titulo' => 'Norma sintética en borrador',
            'estado' => 'borrador',
            'validada' => false,
            'contenido_original' => 'Artículo 12. Detención preventiva en texto no validado.',
        ]);
        $vencida = $this->createFuente([
            'titulo' => 'Norma sintética vencida',
            'fecha_fin_vigencia' => today()->subDay()->toDateString(),
            'contenido_original' => 'Artículo 12. Detención preventiva en versión vencida.',
        ]);

        (new IndexarFuenteJuridica($vigente->getKey()))->handle(new FragmentadorTexto);
        (new IndexarFuenteJuridica($borrador->getKey()))->handle(new FragmentadorTexto);
        (new IndexarFuenteJuridica($vencida->getKey()))->handle(new FragmentadorTexto);

        $this->assertDatabaseHas('fragmentos_documento', [
            'id_fuente_juridica' => $vigente->getKey(),
            'indice_fragmento' => 0,
            'contenido' => 'Artículo 12. La detención preventiva requiere una resolución fundamentada.',
        ]);
        $this->assertDatabaseMissing('fragmentos_documento', ['id_fuente_juridica' => $borrador->getKey()]);
        $this->assertDatabaseMissing('fragmentos_documento', ['id_fuente_juridica' => $vencida->getKey()]);
    }

    public function test_legal_search_is_separate_and_excludes_drafts_expired_sources_and_case_text(): void
    {
        $vigente = $this->createFuente([
            'titulo' => 'Norma sintética vigente',
            'numero_norma' => 'Norma de prueba 1',
            'contenido_original' => 'Artículo 12. La detención preventiva requiere resolución fundamentada.',
        ]);
        $borrador = $this->createFuente([
            'titulo' => 'Norma sintética borrador',
            'estado' => 'borrador',
            'validada' => false,
        ]);
        $vencida = $this->createFuente([
            'titulo' => 'Norma sintética vencida',
            'fecha_fin_vigencia' => today()->subDay()->toDateString(),
        ]);
        (new IndexarFuenteJuridica($vigente->getKey()))->handle(new FragmentadorTexto);
        $borrador->fragmentos()->create([
            'contenido' => 'La detención preventiva aparece en una fuente no validada.',
            'indice_fragmento' => 0,
        ]);
        $vencida->fragmentos()->create([
            'contenido' => 'La detención preventiva aparece en una fuente vencida.',
            'indice_fragmento' => 0,
        ]);

        $owner = User::factory()->create();
        $expediente = $owner->expedientes()->create(['titulo' => 'Caso privado']);
        $archivo = $this->createArchivo($expediente);
        $pagina = $archivo->paginas()->create([
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => 'Detención preventiva mencionada en un caso privado.',
            'es_legible' => true,
        ]);
        $archivo->fragmentos()->create([
            'id_pagina' => $pagina->getKey(),
            'contenido' => 'Detención preventiva mencionada en un caso privado.',
            'numero_pagina' => 1,
            'indice_fragmento' => 0,
        ]);

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/recuperacion-juridica', [
            'consulta' => 'detención preventiva',
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fuente.titulo', 'Norma sintética vigente')
            ->assertJsonPath('data.0.fuente.numero_norma', 'Norma de prueba 1')
            ->assertJsonPath('meta.fuentes_vigentes_indexadas', 1)
            ->assertJsonMissing(['titulo' => 'Norma sintética borrador'])
            ->assertJsonMissing(['titulo' => 'Norma sintética vencida'])
            ->assertJsonMissing(['archivo' => 'caso-privado.pdf']);
    }

    public function test_internal_route_requires_token_and_enqueues_only_an_eligible_source(): void
    {
        Queue::fake();
        config(['services.intelligence.token' => 'token-sintetico']);
        $vigente = $this->createFuente();
        $noValidada = $this->createFuente([
            'validada' => false,
            'estado' => 'borrador',
        ]);

        $this->postJson('/api/internal/v1/fuentes-juridicas/'.$vigente->getKey().'/indexar')
            ->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer token-sintetico')
            ->postJson('/api/internal/v1/fuentes-juridicas/'.$vigente->getKey().'/indexar')
            ->assertAccepted()
            ->assertJsonPath('data.estado', 'encolado');
        $this->withHeader('Authorization', 'Bearer token-sintetico')
            ->postJson('/api/internal/v1/fuentes-juridicas/'.$noValidada->getKey().'/indexar')
            ->assertStatus(409);

        Queue::assertPushed(IndexarFuenteJuridica::class, 1);
        Queue::assertPushed(IndexarFuenteJuridica::class, fn (IndexarFuenteJuridica $job): bool => $job->fuenteId === $vigente->getKey());
    }

    public function test_legal_search_does_not_return_fragments_after_source_content_changes(): void
    {
        $fuente = $this->createFuente([
            'contenido_original' => 'Artículo 12. La detención preventiva requiere resolución fundamentada.',
        ]);
        (new IndexarFuenteJuridica($fuente->getKey()))->handle(new FragmentadorTexto);
        $fuente->update([
            'contenido_original' => 'Artículo 12. Texto reemplazado que ya no menciona la detención.',
        ]);
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/recuperacion-juridica', [
            'consulta' => 'detención',
        ])->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.fragmentos_disponibles', 0);
    }

    /** @param array<string, mixed> $overrides */
    private function createFuente(array $overrides = []): FuenteJuridica
    {
        return FuenteJuridica::query()->create(array_merge([
            'titulo' => 'Norma sintética validada',
            'tipo_fuente' => 'ley',
            'numero_norma' => 'Norma de prueba',
            'version' => '2026-01',
            'fecha_vigencia' => today()->subMonth()->toDateString(),
            'fecha_fin_vigencia' => null,
            'contenido_original' => 'Artículo 1. Texto de prueba para recuperación jurídica.',
            'estado' => 'vigente',
            'validada' => true,
        ], $overrides));
    }

    private function createArchivo(Expediente $expediente): ArchivoExpediente
    {
        return $expediente->archivos()->create([
            'nombre_original' => 'caso-privado.pdf',
            'nombre_almacenado' => 'caso-privado.pdf',
            'tipo_mime' => 'application/pdf',
            'extension' => 'pdf',
            'disco' => 'local',
            'ruta_almacenamiento' => 'expedientes/'.$expediente->getKey().'/caso-privado.pdf',
            'tamano_bytes' => 100,
            'estado_procesamiento' => 'procesado',
        ]);
    }
}
