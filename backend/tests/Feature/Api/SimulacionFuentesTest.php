<?php

namespace Tests\Feature\Api;

use App\Jobs\IndexarArchivoExpediente;
use App\Jobs\IndexarFuenteJuridica;
use App\Models\FuenteJuridica;
use App\Models\Simulacion;
use App\Models\User;
use App\Services\Recuperacion\FragmentadorTexto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SimulacionFuentesTest extends TestCase
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

    public function test_simulation_source_search_returns_separate_case_and_current_legal_candidates(): void
    {
        $simulacion = Simulacion::factory()->create();
        $owner = $simulacion->usuario;
        $archivo = $simulacion->expediente->archivos()->create([
            'nombre_original' => 'audiencia.pdf',
            'nombre_almacenado' => 'audiencia.pdf',
            'tipo_mime' => 'application/pdf',
            'extension' => 'pdf',
            'disco' => 'local',
            'ruta_almacenamiento' => 'expedientes/'.$simulacion->id_expediente.'/audiencia.pdf',
            'tamano_bytes' => 128,
            'estado_procesamiento' => 'procesado',
        ]);
        $archivo->paginas()->create([
            'numero_pagina' => 4,
            'localizador' => 'Página 4',
            'texto_extraido' => 'La detención preventiva requiere motivación suficiente según el expediente.',
            'es_legible' => true,
        ]);
        (new IndexarArchivoExpediente($archivo->getKey()))->handle(new FragmentadorTexto);

        $expedienteAjeno = $owner->expedientes()->create(['titulo' => 'Otro expediente propio']);
        $archivoAjeno = $expedienteAjeno->archivos()->create([
            'nombre_original' => 'otro.pdf',
            'nombre_almacenado' => 'otro.pdf',
            'tipo_mime' => 'application/pdf',
            'extension' => 'pdf',
            'disco' => 'local',
            'ruta_almacenamiento' => 'expedientes/'.$expedienteAjeno->getKey().'/otro.pdf',
            'tamano_bytes' => 128,
            'estado_procesamiento' => 'procesado',
        ]);
        $archivoAjeno->paginas()->create([
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => 'La detención preventiva aparece en otro caso del propietario.',
            'es_legible' => true,
        ]);
        (new IndexarArchivoExpediente($archivoAjeno->getKey()))->handle(new FragmentadorTexto);

        $textoLegal = 'La detención preventiva requiere resolución fundamentada y motivada.';
        $fuenteJuridica = FuenteJuridica::query()->create([
            'titulo' => 'Norma sintética vigente',
            'tipo_fuente' => 'ley',
            'numero_norma' => 'Norma de prueba 12',
            'version' => '2026-01',
            'fecha_vigencia' => today()->subYear()->toDateString(),
            'contenido_original' => $textoLegal,
            'estado' => 'vigente',
            'validada' => true,
        ]);
        (new IndexarFuenteJuridica($fuenteJuridica->getKey()))->handle(new FragmentadorTexto);
        $fragmentoExpediente = $archivo->fragmentos()->sole();
        $fragmentoJuridico = $fuenteJuridica->fragmentos()->sole();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/simulaciones/'.$simulacion->getKey().'/fuentes', [
            'consulta' => 'detención preventiva',
            'limite' => 3,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.expediente')
            ->assertJsonCount(1, 'data.juridica')
            ->assertJsonPath('data.expediente.0.id', $fragmentoExpediente->getKey())
            ->assertJsonPath('data.juridica.0.id', $fragmentoJuridico->getKey())
            ->assertJsonPath('data.expediente.0.extracto', 'La detención preventiva requiere motivación suficiente según el expediente.')
            ->assertJsonPath('data.expediente.0.fuente.archivo', 'audiencia.pdf')
            ->assertJsonPath('data.expediente.0.fuente.localizador', 'Página 4')
            ->assertJsonPath('data.juridica.0.fuente.titulo', 'Norma sintética vigente')
            ->assertJsonPath('data.juridica.0.fuente.numero_norma', 'Norma de prueba 12')
            ->assertJsonPath('meta.relevancia_es_certeza', false)
            ->assertJsonMissing(['archivo' => 'otro.pdf']);
    }

    public function test_simulation_source_search_is_authenticated_and_private_to_the_simulation_owner(): void
    {
        $simulacion = Simulacion::factory()->create();
        $owner = $simulacion->usuario;
        $other = User::factory()->create();
        $url = '/api/v1/simulaciones/'.$simulacion->getKey().'/fuentes';

        $this->postJson($url, ['consulta' => 'detención preventiva'])->assertUnauthorized();

        Sanctum::actingAs($other);
        $this->postJson($url, ['consulta' => 'detención preventiva'])->assertNotFound();

        Sanctum::actingAs($owner);
        $this->postJson($url, ['consulta' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('consulta');
    }
}
