<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcesarArchivoExpediente;
use App\Models\ArchivoExpediente;
use App\Models\Expediente;
use App\Models\HistorialProcesamiento;
use App\Models\ObjetoPendienteEliminacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpedienteTest extends TestCase
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

    public function test_expediente_endpoints_require_an_authenticated_session(): void
    {
        $this->getJson('/api/v1/expedientes')->assertUnauthorized();
        $this->postJson('/api/v1/expedientes', ['titulo' => 'Sin sesión'])->assertUnauthorized();
    }

    public function test_expedientes_are_private_to_the_authenticated_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owned = $this->createExpediente($owner, 'Mi caso');
        $private = $this->createExpediente($other, 'Caso ajeno');
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/expedientes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mi caso');

        $this->getJson('/api/v1/expedientes/'.$owned->getKey())
            ->assertOk()
            ->assertJsonPath('data.file_count', 0);
        $this->getJson('/api/v1/expedientes/'.$private->getKey())->assertNotFound();
        $this->patchJson('/api/v1/expedientes/'.$private->getKey(), ['titulo' => 'Tomado'])->assertNotFound();
        $this->deleteJson('/api/v1/expedientes/'.$private->getKey())->assertNotFound();

        $this->assertSame('Caso ajeno', $private->fresh()->titulo);
        $this->assertSame($owner->id, $owned->fresh()->id_usuario);
    }

    public function test_creating_and_updating_an_expediente_never_accepts_client_ownership_or_state(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($owner);

        $created = $this->postJson('/api/v1/expedientes', [
            'titulo' => '  Carpeta de práctica  ',
            'descripcion' => '  Medidas cautelares  ',
            'numero_caso' => '  C-2026-01  ',
            'id_usuario' => $other->id,
            'estado' => 'archivado',
            'estado_procesamiento' => 'procesado',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Carpeta de práctica')
            ->assertJsonPath('data.description', 'Medidas cautelares')
            ->assertJsonPath('data.case_number', 'C-2026-01')
            ->assertJsonPath('data.status', 'borrador')
            ->assertJsonPath('data.processing_status', 'pendiente')
            ->assertJsonPath('data.file_count', 0);

        $id = $created->json('data.id');
        $this->assertSame($owner->id, Expediente::findOrFail($id)->id_usuario);

        $this->patchJson('/api/v1/expedientes/'.$id, ['titulo' => 'Carpeta actualizada'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Carpeta actualizada');

        $this->assertSame('borrador', Expediente::findOrFail($id)->estado);
    }

    public function test_listing_supports_owner_scoped_title_and_case_number_search(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->createExpediente($owner, 'Denuncia de práctica', 'FIS-004');
        $this->createExpediente($owner, 'Audiencia inicial', 'JUZ-011');
        $this->createExpediente($other, 'Denuncia privada', 'FIS-999');
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/expedientes?search=FIS-004')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.case_number', 'FIS-004');
    }

    public function test_upload_stores_multiple_allowed_files_privately_and_returns_only_safe_metadata(): void
    {
        Queue::fake();
        Storage::fake('local');
        $owner = User::factory()->create();
        $expediente = $this->createExpediente($owner, 'Caso con documentos');
        Sanctum::actingAs($owner);
        $pdf = UploadedFile::fake()->createWithContent('memorial.pdf', "%PDF-1.4\ncontenido de prueba\n%%EOF");
        $image = UploadedFile::fake()->image('sello.png', 20, 20);

        $response = $this->post('/api/v1/expedientes/'.$expediente->getKey().'/archivos', [
            'archivos' => [$pdf, $image],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonCount(2, 'data');
        Queue::assertPushed(ProcesarArchivoExpediente::class, 2);
        $this->assertDatabaseCount('archivos_expediente', 2);
        $response->assertJsonMissingPath('data.0.storage_path');
        $response->assertJsonMissingPath('data.0.disk');

        foreach (ArchivoExpediente::query()->get() as $archivo) {
            $this->assertStringStartsWith('expedientes/'.$expediente->getKey().'/', $archivo->ruta_almacenamiento);
            Storage::disk('local')->assertExists($archivo->ruta_almacenamiento);
        }
    }

    public function test_upload_rejects_unsupported_formats_and_files_from_another_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->createExpediente($owner, 'Mi expediente');
        $theirs = $this->createExpediente($other, 'Privado');
        Sanctum::actingAs($owner);

        $this->post('/api/v1/expedientes/'.$mine->getKey().'/archivos', [
            'archivos' => [UploadedFile::fake()->createWithContent('script.php', '<?php echo "no";')],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->post('/api/v1/expedientes/'.$theirs->getKey().'/archivos', [
            'archivos' => [UploadedFile::fake()->createWithContent('memorial.pdf', "%PDF-1.4\ncontenido\n%%EOF")],
        ], ['Accept' => 'application/json'])->assertNotFound();

        $this->assertDatabaseCount('archivos_expediente', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_valid_docx_document_is_accepted_when_zip_support_is_available(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('La verificación del contenedor DOCX requiere ext-zip.');
        }

        Queue::fake();
        Storage::fake('local');
        $owner = User::factory()->create();
        $expediente = $this->createExpediente($owner, 'DOCX válido');
        $path = tempnam(sys_get_temp_dir(), 'jurissim-docx-');
        $archive = new \ZipArchive;
        $this->assertSame(true, $archive->open($path, \ZipArchive::OVERWRITE));
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $archive->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
        $archive->close();
        $upload = new UploadedFile($path, 'practica.docx', null, null, true);

        try {
            Sanctum::actingAs($owner);
            $this->post('/api/v1/expedientes/'.$expediente->getKey().'/archivos', [
                'archivos' => [$upload],
            ], ['Accept' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('data.0.extension', 'docx');
            Queue::assertPushed(ProcesarArchivoExpediente::class);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_file_download_and_removal_are_owner_scoped_and_cleanup_private_objects(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expediente = $this->createExpediente($owner, 'Caso propio');
        $another = $this->createExpediente($other, 'Caso ajeno');
        $path = 'expedientes/'.$expediente->getKey().'/'.fake()->uuid().'.pdf';
        $otherPath = 'expedientes/'.$another->getKey().'/'.fake()->uuid().'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nmi archivo\n%%EOF");
        Storage::disk('local')->put($otherPath, "%PDF-1.4\narchivo ajeno\n%%EOF");
        $file = $expediente->archivos()->create($this->fileAttributes($path, 'mi-archivo.pdf'));
        $otherFile = $another->archivos()->create($this->fileAttributes($otherPath, 'ajeno.pdf'));
        Sanctum::actingAs($owner);

        $this->get('/api/v1/expedientes/'.$expediente->getKey().'/archivos/'.$file->getKey().'/descarga')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=mi-archivo.pdf');
        $this->getJson('/api/v1/expedientes/'.$expediente->getKey().'/archivos/'.$otherFile->getKey().'/descarga')->assertNotFound();

        $this->deleteJson('/api/v1/expedientes/'.$expediente->getKey().'/archivos/'.$file->getKey())->assertNoContent();
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('procesado', ObjetoPendienteEliminacion::query()->where('ruta_almacenamiento', $path)->value('estado'));
        $this->assertDatabaseHas('archivos_expediente', ['id_archivo' => $otherFile->getKey()]);
    }

    public function test_document_job_stores_page_text_and_finishes_processing_history(): void
    {
        Storage::fake('local');
        config([
            'services.intelligence.url' => 'http://localhost:8100',
            'services.intelligence.token' => 'prueba-interna',
            'services.intelligence.timeout' => 20,
        ]);
        $owner = User::factory()->create();
        $expediente = $this->createExpediente($owner, 'Extracción de prueba');
        $path = 'expedientes/'.$expediente->getKey().'/actuacion.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\ntexto\n%%EOF");
        $archivo = $expediente->archivos()->create($this->fileAttributes($path, 'actuacion.pdf'));
        Http::fake([
            'http://localhost:8100/api/v1/documents/extract' => Http::response([
                'document_type' => 'pdf',
                'page_count' => 1,
                'pages' => [[
                    'page_number' => 1,
                    'locator' => 'Página 1',
                    'text' => 'Se solicita la aplicación de medidas cautelares.',
                    'used_ocr' => false,
                    'confidence' => null,
                    'is_readable' => true,
                ]],
                'warnings' => [],
            ]),
        ]);

        (new ProcesarArchivoExpediente($archivo->getKey()))->handle();

        $this->assertSame('procesado', $archivo->fresh()->estado_procesamiento);
        $this->assertSame('procesado', $expediente->fresh()->estado_procesamiento);
        $this->assertDatabaseHas('paginas_expediente', [
            'id_archivo' => $archivo->getKey(),
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => 'Se solicita la aplicación de medidas cautelares.',
            'es_legible' => true,
        ]);
        $this->assertSame('procesado', HistorialProcesamiento::query()
            ->where('tipo', 'extraccion_texto')
            ->sole()
            ->estado);
        $this->assertSame('procesado', HistorialProcesamiento::query()
            ->where('tipo', 'indexacion_rag')
            ->sole()
            ->estado);
        $this->assertDatabaseHas('fragmentos_documento', [
            'id_archivo' => $archivo->getKey(),
            'contenido' => 'Se solicita la aplicación de medidas cautelares.',
        ]);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer prueba-interna'));
    }

    public function test_extracted_pages_are_visible_only_to_the_expediente_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expediente = $this->createExpediente($owner, 'Texto extraído');
        $otherCase = $this->createExpediente($other, 'Otro expediente');
        $archivo = $expediente->archivos()->create($this->fileAttributes('privado.pdf', 'privado.pdf'));
        $archivo->paginas()->create([
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => 'Contenido reservado del expediente.',
            'es_legible' => true,
        ]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/expedientes/'.$expediente->getKey().'/archivos/'.$archivo->getKey().'/paginas')
            ->assertOk()
            ->assertJsonPath('data.0.locator', 'Página 1')
            ->assertJsonPath('data.0.text', 'Contenido reservado del expediente.')
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/expedientes/'.$otherCase->getKey().'/archivos/'.$archivo->getKey().'/paginas')
            ->assertNotFound();
    }

    public function test_deleting_an_expediente_removes_its_private_files_and_leaves_other_users_untouched(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expediente = $this->createExpediente($owner, 'Eliminar');
        $otherCase = $this->createExpediente($other, 'Conservar');
        $path = 'expedientes/'.$expediente->getKey().'/'.fake()->uuid().'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nprivado\n%%EOF");
        $expediente->archivos()->create($this->fileAttributes($path, 'privado.pdf'));
        Sanctum::actingAs($owner);

        $this->deleteJson('/api/v1/expedientes/'.$expediente->getKey())->assertNoContent();

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('expedientes', ['id_expediente' => $expediente->getKey()]);
        $this->assertDatabaseHas('expedientes', ['id_expediente' => $otherCase->getKey()]);
    }

    private function createExpediente(User $owner, string $title, ?string $caseNumber = null): Expediente
    {
        return $owner->expedientes()->create([
            'titulo' => $title,
            'numero_caso' => $caseNumber,
        ]);
    }

    /** @return array<string, mixed> */
    private function fileAttributes(string $path, string $originalName): array
    {
        return [
            'nombre_original' => $originalName,
            'nombre_almacenado' => basename($path),
            'tipo_mime' => 'application/pdf',
            'extension' => 'pdf',
            'disco' => 'local',
            'ruta_almacenamiento' => $path,
            'tamano_bytes' => 32,
            'estado_procesamiento' => 'pendiente',
        ];
    }
}
