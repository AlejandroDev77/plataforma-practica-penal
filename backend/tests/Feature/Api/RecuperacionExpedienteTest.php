<?php

namespace Tests\Feature\Api;

use App\Jobs\IndexarArchivoExpediente;
use App\Models\ArchivoExpediente;
use App\Models\Expediente;
use App\Models\FragmentoDocumento;
use App\Models\PaginaExpediente;
use App\Models\User;
use App\Services\Recuperacion\FragmentadorTextoExpediente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecuperacionExpedienteTest extends TestCase
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

    public function test_indexing_saves_only_readable_page_fragments_with_traceable_locations(): void
    {
        $owner = User::factory()->create();
        $expediente = $owner->expedientes()->create(['titulo' => 'Caso sintético']);
        $archivo = $this->createArchivo($expediente, 'declaracion.pdf');
        $texto = str_repeat('La medida cautelar fue revisada por el juzgado. ', 80);
        $pagina = $archivo->paginas()->create([
            'numero_pagina' => 2,
            'localizador' => 'Página 2',
            'texto_extraido' => $texto,
            'es_legible' => true,
        ]);
        $archivo->paginas()->create([
            'numero_pagina' => 3,
            'localizador' => 'Página 3',
            'texto_extraido' => 'Texto no legible que no debe indexarse.',
            'es_legible' => false,
        ]);

        (new IndexarArchivoExpediente($archivo->getKey()))->handle(new FragmentadorTextoExpediente);

        $this->assertGreaterThan(1, FragmentoDocumento::query()->where('id_archivo', $archivo->getKey())->count());
        $this->assertDatabaseHas('fragmentos_documento', [
            'id_archivo' => $archivo->getKey(),
            'id_pagina' => $pagina->getKey(),
            'numero_pagina' => 2,
            'indice_fragmento' => 0,
        ]);
        $this->assertDatabaseMissing('fragmentos_documento', [
            'id_archivo' => $archivo->getKey(),
            'numero_pagina' => 3,
        ]);
        $this->assertDatabaseHas('historial_procesamiento', [
            'id_expediente' => $expediente->getKey(),
            'id_archivo' => $archivo->getKey(),
            'tipo' => 'indexacion_rag',
            'estado' => 'procesado',
        ]);
    }

    public function test_search_returns_ranked_quotes_from_the_owned_case_only(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expediente = $owner->expedientes()->create(['titulo' => 'Caso privado']);
        $foreignExpediente = $other->expedientes()->create(['titulo' => 'Caso ajeno']);
        $archivo = $this->createArchivo($expediente, 'propio.pdf');
        $pagina = $archivo->paginas()->create([
            'numero_pagina' => 4,
            'localizador' => 'Página 4',
            'texto_extraido' => 'El juez dispuso detención preventiva para el imputado.',
            'es_legible' => true,
        ]);
        $this->createFragmento($archivo, $pagina, 'El juez dispuso detención preventiva para el imputado.');

        $archivoAjeno = $this->createArchivo($foreignExpediente, 'ajeno.pdf');
        $paginaAjena = $archivoAjeno->paginas()->create([
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => 'Detención preventiva descrita en expediente ajeno.',
            'es_legible' => true,
        ]);
        $this->createFragmento($archivoAjeno, $paginaAjena, 'Detención preventiva descrita en expediente ajeno.');

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/expedientes/'.$expediente->getKey().'/recuperacion', [
            'consulta' => 'detención preventiva',
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fuente.archivo', 'propio.pdf')
            ->assertJsonPath('data.0.fuente.localizador', 'Página 4')
            ->assertJsonPath('meta.fragmentos_indexados', 1)
            ->assertJsonPath('meta.relevancia_es_certeza', false);

        Sanctum::actingAs($other);
        $this->postJson('/api/v1/expedientes/'.$expediente->getKey().'/recuperacion', [
            'consulta' => 'detención preventiva',
        ])->assertNotFound();
    }

    public function test_owner_can_enqueue_reindexing_only_for_readable_files_in_the_case(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expediente = $owner->expedientes()->create(['titulo' => 'Caso propio']);
        $foreign = $other->expedientes()->create(['titulo' => 'Caso ajeno']);
        $archivo = $this->createArchivo($expediente, 'legible.pdf');
        $archivo->paginas()->create([
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => 'Texto legible para una indexación de prueba.',
            'es_legible' => true,
        ]);
        $sinTexto = $this->createArchivo($expediente, 'vacio.pdf');
        $sinTexto->paginas()->create([
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => '   ',
            'es_legible' => true,
        ]);
        $archivoAjeno = $this->createArchivo($foreign, 'ajeno.pdf');
        $archivoAjeno->paginas()->create([
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => 'Texto fuera del expediente autorizado.',
            'es_legible' => true,
        ]);

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/expedientes/'.$expediente->getKey().'/recuperacion/indexar')
            ->assertAccepted()
            ->assertJsonPath('data.archivos_en_cola', 1)
            ->assertJsonPath('data.estado', 'encolado');

        Queue::assertPushed(IndexarArchivoExpediente::class, 1);
        Queue::assertPushed(IndexarArchivoExpediente::class, fn (IndexarArchivoExpediente $job): bool => $job->archivoId === $archivo->getKey());
    }

    private function createArchivo(Expediente $expediente, string $nombre): ArchivoExpediente
    {
        return $expediente->archivos()->create([
            'nombre_original' => $nombre,
            'nombre_almacenado' => $nombre,
            'tipo_mime' => 'application/pdf',
            'extension' => 'pdf',
            'disco' => 'local',
            'ruta_almacenamiento' => 'expedientes/'.$expediente->getKey().'/'.$nombre,
            'tamano_bytes' => 100,
            'estado_procesamiento' => 'procesado',
        ]);
    }

    private function createFragmento(ArchivoExpediente $archivo, PaginaExpediente $pagina, string $contenido): void
    {
        $archivo->fragmentos()->create([
            'id_pagina' => $pagina->getKey(),
            'contenido' => $contenido,
            'numero_pagina' => $pagina->numero_pagina,
            'indice_fragmento' => 0,
        ]);
    }
}
