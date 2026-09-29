<?php

namespace Tests\Feature\Api;

use App\Jobs\EvaluarSimulacion;
use App\Models\EtapaAudiencia;
use App\Models\Evaluacion;
use App\Models\Rubrica;
use App\Models\Simulacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvaluacionSimulacionTest extends TestCase
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

    public function test_owner_can_request_rubric_driven_evaluation_after_simulation_finishes(): void
    {
        [$simulacion, $rubrica] = $this->prepararSimulacionFinalizada();
        Queue::fake();
        Sanctum::actingAs($simulacion->usuario);

        $this->postJson('/api/v1/simulaciones/'.$simulacion->getKey().'/evaluacion')
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pendiente')
            ->assertJsonPath('data.requires_human_review', true)
            ->assertJsonPath('data.rubric.name', $rubrica->nombre);

        $evaluacion = Evaluacion::query()->firstOrFail();
        $this->assertSame($rubrica->getKey(), $evaluacion->id_rubrica);
        $this->assertSame('pendiente', $evaluacion->estado);
        $this->assertSame($rubrica->criterios()->firstOrFail()->getKey(), $evaluacion->datos_evaluacion['rubric']['criteria'][0]['id']);
        Queue::assertPushed(EvaluarSimulacion::class, fn (EvaluarSimulacion $job): bool => $job->evaluacionId === $evaluacion->getKey());
    }

    public function test_request_rejects_client_selected_rubric_and_does_not_dispatch(): void
    {
        [$simulacion, $rubrica] = $this->prepararSimulacionFinalizada();
        Queue::fake();
        Sanctum::actingAs($simulacion->usuario);

        $this->postJson('/api/v1/simulaciones/'.$simulacion->getKey().'/evaluacion', [
            'id_rubrica' => $rubrica->getKey(),
            'score' => 10,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('evaluaciones', 0);
        Queue::assertNothingPushed();
    }

    public function test_evaluation_is_blocked_until_simulation_is_finished(): void
    {
        [$simulacion] = $this->prepararSimulacionFinalizada();
        $simulacion->update(['estado' => 'activa']);
        Queue::fake();
        Sanctum::actingAs($simulacion->usuario);

        $this->postJson('/api/v1/simulaciones/'.$simulacion->getKey().'/evaluacion')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('simulacion');

        $this->assertDatabaseCount('evaluaciones', 0);
        Queue::assertNothingPushed();
    }

    public function test_evaluation_requires_one_unambiguous_active_rubric(): void
    {
        [$simulacion, $rubrica] = $this->prepararSimulacionFinalizada();
        $duplicada = Rubrica::query()->create([
            'nombre' => 'Rúbrica duplicada '.$simulacion->getKey(),
            'rol_aplicable' => 'abogado_defensor',
            'id_tipo_audiencia' => $simulacion->id_tipo_audiencia,
            'version' => 1,
            'activo' => true,
        ]);
        $duplicada->criterios()->create([
            'nombre' => 'Criterio alterno',
            'descripcion' => 'Descripción de prueba.',
            'peso' => 1,
            'puntaje_maximo' => 10,
            'orden' => 1,
        ]);
        Queue::fake();
        Sanctum::actingAs($simulacion->usuario);

        $this->postJson('/api/v1/simulaciones/'.$simulacion->getKey().'/evaluacion')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rubric');

        $this->assertDatabaseCount('evaluaciones', 0);
        $this->assertDatabaseHas('rubricas', ['id_rubrica' => $rubrica->getKey(), 'activo' => true]);
        Queue::assertNothingPushed();
    }

    public function test_owner_can_poll_evaluation_but_another_user_cannot(): void
    {
        [$simulacion, $rubrica] = $this->prepararSimulacionFinalizada();
        $evaluacion = $simulacion->evaluaciones()->create([
            'id_rubrica' => $rubrica->getKey(),
            'estado' => 'pendiente',
            'datos_evaluacion' => ['requires_human_review' => true],
        ]);
        Sanctum::actingAs($simulacion->usuario);

        $this->getJson('/api/v1/simulaciones/'.$simulacion->getKey().'/evaluaciones/'.$evaluacion->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', $evaluacion->getKey())
            ->assertJsonPath('data.status', 'pendiente');

        $this->getJson('/api/v1/simulaciones/'.$simulacion->getKey().'/evaluacion')
            ->assertOk()
            ->assertJsonPath('data.id', $evaluacion->getKey())
            ->assertJsonPath('data.status', 'pendiente');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/simulaciones/'.$simulacion->getKey().'/evaluaciones/'.$evaluacion->getKey())
            ->assertNotFound();
    }

    /** @return array{0: Simulacion, 1: Rubrica} */
    private function prepararSimulacionFinalizada(): array
    {
        $simulacion = Simulacion::factory()->create();
        $etapa = EtapaAudiencia::query()->create([
            'id_tipo_audiencia' => $simulacion->id_tipo_audiencia,
            'codigo' => 'fase-evaluacion-'.$simulacion->getKey(),
            'nombre' => 'Audiencia finalizada',
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
        $simulacion->intervenciones()->create([
            'id_participante_simulacion' => $defensa->getKey(),
            'id_tipo_audiencia' => $simulacion->id_tipo_audiencia,
            'id_etapa' => $etapa->getKey(),
            'orden' => 1,
            'contenido' => 'Solicito que se valore la petición por el arraigo demostrado.',
            'tipo_entrada' => 'texto',
        ]);
        $rubrica = Rubrica::query()->create([
            'nombre' => 'Rúbrica de práctica '.$simulacion->getKey(),
            'rol_aplicable' => 'abogado_defensor',
            'id_tipo_audiencia' => $simulacion->id_tipo_audiencia,
            'version' => 1,
            'activo' => true,
        ]);
        $rubrica->criterios()->create([
            'nombre' => 'Claridad argumentativa',
            'descripcion' => 'Expone una petición comprensible y ordenada.',
            'peso' => 1,
            'puntaje_maximo' => 10,
            'orden' => 1,
        ]);

        return [$simulacion->fresh(['usuario']), $rubrica];
    }
}
