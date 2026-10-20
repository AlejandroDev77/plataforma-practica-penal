<?php

namespace Tests\Feature\Api;

use App\Models\EtapaAudiencia;
use App\Models\Simulacion;
use App\Models\TransicionAudiencia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrquestacionAudienciaTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if ($this->app->configurationIsCached()
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'jurissim_pruebas'
            || config('database.connections.pgsql.url')
            || DB::selectOne('select current_database() as nombre')->nombre !== 'jurissim_pruebas') {
            throw new \RuntimeException('Las pruebas requieren PostgreSQL jurissim_pruebas sin DB_URL ni configuracion cacheada.');
        }
        Http::preventStrayRequests();
    }

    public function test_requires_authentication_and_hides_other_owners(): void
    {
        $simulacion = $this->preparar();
        $this->getJson($this->url($simulacion))->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($this->url($simulacion).'?id_usuario='.$simulacion->id_usuario)->assertNotFound();
    }

    public function test_user_turn_is_only_a_technical_candidate_not_a_legal_authorization(): void
    {
        $simulacion = $this->preparar();
        $this->consultar($simulacion)
            ->assertJsonPath('data.technical_status', 'esperando_usuario')
            ->assertJsonPath('data.current_turn.role', 'abogado_defensor')
            ->assertJsonPath('data.technical_candidate_actions', ['submit_text_intervention'])
            ->assertJsonPath('data.allowed_actions', [])
            ->assertJsonPath('data.practice_ready', false)
            ->assertJsonPath('data.legal_review_status', 'no_acreditada')
            ->assertJsonPath('data.blocking_reasons', ['revision_juridica_secuencia_no_acreditada']);
    }

    public function test_legacy_simulation_without_sequence_is_blocked(): void
    {
        $simulacion = $this->preparar();
        $simulacion->update(['configuracion' => []]);
        $this->consultar($simulacion)->assertJsonPath('data.current_turn', null)
            ->assertJsonPath('data.technical_candidate_actions', [])
            ->assertJsonFragment(['technical_blocking_reasons' => ['secuencia_no_configurada']]);
    }

    public function test_latest_analysis_review_can_revoke_approval(): void
    {
        $simulacion = $this->preparar();
        $simulacion->analisis->revisiones()->create([
            'id_expediente' => $simulacion->id_expediente, 'id_usuario' => $simulacion->id_usuario,
            'decision' => 'requiere_cambios', 'observacion' => 'Revision sintetica posterior.',
        ]);
        $this->consultar($simulacion)->assertJsonPath('data.analysis.review_decision', 'requiere_cambios')
            ->assertJsonPath('data.technical_candidate_actions', [])
            ->assertJsonFragment(['technical_blocking_reasons' => ['analisis_no_aprobado']]);
    }

    public function test_missing_review_does_not_authorize_an_analysis(): void
    {
        $simulacion = $this->preparar();
        $simulacion->analisis->revisiones()->delete();
        $this->consultar($simulacion)->assertJsonPath('data.analysis.review_decision', null)
            ->assertJsonFragment(['technical_blocking_reasons' => ['analisis_no_aprobado']]);
    }

    public function test_agent_turn_requires_a_unique_participant_and_an_instruction(): void
    {
        $simulacion = $this->preparar([['orden' => 1, 'rol' => 'fiscal']]);
        $this->actor($simulacion, 'fiscal', 'ia');
        $this->consultar($simulacion)->assertJsonPath('data.technical_candidate_actions', [])
            ->assertJsonFragment(['technical_blocking_reasons' => ['instruccion_agente_ausente']]);
    }

    public function test_agent_readiness_does_not_call_the_provider(): void
    {
        $simulacion = $this->preparar([['orden' => 1, 'rol' => 'fiscal', 'instruccion' => 'Ejercicio sintetico.']]);
        $this->actor($simulacion, 'fiscal', 'ia');
        $this->consultar($simulacion)->assertJsonPath('data.technical_status', 'esperando_agente')
            ->assertJsonPath('data.requires_provider_check', true)
            ->assertJsonPath('data.technical_candidate_actions', ['request_agent_proposal'])
            ->assertJsonPath('data.allowed_actions', []);
        Http::assertNothingSent();
    }

    public function test_missing_or_inactive_actor_blocks_the_turn(): void
    {
        $simulacion = $this->preparar();
        $simulacion->participantes()->update(['estado' => 'inactivo']);
        $this->consultar($simulacion)->assertJsonPath('data.current_participant', null)
            ->assertJsonFragment(['technical_blocking_reasons' => ['participante_no_disponible']]);
    }

    public function test_ambiguous_actor_blocks_the_turn(): void
    {
        $simulacion = $this->preparar();
        $this->actor($simulacion, 'abogado_defensor', 'usuario');
        $this->consultar($simulacion)->assertJsonPath('data.technical_candidate_actions', [])
            ->assertJsonFragment(['technical_blocking_reasons' => ['participante_ambiguo']]);
    }

    public function test_invalid_snapshots_are_not_silently_filtered_or_repaired(): void
    {
        $simulacion = $this->preparar();
        $invalidas = [
            'texto',
            [['orden' => '1', 'rol' => 'juez']],
            [['orden' => 0, 'rol' => 'juez']],
            [['orden' => 1, 'rol' => 'juez'], ['orden' => 1, 'rol' => 'fiscal']],
            [['orden' => 2, 'rol' => 'juez'], ['orden' => 1, 'rol' => 'fiscal']],
            [['orden' => 1, 'rol' => 'victima']],
            [['orden' => 1, 'rol' => 'fiscal', 'instruccion' => ['no' => 'texto']]],
            array_fill(0, 101, ['orden' => 1, 'rol' => 'juez']),
        ];
        foreach ($invalidas as $secuencia) {
            $simulacion->update(['configuracion' => ['turnos_por_etapa' => [$simulacion->id_etapa_actual => $secuencia]]]);
            $this->consultar($simulacion)->assertJsonPath('data.current_turn', null)
                ->assertJsonFragment(['technical_blocking_reasons' => ['secuencia_invalida_o_rol_no_soportado']]);
        }
        foreach (['texto', ['turnos_por_etapa' => 'texto']] as $configuracion) {
            $simulacion->update(['configuracion' => $configuracion]);
            $this->consultar($simulacion)->assertJsonFragment([
                'technical_blocking_reasons' => ['secuencia_invalida_o_rol_no_soportado'],
            ]);
        }
    }

    public function test_wrong_historical_actor_blocks_even_a_complete_sequence(): void
    {
        $simulacion = $this->preparar();
        $actor = $this->actor($simulacion, 'fiscal', 'ia');
        $this->intervenir($simulacion, $actor->getKey());
        $simulacion->etapaActual->update(['es_final' => true]);
        $this->consultar($simulacion)->assertJsonPath('data.technical_candidate_actions', [])
            ->assertJsonFragment(['technical_blocking_reasons' => ['historial_incompatible_con_secuencia']]);
    }

    public function test_history_selects_the_next_snapshotted_turn_without_mutating_it(): void
    {
        $simulacion = $this->preparar([
            ['orden' => 2, 'rol' => 'abogado_defensor'],
            ['orden' => 4, 'rol' => 'fiscal', 'instruccion' => 'Ejercicio sintetico.'],
        ]);
        $this->actor($simulacion, 'fiscal', 'ia');
        $configuracion = $simulacion->fresh()->configuracion;
        $this->consultar($simulacion)->assertJsonPath('data.current_turn.order', 2);
        $this->intervenir($simulacion, $simulacion->participantes()->where('rol', 'abogado_defensor')->sole()->getKey());
        $this->consultar($simulacion)->assertJsonPath('data.current_turn.order', 4)
            ->assertJsonPath('data.current_turn.role', 'fiscal')
            ->assertJsonPath('data.technical_status', 'esperando_agente');
        $this->assertSame($configuracion, $simulacion->fresh()->configuracion);
    }

    public function test_user_role_outside_the_mvp_is_not_silently_replaced(): void
    {
        $simulacion = $this->preparar();
        $simulacion->update(['rol_usuario' => 'fiscal']);
        $this->consultar($simulacion)->assertJsonPath('data.technical_candidate_actions', [])
            ->assertJsonPath('data.technical_blocking_reasons.0', 'rol_usuario_no_soportado');
    }

    public function test_wrong_historical_control_cannot_impersonate_the_user(): void
    {
        $simulacion = $this->preparar();
        $actor = $this->actor($simulacion, 'abogado_defensor', 'ia');
        $this->intervenir($simulacion, $actor->getKey());
        $simulacion->etapaActual->update(['es_final' => true]);
        $this->consultar($simulacion)->assertJsonPath('data.technical_candidate_actions', [])
            ->assertJsonFragment(['technical_blocking_reasons' => ['historial_incompatible_con_secuencia']]);
    }

    public function test_excess_interventions_do_not_become_valid_completion(): void
    {
        $simulacion = $this->preparar();
        $actor = $simulacion->participantes()->sole();
        $this->intervenir($simulacion, $actor->getKey());
        $this->intervenir($simulacion, $actor->getKey(), 2);
        $simulacion->etapaActual->update(['es_final' => true]);
        $this->consultar($simulacion)->assertJsonFragment([
            'technical_blocking_reasons' => ['historial_incompatible_con_secuencia'],
        ]);
    }

    public function test_completed_stage_requires_a_valid_destination_and_reports_choices(): void
    {
        $simulacion = $this->preparar();
        $this->intervenir($simulacion, $simulacion->participantes()->sole()->getKey());
        $this->consultar($simulacion)->assertJsonFragment([
            'technical_blocking_reasons' => ['etapa_sin_transicion_disponible'],
        ]);
        $destino = $this->destino($simulacion, 2);
        $this->consultar($simulacion)->assertJsonPath('data.technical_status', 'esperando_avance')
            ->assertJsonPath('data.technical_candidate_actions', ['advance_stage'])
            ->assertJsonCount(1, 'data.candidate_next_stages');
        $this->destino($simulacion, 3);
        $this->consultar($simulacion)->assertJsonPath('data.technical_status', 'esperando_destino')
            ->assertJsonCount(2, 'data.candidate_next_stages');
        $destino->update(['activo' => false]);
        $this->consultar($simulacion)->assertJsonCount(1, 'data.candidate_next_stages');
    }

    public function test_completed_final_stage_can_only_be_a_closure_candidate(): void
    {
        $simulacion = $this->preparar();
        $simulacion->etapaActual->update(['es_final' => true]);
        $this->intervenir($simulacion, $simulacion->participantes()->sole()->getKey());
        $this->consultar($simulacion)->assertJsonPath('data.technical_status', 'esperando_cierre')
            ->assertJsonPath('data.technical_candidate_actions', ['finish_simulation'])
            ->assertJsonPath('data.allowed_actions', []);
        $simulacion->update(['estado' => 'finalizada', 'fecha_fin' => now()]);
        $this->consultar($simulacion)->assertJsonPath('data.technical_status', 'finalizada')
            ->assertJsonPath('data.technical_candidate_actions', []);
    }

    public function test_inactive_catalog_and_preparing_state_are_not_ready(): void
    {
        $simulacion = $this->preparar();
        $simulacion->update(['estado' => 'preparando']);
        $simulacion->tipoAudiencia->update(['activo' => false]);
        $simulacion->etapaActual->update(['activo' => false]);
        $this->consultar($simulacion)->assertJsonPath('data.technical_candidate_actions', [])
            ->assertJsonPath('data.technical_blocking_reasons', [
                'audiencia_no_activa', 'tipo_audiencia_no_disponible', 'etapa_no_disponible',
            ]);
    }

    public function test_flags_and_query_parameters_cannot_certify_legal_review_or_override_turns(): void
    {
        $simulacion = $this->preparar();
        $configuracion = $simulacion->configuracion;
        $configuracion['revision_juridica'] = ['estado' => 'aprobado'];
        $simulacion->update(['configuracion' => $configuracion]);
        Sanctum::actingAs($simulacion->usuario);
        $this->getJson($this->url($simulacion).'?rol=fiscal&legal_review_status=aprobado&practice_ready=1')
            ->assertOk()->assertJsonPath('data.current_turn.role', 'abogado_defensor')
            ->assertJsonPath('data.legal_review_status', 'no_acreditada')
            ->assertJsonPath('data.allowed_actions', []);
    }

    public function test_consultation_is_read_only_and_does_not_expose_document_content(): void
    {
        $simulacion = $this->preparar();
        $antes = $simulacion->fresh()->getAttributes();
        Sanctum::actingAs($simulacion->usuario);
        $escrituras = [];
        DB::listen(function ($query) use (&$escrituras): void {
            if (preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate)\b/i', $query->sql)) {
                $escrituras[] = $query->sql;
            }
        });
        for ($i = 0; $i < 2; $i++) {
            $this->getJson($this->url($simulacion))->assertOk()->assertJsonPath('data.read_only', true)
                ->assertJsonMissingPath('data.analysis.summary')
                ->assertJsonMissingPath('data.current_turn.instruction')
                ->assertJsonMissingPath('data.configuration');
        }
        $this->assertSame([], $escrituras);
        $this->assertSame($antes, $simulacion->fresh()->getAttributes());
        $this->assertSame(0, $simulacion->intervenciones()->count());
        Http::assertNothingSent();
    }

    private function preparar(?array $secuencia = null): Simulacion
    {
        $simulacion = Simulacion::factory()->create();
        $etapa = EtapaAudiencia::create([
            'id_tipo_audiencia' => $simulacion->id_tipo_audiencia,
            'codigo' => 'fase-prueba-'.$simulacion->getKey(), 'nombre' => 'Fase sintetica',
            'orden' => 1, 'es_inicial' => true, 'activo' => true,
        ]);
        $simulacion->update([
            'id_etapa_actual' => $etapa->getKey(), 'estado' => 'activa', 'fecha_inicio' => now(),
            'configuracion' => ['turnos_por_etapa' => [$etapa->getKey() => $secuencia ?? [
                ['orden' => 1, 'rol' => 'abogado_defensor'],
            ]]],
        ]);
        $simulacion->analisis->revisiones()->create([
            'id_expediente' => $simulacion->id_expediente, 'id_usuario' => $simulacion->id_usuario,
            'decision' => 'aprobado', 'observacion' => 'Aprobacion exclusivamente sintetica.',
        ]);
        $this->actor($simulacion, 'abogado_defensor', 'usuario');

        return $simulacion;
    }

    private function actor(Simulacion $simulacion, string $rol, string $control)
    {
        return $simulacion->participantes()->create([
            'id_analisis' => $simulacion->id_analisis, 'id_expediente' => $simulacion->id_expediente,
            'rol' => $rol, 'nombre_mostrado' => 'Actor sintetico', 'controlado_por' => $control, 'estado' => 'activo',
        ]);
    }

    private function intervenir(Simulacion $simulacion, int $idActor, int $orden = 1): void
    {
        $simulacion->intervenciones()->create([
            'id_participante_simulacion' => $idActor, 'id_tipo_audiencia' => $simulacion->id_tipo_audiencia,
            'id_etapa' => $simulacion->id_etapa_actual, 'orden' => $orden,
            'contenido' => 'Intervencion sintetica.', 'tipo_entrada' => 'texto',
        ]);
    }

    private function destino(Simulacion $simulacion, int $orden): EtapaAudiencia
    {
        $destino = EtapaAudiencia::create([
            'id_tipo_audiencia' => $simulacion->id_tipo_audiencia,
            'codigo' => 'destino-'.$orden, 'nombre' => 'Destino sintetico', 'orden' => $orden, 'activo' => true,
        ]);
        TransicionAudiencia::create([
            'id_tipo_audiencia' => $simulacion->id_tipo_audiencia, 'id_etapa_origen' => $simulacion->id_etapa_actual,
            'id_etapa_destino' => $destino->getKey(), 'activo' => true,
        ]);

        return $destino;
    }

    private function url(Simulacion $simulacion): string
    {
        return '/api/v1/simulaciones/'.$simulacion->getKey().'/orquestacion';
    }

    private function consultar(Simulacion $simulacion): TestResponse
    {
        Sanctum::actingAs($simulacion->usuario);

        return $this->getJson($this->url($simulacion))->assertOk();
    }
}
