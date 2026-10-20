<?php

namespace Tests\Feature\Api;

use App\Models\ActivacionReglasAudiencia;
use App\Models\EtapaAudiencia;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\TipoAudiencia;
use App\Models\TransicionAudiencia;
use App\Models\User;
use App\Services\Simulaciones\GestionarRevisionJuridicaReglas;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActivacionAdministrativaReglasTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'jurissim_pruebas'
            || config('database.connections.pgsql.url')
            || DB::selectOne('SELECT current_database() nombre')->nombre !== 'jurissim_pruebas') {
            throw new RuntimeException('Solo PostgreSQL jurissim_pruebas, sin DB_URL ni cache.');
        }
        Http::preventStrayRequests();
    }

    public function test_sesion_y_rol_actual_son_obligatorios_antes_de_buscar_ids(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->consulta($v))->assertUnauthorized();
        foreach ([$revisor, User::factory()->create()] as $actor) {
            Sanctum::actingAs($actor);
            $this->getJson($this->consulta($v))->assertForbidden();
            $this->getJson('/api/v1/reglas-audiencia/versiones/999999/preparacion-activacion')->assertForbidden();
            $this->getJson('/api/v1/reglas-audiencia/tipos/999999/activaciones')->assertForbidden();
            $this->postJson($this->alta($v), [])->assertForbidden();
            $this->postJson('/api/v1/reglas-audiencia/activaciones/999999/revocaciones', [])->assertForbidden();
        }
        Sanctum::actingAs($admin);
        $admin->load('roles');
        $admin->removeRole('administrador_plataforma');
        $this->getJson($this->consulta($v))->assertForbidden();
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 0);
    }

    public function test_consulta_prepara_ids_del_servidor_sin_reservar_ni_escribir(): void
    {
        [, , $v, $r] = $this->escenario();
        $this->getJson($this->consulta($v))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.id_version_reglas', $v->getKey())
            ->assertJsonPath('data.puede_solicitar_activacion', true)->assertJsonPath('data.bloqueos', [])
            ->assertJsonPath('data.precondicion.id_revision_esperada', $r->getKey())
            ->assertJsonPath('data.precondicion.id_activacion_previa', null)
            ->assertJsonPath('data.precondicion.huella_version_esperada', $v->huella_contenido)
            ->assertJsonPath('data.activacion_actual.activation_status', 'sin_activacion')
            ->assertJsonPath('data.execution_authorized', false)->assertJsonPath('data.requires_simulation_binding', true)
            ->assertJsonMissingPath('data.contenido')->assertJsonMissingPath('data.referencia_acreditacion');
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 0);
        $this->assertDatabaseCount('simulaciones', 0);
        Http::assertNothingSent();
    }

    public function test_activacion_http_fija_responsable_y_no_muta_el_dominio_o_envia_ia(): void
    {
        [$admin, , $v] = $this->escenario();
        $antes = $this->dominio();
        $datos = $this->datos($v);
        $this->postJson($this->alta($v), [...$datos, 'id_usuario_responsable' => 999999, 'execution_authorized' => true,
            'fecha_creacion' => '2000-01-01', 'contenido' => ['falso' => true], 'perfil_validacion' => 'falso'])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.id_usuario_responsable', $admin->getKey())
            ->assertJsonPath('data.id_version_reglas', $v->getKey())
            ->assertJsonPath('data.execution_authorized', false)->assertJsonPath('data.requires_simulation_binding', true);
        $this->getJson($this->consulta($v))->assertOk()->assertJsonPath('data.puede_solicitar_activacion', false)
            ->assertJsonPath('data.bloqueos.0', 'revision_ya_utilizada')
            ->assertJsonPath('data.activacion_actual.activation_status', 'vigente')
            ->assertJsonPath('data.activacion_actual.execution_authorized', false);
        $this->assertSame($antes, $this->dominio());
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 1);
        Http::assertNothingSent();
    }

    public function test_confirmacion_estricta_motivo_y_precondiciones_no_son_opcionales(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [, , $v] = $this->escenario();
        $datos = $this->datos($v);
        foreach ([false, 1, 'true'] as $valor) {
            $this->postJson($this->alta($v), [...$datos, 'confirmacion_humana' => $valor])
                ->assertUnprocessable()->assertJsonValidationErrors('confirmacion_humana');
        }
        foreach (['motivo', 'huella_version_esperada', 'id_revision_esperada', 'id_activacion_previa'] as $campo) {
            $incompleto = $datos;
            unset($incompleto[$campo]);
            $this->postJson($this->alta($v), $incompleto)->assertUnprocessable()->assertJsonValidationErrors($campo);
        }
        $this->postJson($this->alta($v), [...$datos, 'motivo' => "\u{00a0}"])
            ->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 0);
    }

    public function test_cambios_de_revision_y_activacion_rechazan_lectura_obsoleta_sin_sql(): void
    {
        [, $revisor, $v] = $this->escenario();
        $datos = $this->datos($v);
        $this->revisar($revisor, $v);
        $respuesta = $this->postJson($this->alta($v), $datos)->assertConflict()->assertJsonPath('code', 'reglas_conflicto');
        $this->assertStringNotContainsString('SQL', $respuesta->getContent());
        $nueva = $this->datos($v);
        $this->postJson($this->alta($v), $nueva)->assertCreated();
        $this->postJson($this->alta($v), $nueva)->assertConflict();
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 1);
    }

    public function test_retiro_confirmado_es_idempotente_y_no_reactiva_ni_borra(): void
    {
        [, $revisor, $v] = $this->escenario();
        $this->postJson($this->alta($v), $this->datos($v))->assertCreated();
        $primera = ActivacionReglasAudiencia::query()->firstOrFail();
        $this->revisar($revisor, $v);
        $this->postJson($this->alta($v), $this->datos($v))->assertCreated();
        $ultima = ActivacionReglasAudiencia::query()->orderByDesc('id_activacion')->firstOrFail();
        $ruta = '/api/v1/reglas-audiencia/activaciones/'.$ultima->getKey().'/revocaciones';
        $this->postJson($ruta, ['motivo' => 'Retiro sintetico.'])->assertUnprocessable();
        $respuesta = $this->postJson($ruta, ['motivo' => 'Motivo original.', 'confirmacion_humana' => true])->assertOk();
        $this->postJson($ruta, ['motivo' => 'No reemplazar.', 'confirmacion_humana' => true])->assertOk()
            ->assertJsonPath('data.revocacion.id', $respuesta->json('data.revocacion.id'))
            ->assertJsonPath('data.revocacion.motivo', 'Motivo original.');
        $this->getJson($this->consulta($v))->assertOk()->assertJsonPath('data.activacion_actual.activation_id', $ultima->getKey())
            ->assertJsonPath('data.activacion_actual.activation_status', 'retirada')
            ->assertJsonPath('data.activacion_actual.activation_current', false);
        $this->getJson('/api/v1/reglas-audiencia/tipos/'.$v->id_tipo_audiencia.'/activaciones?por_pagina=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $ultima->getKey())
            ->assertJsonPath('data.0.execution_authorized', false);
        $this->getJson('/api/v1/reglas-audiencia/tipos/'.$v->id_tipo_audiencia.'/activaciones?por_pagina=1&page=2')
            ->assertOk()->assertJsonPath('data.0.id', $primera->getKey());
        $this->assertDatabaseCount('revocaciones_activaciones_reglas', 1);
    }

    public function test_copias_historicas_y_tipo_inactivo_no_se_presentan_como_activables(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $historica = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $v->id_tipo_audiencia, 'Copia historica sintetica.');
        $this->revisar($revisor, $historica, null);
        $respuesta = $this->getJson($this->consulta($historica))->assertOk()->assertJsonPath('data.puede_solicitar_activacion', false);
        $this->assertContains('instrucciones_no_compatibles', $respuesta->json('data.bloqueos'));
        $this->postJson($this->alta($historica), $this->datos($historica))->assertConflict();
        TipoAudiencia::query()->whereKey($v->id_tipo_audiencia)->update(['activo' => false]);
        $respuesta = $this->getJson($this->consulta($v))->assertOk()->assertJsonPath('data.puede_solicitar_activacion', false);
        $this->assertContains('tipo_inactivo', $respuesta->json('data.bloqueos'));
        $this->postJson($this->alta($v), $this->datos($v))->assertConflict();
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 0);
    }

    public function test_ids_inexistentes_y_filtros_invalidos_son_controlados(): void
    {
        [, , $v] = $this->escenario();
        $this->getJson('/api/v1/reglas-audiencia/versiones/999999/preparacion-activacion')->assertNotFound();
        $this->getJson('/api/v1/reglas-audiencia/tipos/999999/activaciones')->assertNotFound();
        $this->getJson('/api/v1/reglas-audiencia/tipos/'.$v->id_tipo_audiencia.'/activaciones?por_pagina=51')->assertUnprocessable();
        $this->postJson('/api/v1/reglas-audiencia/activaciones/999999/revocaciones', ['motivo' => 'Sintetico.', 'confirmacion_humana' => true])->assertNotFound();
    }

    private function escenario(): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $revisor = User::factory()->create();
        $tipo = TipoAudiencia::create(['nombre' => 'Audiencia sintetica', 'codigo' => 'sintetica', 'activo' => true]);
        $etapas = $instrucciones = [];
        foreach ([1, 2] as $orden) {
            $e = EtapaAudiencia::create(['id_tipo_audiencia' => $tipo->getKey(), 'codigo' => 'sintetica-'.$orden,
                'nombre' => 'Etapa sintetica', 'orden' => $orden, 'es_inicial' => $orden === 1, 'es_final' => $orden === 2, 'activo' => true]);
            $etapas[] = $e;
            $p = new PropuestaTurnoAudiencia(['id_tipo_audiencia' => $tipo->getKey(), 'id_etapa' => $e->getKey(),
                'orden' => 1, 'rol' => $orden === 1 ? 'juez' : 'abogado_defensor', 'acto_propuesto' => 'Acto sintetico', 'descripcion_propuesta' => 'Propuesta sintetica.']);
            $p->id_usuario_creador = $admin->getKey();
            $p->save();
            $instrucciones[] = ['id_propuesta_turno' => $p->getKey(), 'texto' => 'Instruccion sintetica sin valor juridico.'];
        }
        TransicionAudiencia::create(['id_tipo_audiencia' => $tipo->getKey(), 'id_etapa_origen' => $etapas[0]->getKey(), 'id_etapa_destino' => $etapas[1]->getKey(), 'activo' => true]);
        $v = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $tipo->getKey(), 'Copia sintetica.', $instrucciones, true);
        app(GestionarRevisionJuridicaReglas::class)->habilitar($admin, $revisor->getKey(), $tipo->getKey(), [
            'alcance' => 'Ambito sintetico.', 'referencia_acreditacion' => 'Evidencia sintetica.', 'huella_acreditacion' => str_repeat('a', 64),
            'acreditacion_verificada' => true, 'fecha_vencimiento' => now()->addDays(3)->toIso8601String(),
        ]);
        $r = $this->revisar($revisor, $v);
        Sanctum::actingAs($admin);

        return [$admin, $revisor, $v, $r];
    }

    private function revisar($revisor, $v, ?string $huella = 'version')
    {
        return app(GestionarRevisionJuridicaReglas::class)->registrar($revisor, $v->getKey(), [
            'decision' => 'aprobado', 'observacion' => 'Decision sintetica.',
            'fuentes_verificadas' => [['referencia' => 'Fuente sintetica.', 'huella_contenido' => str_repeat('b', 64)]],
            'vigencia_comprobada_en' => now('America/La_Paz')->toDateString(), 'confirmacion_humana' => true,
            'fecha_vencimiento' => now()->addDays(2)->toIso8601String(),
        ], null, $huella === 'version' ? $v->huella_contenido : $huella);
    }

    private function consulta($v): string
    {
        return '/api/v1/reglas-audiencia/versiones/'.$v->getKey().'/preparacion-activacion';
    }

    private function alta($v): string
    {
        return '/api/v1/reglas-audiencia/versiones/'.$v->getKey().'/activaciones';
    }

    private function datos($v): array
    {
        $p = $this->getJson($this->consulta($v))->assertOk()->json('data.precondicion');

        return [...$p, 'motivo' => 'Activacion sintetica.', 'confirmacion_humana' => true];
    }

    private function dominio(): array
    {
        return array_map(fn ($t) => DB::table($t)->get()->toJson(), ['users', 'tipos_audiencia', 'etapas_audiencia',
            'propuestas_turnos_audiencia', 'simulaciones', 'intervenciones', 'jobs']);
    }
}
