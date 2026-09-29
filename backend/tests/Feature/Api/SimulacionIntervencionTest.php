<?php

namespace Tests\Feature\Api;

use App\Models\EtapaAudiencia;
use App\Models\FragmentoDocumento;
use App\Models\FuenteIntervencion;
use App\Models\FuenteJuridica;
use App\Models\Simulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SimulacionIntervencionTest extends TestCase
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

    public function test_intervention_persists_a_verified_case_file_citation_and_returns_its_snapshot(): void
    {
        $simulacion = $this->simulacionActiva();
        $archivo = $simulacion->expediente->archivos()->create([
            'nombre_original' => 'declaracion.pdf',
            'nombre_almacenado' => 'declaracion.pdf',
            'tipo_mime' => 'application/pdf',
            'extension' => 'pdf',
            'disco' => 'local',
            'ruta_almacenamiento' => 'expedientes/'.$simulacion->id_expediente.'/declaracion.pdf',
            'tamano_bytes' => 128,
            'estado_procesamiento' => 'procesado',
        ]);
        $pagina = $archivo->paginas()->create([
            'numero_pagina' => 2,
            'localizador' => 'Página 2',
            'texto_extraido' => 'La declaración identifica a Ana como testigo del hecho.',
            'es_legible' => true,
        ]);
        $fragmento = FragmentoDocumento::query()->create([
            'id_archivo' => $archivo->getKey(),
            'id_pagina' => $pagina->getKey(),
            'contenido' => 'La declaración identifica a Ana como testigo',
            'numero_pagina' => 2,
            'indice_fragmento' => 0,
        ]);
        $fuenteCargada = $fragmento->fresh(['archivo', 'pagina']);
        $this->assertSame($simulacion->id_expediente, $fuenteCargada->archivo->id_expediente);
        $this->assertSame('procesado', $fuenteCargada->archivo->estado_procesamiento);
        $this->assertTrue($fuenteCargada->pagina->es_legible);
        $this->assertSame('La declaración identifica a Ana como testigo', $fuenteCargada->contenido);
        $this->assertSame('La declaración identifica a Ana como testigo del hecho.', $fuenteCargada->pagina->texto_extraido);
        $this->assertTrue(str_contains($fuenteCargada->pagina->texto_extraido, $fuenteCargada->contenido));
        Sanctum::actingAs($simulacion->usuario);

        $response = $this->postJson('/api/v1/simulaciones/'.$simulacion->getKey().'/intervenciones', [
            'contenido' => 'Solicito que se valore la declaración.',
            'source_fragment_ids' => [$fragmento->getKey()],
        ])
            ->assertCreated()
            ->assertJsonPath('data.interventions.0.content', 'Solicito que se valore la declaración.')
            ->assertJsonPath('data.interventions.0.sources.0.fragment_id', $fragmento->getKey())
            ->assertJsonPath('data.interventions.0.sources.0.excerpt', 'La declaración identifica a Ana como testigo')
            ->assertJsonPath('data.interventions.0.sources.0.source.kind', 'expediente')
            ->assertJsonPath('data.interventions.0.sources.0.source.title', 'declaracion.pdf')
            ->assertJsonPath('data.interventions.0.sources.0.source.page', 2)
            ->assertJsonPath('data.interventions.0.sources.0.source.locator', 'Página 2')
            ->assertJsonMissingPath('data.interventions.0.sources.0.source.storage_path');

        $intervencionId = $response->json('data.interventions.0.id');
        $cita = FuenteIntervencion::query()->where('id_intervencion', $intervencionId)->sole();
        $this->assertSame($fragmento->getKey(), $cita->id_fragmento);
        $this->assertSame('La declaración identifica a Ana como testigo', $cita->fragmento_utilizado);
        $this->assertSame('expediente', $cita->metadatos['tipo_fuente']);
        $this->assertSame('declaracion.pdf', $cita->metadatos['titulo']);
    }

    public function test_intervention_persists_a_legal_citation_only_while_its_validated_version_is_current(): void
    {
        $simulacion = $this->simulacionActiva();
        $textoNormativo = 'Toda persona tiene derecho a la defensa en juicio.';
        $fuente = FuenteJuridica::query()->create([
            'titulo' => 'Norma de práctica',
            'tipo_fuente' => 'constitucion',
            'numero_norma' => 'CPE',
            'version' => '2026-01',
            'fecha_vigencia' => today()->subYear()->toDateString(),
            'contenido_original' => $textoNormativo,
            'estado' => 'vigente',
            'validada' => true,
        ]);
        $fragmento = FragmentoDocumento::query()->create([
            'id_fuente_juridica' => $fuente->getKey(),
            'contenido' => 'derecho a la defensa en juicio',
            'indice_fragmento' => 0,
            'metadatos' => ['md5_contenido_fuente' => md5($textoNormativo)],
        ]);
        Sanctum::actingAs($simulacion->usuario);

        $response = $this->postJson('/api/v1/simulaciones/'.$simulacion->getKey().'/intervenciones', [
            'contenido' => 'Invoco el derecho a la defensa.',
            'source_fragment_ids' => [$fragmento->getKey()],
        ])
            ->assertCreated()
            ->assertJsonPath('data.interventions.0.sources.0.source.kind', 'juridica')
            ->assertJsonPath('data.interventions.0.sources.0.source.title', 'Norma de práctica')
            ->assertJsonPath('data.interventions.0.sources.0.source.identifier', 'CPE')
            ->assertJsonPath('data.interventions.0.sources.0.source.version', '2026-01');

        $idIntervencion = $response->json('data.interventions.0.id');
        $this->assertSame(hash('sha256', $textoNormativo), FuenteIntervencion::query()
            ->where('id_intervencion', $idIntervencion)
            ->sole()
            ->metadatos['hash_contenido_fuente']);

        $fuente->update(['titulo' => 'Título actualizado después de citar']);
        $this->getJson('/api/v1/simulaciones/'.$simulacion->getKey())
            ->assertOk()
            ->assertJsonPath('data.interventions.0.sources.0.source.title', 'Norma de práctica');
    }

    public function test_unverifiable_or_foreign_fragments_are_rejected_without_partial_intervention(): void
    {
        $simulacion = $this->simulacionActiva();
        $otraSimulacion = Simulacion::factory()->create();
        $archivoAjeno = $otraSimulacion->expediente->archivos()->create([
            'nombre_original' => 'ajeno.pdf',
            'nombre_almacenado' => 'ajeno.pdf',
            'tipo_mime' => 'application/pdf',
            'extension' => 'pdf',
            'disco' => 'local',
            'ruta_almacenamiento' => 'expedientes/'.$otraSimulacion->id_expediente.'/ajeno.pdf',
            'tamano_bytes' => 64,
            'estado_procesamiento' => 'procesado',
        ]);
        $paginaAjena = $archivoAjeno->paginas()->create([
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => 'Contenido privado de otro expediente.',
            'es_legible' => true,
        ]);
        $fragmentoAjeno = FragmentoDocumento::query()->create([
            'id_archivo' => $archivoAjeno->getKey(),
            'id_pagina' => $paginaAjena->getKey(),
            'contenido' => 'Contenido privado de otro expediente.',
            'numero_pagina' => 1,
            'indice_fragmento' => 0,
        ]);
        $fuenteNoValidada = FuenteJuridica::query()->create([
            'titulo' => 'Borrador no verificable',
            'tipo_fuente' => 'norma',
            'fecha_vigencia' => today()->subYear()->toDateString(),
            'contenido_original' => 'Una regla de prueba.',
            'estado' => 'vigente',
            'validada' => false,
        ]);
        $fragmentoNoValidado = FragmentoDocumento::query()->create([
            'id_fuente_juridica' => $fuenteNoValidada->getKey(),
            'contenido' => 'Una regla de prueba.',
            'indice_fragmento' => 0,
            'metadatos' => ['md5_contenido_fuente' => md5('Una regla de prueba.')],
        ]);
        Sanctum::actingAs($simulacion->usuario);

        foreach ([$fragmentoAjeno, $fragmentoNoValidado] as $fragmento) {
            $this->postJson('/api/v1/simulaciones/'.$simulacion->getKey().'/intervenciones', [
                'contenido' => 'Intervención con referencia inválida.',
                'source_fragment_ids' => [$fragmento->getKey()],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('source_fragment_ids');
        }

        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertDatabaseCount('fuentes_intervencion', 0);
    }

    public function test_private_citation_must_match_the_text_of_a_legible_page(): void
    {
        $simulacion = $this->simulacionActiva();
        $archivo = $simulacion->expediente->archivos()->create([
            'nombre_original' => 'pagina-ilegible.pdf',
            'nombre_almacenado' => 'pagina-ilegible.pdf',
            'tipo_mime' => 'application/pdf',
            'extension' => 'pdf',
            'disco' => 'local',
            'ruta_almacenamiento' => 'expedientes/'.$simulacion->id_expediente.'/pagina-ilegible.pdf',
            'tamano_bytes' => 64,
            'estado_procesamiento' => 'procesado',
        ]);
        $pagina = $archivo->paginas()->create([
            'numero_pagina' => 1,
            'localizador' => 'Página 1',
            'texto_extraido' => 'El documento no contiene el extracto indicado.',
            'es_legible' => true,
        ]);
        $fragmento = FragmentoDocumento::query()->create([
            'id_archivo' => $archivo->getKey(),
            'id_pagina' => $pagina->getKey(),
            'contenido' => 'Una cita inventada que no está en la página.',
            'numero_pagina' => 1,
            'indice_fragmento' => 0,
        ]);
        Sanctum::actingAs($simulacion->usuario);

        $this->postJson('/api/v1/simulaciones/'.$simulacion->getKey().'/intervenciones', [
            'contenido' => 'Intervención con extracto inconsistente.',
            'source_fragment_ids' => [$fragmento->getKey()],
        ])->assertUnprocessable()->assertJsonValidationErrors('source_fragment_ids');

        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertDatabaseCount('fuentes_intervencion', 0);
    }

    public function test_legal_citation_is_rejected_when_its_version_is_expired_or_changed_after_indexing(): void
    {
        $simulacion = $this->simulacionActiva();
        $this->configurarSegundoTurno($simulacion);
        $texto = 'El derecho a la defensa será inviolable.';
        $vigenteHastaAyer = FuenteJuridica::query()->create([
            'titulo' => 'Norma expirada',
            'tipo_fuente' => 'norma',
            'fecha_vigencia' => today()->subYear()->toDateString(),
            'fecha_fin_vigencia' => today()->subDay()->toDateString(),
            'contenido_original' => $texto,
            'estado' => 'vigente',
            'validada' => true,
        ]);
        $fragmentoExpirado = FragmentoDocumento::query()->create([
            'id_fuente_juridica' => $vigenteHastaAyer->getKey(),
            'contenido' => $texto,
            'indice_fragmento' => 0,
            'metadatos' => ['md5_contenido_fuente' => md5($texto)],
        ]);
        $fuenteModificada = FuenteJuridica::query()->create([
            'titulo' => 'Norma modificada tras indexar',
            'tipo_fuente' => 'norma',
            'fecha_vigencia' => today()->subYear()->toDateString(),
            'contenido_original' => $texto.' Texto añadido después de indexar.',
            'estado' => 'vigente',
            'validada' => true,
        ]);
        $fragmentoDesactualizado = FragmentoDocumento::query()->create([
            'id_fuente_juridica' => $fuenteModificada->getKey(),
            'contenido' => $texto,
            'indice_fragmento' => 0,
            'metadatos' => ['md5_contenido_fuente' => md5($texto)],
        ]);
        Sanctum::actingAs($simulacion->usuario);
        $url = '/api/v1/simulaciones/'.$simulacion->getKey().'/intervenciones';

        foreach ([$fragmentoExpirado, $fragmentoDesactualizado] as $fragmento) {
            $this->postJson($url, [
                'contenido' => 'Intervención con norma no vigente.',
                'source_fragment_ids' => [$fragmento->getKey()],
            ])->assertUnprocessable()->assertJsonValidationErrors('source_fragment_ids');
        }

        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertDatabaseCount('fuentes_intervencion', 0);
    }

    public function test_intervention_can_be_saved_without_sources_but_source_ids_are_bounded_and_unique(): void
    {
        $simulacion = $this->simulacionActiva();
        Sanctum::actingAs($simulacion->usuario);
        $url = '/api/v1/simulaciones/'.$simulacion->getKey().'/intervenciones';

        $this->postJson($url, ['contenido' => 'Argumento oral sin cita adjunta.'])
            ->assertCreated()
            ->assertJsonPath('data.interventions.0.sources', []);

        // A second turn is configured only for this test fixture; legal defaults remain untouched.
        $this->configurarSegundoTurno($simulacion);

        $this->postJson($url, [
            'contenido' => 'Referencia duplicada.',
            'source_fragment_ids' => [1, 1],
        ])->assertUnprocessable()->assertJsonValidationErrors('source_fragment_ids.1');

        $this->postJson($url, [
            'contenido' => 'Demasiadas referencias.',
            'source_fragment_ids' => range(1, 11),
        ])->assertUnprocessable()->assertJsonValidationErrors('source_fragment_ids');

        $this->assertDatabaseCount('intervenciones', 1);
        $this->assertDatabaseCount('fuentes_intervencion', 0);
    }

    private function simulacionActiva(): Simulacion
    {
        $simulacion = Simulacion::factory()->create();
        $etapa = EtapaAudiencia::query()->create([
            'id_tipo_audiencia' => $simulacion->id_tipo_audiencia,
            'codigo' => 'fase-prueba-'.$simulacion->getKey(),
            'nombre' => 'Fase de prueba',
            'orden' => 1,
            'es_inicial' => true,
            'activo' => true,
        ]);
        $simulacion->update([
            'id_etapa_actual' => $etapa->getKey(),
            'estado' => 'activa',
            'fecha_inicio' => now(),
            'configuracion' => ['turnos_por_etapa' => [(string) $etapa->getKey() => [
                ['orden' => 1, 'rol' => 'abogado_defensor'],
            ]]],
        ]);
        $simulacion->participantes()->create([
            'id_analisis' => $simulacion->id_analisis,
            'id_expediente' => $simulacion->id_expediente,
            'rol' => 'abogado_defensor',
            'nombre_mostrado' => 'Defensa de prueba',
            'controlado_por' => 'usuario',
            'estado' => 'activo',
        ]);

        return $simulacion->fresh(['usuario', 'expediente']);
    }

    private function configurarSegundoTurno(Simulacion $simulacion): void
    {
        $simulacion->update([
            'configuracion' => ['turnos_por_etapa' => [(string) $simulacion->id_etapa_actual => [
                ['orden' => 1, 'rol' => 'abogado_defensor'],
                ['orden' => 2, 'rol' => 'abogado_defensor'],
            ]]],
        ]);
    }
}
