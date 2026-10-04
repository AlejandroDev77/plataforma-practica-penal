<?php

namespace Tests\Feature\Api;

use App\Models\EtapaAudiencia;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\TipoAudiencia;
use App\Models\User;
use App\Models\VersionReglasAudiencia;
use App\Services\Simulaciones\GestionarRevisionJuridicaReglas;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PDOException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GobiernoReglasAudienciaTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/reglas-audiencia';

    protected function beforeRefreshingDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'jurissim_pruebas'
            || config('database.connections.pgsql.url')
            || DB::selectOne('select current_database() as nombre')->nombre !== 'jurissim_pruebas') {
            throw new RuntimeException('Las pruebas requieren PostgreSQL jurissim_pruebas, sin DB_URL ni cache.');
        }
        Http::preventStrayRequests();
    }

    public function test_todas_las_operaciones_exigen_sesion(): void
    {
        foreach (['/versiones', '/versiones/1', '/versiones/1/revisiones', '/habilitaciones', '/habilitaciones/1'] as $ruta) {
            $this->getJson(self::BASE.$ruta)->assertUnauthorized();
        }
        foreach (['/tipos/1/versiones', '/habilitaciones', '/habilitaciones/1/revocaciones', '/versiones/1/revisiones', '/revisiones/1/revocaciones'] as $ruta) {
            $this->postJson(self::BASE.$ruta, [])->assertUnauthorized();
        }
    }

    public function test_cuenta_normal_no_consulta_versiones_ni_acreditaciones_ajenas(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $habilitacion = $this->habilitar($admin, $revisor, $version);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson(self::BASE.'/versiones')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'/versiones/'.$version->getKey())->assertNotFound();
        $this->getJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones')->assertNotFound();
        $this->getJson(self::BASE.'/habilitaciones')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'/habilitaciones/'.$habilitacion->getKey())->assertNotFound();
    }

    public function test_solo_admin_sella_sin_confiar_en_autor_contenido_o_estado_del_cliente(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        Sanctum::actingAs($revisor);
        $this->postJson(self::BASE.'/tipos/'.$version->id_tipo_audiencia.'/versiones', ['motivo' => 'Sintetico'])->assertForbidden();
        Sanctum::actingAs($admin);
        $this->postJson(self::BASE.'/tipos/'.$version->id_tipo_audiencia.'/versiones', [
            'motivo' => 'Segunda version sintetica.', 'id_usuario_autor' => $revisor->getKey(),
            'contenido' => ['falso' => true], 'huella_contenido' => str_repeat('f', 64), 'estado' => 'activo',
        ])->assertCreated()->assertJsonPath('data.id_usuario_autor', $admin->getKey())
            ->assertJsonPath('data.numero_version', 2)->assertJsonPath('data.contenido.formato', 1)
            ->assertJsonPath('data.revision_juridica.execution_authorized', false);
        $this->assertDatabaseCount('versiones_reglas_audiencia', 2);
        $this->assertDatabaseCount('turnos_etapa_audiencia', 0);
        Http::assertNothingSent();
    }

    public function test_rol_admin_retirado_no_persiste_por_cache_de_sesion(): void
    {
        [$admin, , $version] = $this->escenario();
        $admin->load('roles');
        Sanctum::actingAs($admin);
        DB::table('model_has_roles')->where('model_id', $admin->getKey())->delete();
        $this->postJson(self::BASE.'/tipos/'.$version->id_tipo_audiencia.'/versiones', ['motivo' => 'Sintetico'])->assertForbidden();
        $this->getJson(self::BASE.'/versiones/'.$version->getKey())->assertNotFound();
    }

    public function test_habilitacion_http_exige_admin_evidencia_y_confirmacion_estricta(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $datos = $this->datosHabilitacion($revisor, $version);
        Sanctum::actingAs($revisor);
        $this->postJson(self::BASE.'/habilitaciones', $datos)->assertForbidden();
        Sanctum::actingAs($admin);
        $this->postJson(self::BASE.'/habilitaciones', [...$datos, 'acreditacion_verificada' => 1])->assertUnprocessable();
        $this->postJson(self::BASE.'/habilitaciones', [...$datos, 'referencia_acreditacion' => ' '])->assertUnprocessable();
        $this->postJson(self::BASE.'/habilitaciones', [...$datos, 'id_usuario_revisor' => $admin->getKey()])->assertConflict();
        $this->postJson(self::BASE.'/habilitaciones', [...$datos, 'id_usuario_habilitador' => $revisor->getKey(), 'rol' => 'administrador_plataforma'])
            ->assertCreated()->assertJsonPath('data.id_usuario_habilitador', $admin->getKey())
            ->assertJsonPath('data.estado', 'vigente')->assertJsonPath('data.execution_authorized', false);
        $this->assertFalse($revisor->fresh()->hasRole('administrador_plataforma'));
        $this->postJson(self::BASE.'/habilitaciones', $datos)->assertConflict();
        $this->assertDatabaseCount('habilitaciones_revision_juridica', 1);
    }

    public function test_revisor_solo_ve_versiones_del_tipo_habilitado_y_su_propia_acreditacion(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        [, $otroRevisor, $otra] = $this->escenario('otra');
        $propia = $this->habilitar($admin, $revisor, $version);
        $ajena = $this->habilitar($admin, $otroRevisor, $version);
        Sanctum::actingAs($revisor);
        $this->getJson(self::BASE.'/versiones')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.contenido');
        $this->getJson(self::BASE.'/versiones/'.$version->getKey())->assertOk()->assertJsonPath('data.contenido.formato', 1);
        $this->getJson(self::BASE.'/versiones/'.$otra->getKey())->assertNotFound();
        $this->getJson(self::BASE.'/habilitaciones?id_usuario_revisor='.$otroRevisor->getKey())->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $propia->getKey());
        $this->getJson(self::BASE.'/habilitaciones/'.$ajena->getKey())->assertNotFound();
    }

    public function test_rol_juridico_o_ids_falsificados_no_sustituyen_habilitacion(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $revisor->assignRole(Role::findOrCreate('revisor_juridico', 'web'));
        Sanctum::actingAs($revisor);
        $this->postJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones', [
            ...$this->datosRevision(), 'id_usuario_revisor' => $admin->getKey(), 'id_habilitacion' => 1,
        ])->assertNotFound();
        Sanctum::actingAs($admin);
        $this->postJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones', $this->revisionHttp($version))->assertForbidden();
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 0);
    }

    public function test_decision_http_conserva_contexto_servidor_y_no_activa_el_motor(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $hab = $this->habilitar($admin, $revisor, $version);
        Sanctum::actingAs($revisor);
        $antes = $version->fresh()->getAttributes();
        $this->postJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones', [
            ...$this->revisionHttp($version), 'id_usuario_revisor' => $admin->getKey(), 'id_habilitacion' => 999,
            'id_tipo_audiencia' => 999, 'huella_version' => str_repeat('f', 64), 'alcance_revisado' => 'Falso', 'execution_authorized' => true,
        ])->assertCreated()->assertJsonPath('data.id_usuario_revisor', $revisor->getKey())
            ->assertJsonPath('data.id_habilitacion', $hab->getKey())->assertJsonPath('data.huella_version', $version->huella_contenido)
            ->assertJsonPath('data.alcance_revisado', $hab->alcance)->assertJsonPath('data.execution_authorized', false);
        $this->getJson(self::BASE.'/versiones/'.$version->getKey())->assertOk()
            ->assertJsonPath('data.revision_juridica.review_approved', true)
            ->assertJsonPath('data.revision_juridica.execution_authorized', false);
        $this->getJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.referencia_acreditacion');
        $this->assertSame($antes, $version->fresh()->getAttributes());
        $this->assertDatabaseCount('simulaciones', 0);
        $this->assertDatabaseCount('turnos_etapa_audiencia', 0);
        Http::assertNothingSent();
    }

    public function test_no_se_revisa_autoria_propia_ni_version_con_autor_desconocido(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $otroAdmin = User::factory()->create();
        $otroAdmin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $this->habilitar($otroAdmin, $admin, $version);
        Sanctum::actingAs($admin);
        $this->postJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones', $this->revisionHttp($version))->assertConflict();
        PropuestaTurnoAudiencia::query()->where('id_tipo_audiencia', $version->id_tipo_audiencia)->update(['id_usuario_creador' => null]);
        $desconocida = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $version->id_tipo_audiencia, 'Prueba sin autoria conocida.');
        $this->habilitar($admin, $revisor, $version);
        Sanctum::actingAs($revisor);
        $this->postJson(self::BASE.'/versiones/'.$desconocida->getKey().'/revisiones', $this->revisionHttp($desconocida))->assertConflict();
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 0);
    }

    public function test_revision_exige_confirmacion_fuentes_y_precondicion(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version);
        Sanctum::actingAs($revisor);
        $this->postJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones', $this->datosRevision())->assertUnprocessable();
        foreach ([['confirmacion_humana' => 'true'], ['fuentes_verificadas' => []],
            ['fecha_vencimiento' => '2030-01-01'], ['id_ultima_revision_esperada' => 0], ['estado_revision_esperado' => 'activo']] as $cambios) {
            $this->postJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones', [...$this->revisionHttp($version), ...$cambios])->assertUnprocessable();
        }
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 0);
    }

    public function test_precondicion_rechaza_hash_distinto_reintento_y_decision_desactualizada(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version);
        Sanctum::actingAs($revisor);
        $datos = $this->revisionHttp($version);
        $ruta = self::BASE.'/versiones/'.$version->getKey().'/revisiones';
        $this->postJson($ruta, [...$datos, 'huella_version_esperada' => str_repeat('f', 64)])->assertConflict();
        $this->postJson($ruta, $datos)->assertCreated();
        $this->postJson($ruta, $datos)->assertConflict();
        $this->postJson($ruta, [...$datos, 'decision' => 'requiere_cambios', 'fecha_vencimiento' => null])->assertConflict();
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 1);
        $frescos = $this->revisionHttp($version);
        $this->postJson($ruta, [...$frescos, 'decision' => 'requiere_cambios', 'fecha_vencimiento' => null])->assertCreated();
        $this->getJson(self::BASE.'/versiones/'.$version->getKey())->assertOk()
            ->assertJsonPath('data.revision_juridica.legal_review_status', 'requiere_cambios');
    }

    public function test_revocacion_cambia_la_precondicion_y_no_resucita_aprobaciones_antiguas(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version);
        Sanctum::actingAs($revisor);
        $revision = $this->postJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones', $this->revisionHttp($version))->assertCreated()->json('data.id');
        $antes = $this->revisionHttp($version);
        $this->postJson(self::BASE.'/revisiones/'.$revision.'/revocaciones', ['motivo' => 'Retiro sintetico.'])->assertOk();
        $this->postJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones', $antes)->assertConflict();
        $this->getJson(self::BASE.'/versiones/'.$version->getKey())->assertOk()
            ->assertJsonPath('data.revision_juridica.legal_review_status', 'revision_revocada');
    }

    public function test_revocar_habilitacion_quita_acceso_pero_conserva_historial_propio(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $hab = $this->habilitar($admin, $revisor, $version);
        Sanctum::actingAs($revisor);
        $datos = $this->revisionHttp($version);
        $id = $this->postJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones', $datos)->assertCreated()->json('data.id');
        $ruta = self::BASE.'/habilitaciones/'.$hab->getKey().'/revocaciones';
        $respuesta = $this->postJson($ruta, ['motivo' => 'Retiro sintetico inicial.'])->assertOk()->assertJsonPath('data.estado', 'revocada');
        $this->postJson($ruta, ['motivo' => 'Motivo que no debe reemplazarlo.'])->assertOk()
            ->assertJsonPath('data.revocacion.id', $respuesta->json('data.revocacion.id'))
            ->assertJsonPath('data.revocacion.motivo', 'Retiro sintetico inicial.');
        $this->getJson(self::BASE.'/versiones/'.$version->getKey())->assertNotFound();
        $this->postJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones', $datos)->assertNotFound();
        $this->getJson(self::BASE.'/habilitaciones/'.$hab->getKey())->assertOk()->assertJsonPath('data.estado', 'revocada');
        $this->postJson(self::BASE.'/revisiones/'.$id.'/revocaciones', ['motivo' => 'Retiro de decision tras perder permiso.'])->assertOk();
        $this->assertDatabaseCount('revocaciones_habilitacion_juridica', 1);
        $this->assertDatabaseCount('revocaciones_revision_reglas', 1);
    }

    public function test_otro_revisor_no_revoca_decision_o_habilitacion_ajena(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $hab = $this->habilitar($admin, $revisor, $version);
        $otro = User::factory()->create();
        $this->habilitar($admin, $otro, $version);
        $revision = app(GestionarRevisionJuridicaReglas::class)->registrar($revisor, $version->getKey(), $this->datosRevision());
        Sanctum::actingAs($otro);
        $this->postJson(self::BASE.'/habilitaciones/'.$hab->getKey().'/revocaciones', ['motivo' => 'No autorizado'])->assertNotFound();
        $this->postJson(self::BASE.'/revisiones/'.$revision->getKey().'/revocaciones', ['motivo' => 'No autorizado'])->assertNotFound();
        Sanctum::actingAs($admin);
        $this->postJson(self::BASE.'/revisiones/'.$revision->getKey().'/revocaciones', ['motivo' => 'Retiro administrativo sintetico.'])->assertOk();
    }

    public function test_paginacion_es_acotada_y_respuestas_no_se_almacenan(): void
    {
        [$admin, , $version] = $this->escenario();
        Sanctum::actingAs($admin);
        foreach (['/versiones', '/habilitaciones', '/versiones/'.$version->getKey().'/revisiones'] as $ruta) {
            $this->getJson(self::BASE.$ruta.'?por_pagina=51')->assertUnprocessable();
            $this->getJson(self::BASE.$ruta.'?page=0')->assertUnprocessable();
            $this->getJson(self::BASE.$ruta.'?por_pagina=1')->assertOk()->assertJsonPath('meta.per_page', 1)
                ->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->getJson(self::BASE.'/versiones/999999')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_no_hay_rutas_para_editar_o_borrar_el_historial(): void
    {
        [$admin, , $version] = $this->escenario();
        Sanctum::actingAs($admin);
        foreach (['/versiones/'.$version->getKey(), '/habilitaciones/1'] as $ruta) {
            $this->patchJson(self::BASE.$ruta, ['estado' => 'activo'])->assertStatus(405);
            $this->deleteJson(self::BASE.$ruta)->assertStatus(405);
        }
        $this->assertDatabaseCount('versiones_reglas_audiencia', 1);
    }

    public function test_error_sql_no_filtra_bindings_traza_o_acreditacion_con_debug(): void
    {
        [$admin] = $this->escenario();
        Sanctum::actingAs($admin);
        config(['app.debug' => true]);
        $codigo = '23514';
        DB::listen(function ($consulta) use (&$codigo): void {
            if (str_contains($consulta->sql, 'count(*) as aggregate') && str_contains($consulta->sql, 'versiones_reglas_audiencia')) {
                $error = new PDOException('DETALLE-SENSIBLE-SINTETICO');
                $error->errorInfo = [$codigo, null, 'ACREDITACION-SENSIBLE-SINTETICA'];
                throw new QueryException('pgsql', 'SELECT dato_privado = ?', ['ACREDITACION-SENSIBLE-SINTETICA'], $error);
            }
        });
        $respuesta = $this->getJson(self::BASE.'/versiones')->assertConflict()->assertJsonPath('code', 'reglas_conflicto');
        $this->assertStringNotContainsString('SENSIBLE', $respuesta->getContent());
        $this->assertStringNotContainsString('SELECT', $respuesta->getContent());
        $respuesta->assertJsonMissingPath('trace');
        $codigo = '08006';
        $respuesta = $this->getJson(self::BASE.'/versiones')->assertStatus(503)->assertJsonPath('code', 'reglas_no_disponibles');
        $this->assertStringNotContainsString('SENSIBLE', $respuesta->getContent());
        $respuesta->assertJsonMissingPath('trace');
    }

    public function test_escrituras_tienen_limite_de_frecuencia(): void
    {
        [$admin, , $version] = $this->escenario();
        Sanctum::actingAs($admin);
        $ruta = self::BASE.'/tipos/'.$version->id_tipo_audiencia.'/versiones';
        for ($i = 0; $i < 10; $i++) {
            $this->postJson($ruta, [])->assertUnprocessable();
        }
        $this->postJson($ruta, [])->assertStatus(429);
    }

    public function test_habilitacion_vencida_impide_lectura_y_escritura_pero_no_su_propio_retiro(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $hab = app(GestionarRevisionJuridicaReglas::class)->habilitar($admin, $revisor->getKey(), $version->id_tipo_audiencia, [
            ...$this->datosHabilitacion($revisor, $version), 'fecha_vencimiento' => now()->addSeconds(2)->toIso8601String(),
        ]);
        Sanctum::actingAs($revisor);
        $this->getJson(self::BASE.'/versiones/'.$version->getKey())->assertOk();
        $limite = microtime(true) + 5;
        do {
            $vigente = DB::table('habilitaciones_revision_juridica')->where('id_habilitacion', $hab->getKey())
                ->whereRaw('fecha_vencimiento > clock_timestamp()')->exists();
            if (! $vigente) {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $limite);
        $this->assertFalse($vigente);
        $this->getJson(self::BASE.'/versiones/'.$version->getKey())->assertNotFound();
        $this->postJson(self::BASE.'/versiones/'.$version->getKey().'/revisiones', $this->datosRevision())->assertNotFound();
        $this->getJson(self::BASE.'/habilitaciones/'.$hab->getKey())->assertOk()->assertJsonPath('data.estado', 'vencida');
        $this->postJson(self::BASE.'/habilitaciones/'.$hab->getKey().'/revocaciones', ['motivo' => 'Retiro sintetico vencido.'])
            ->assertOk()->assertJsonPath('data.estado', 'revocada');
    }

    public function test_creador_de_propuesta_no_puede_revisar_version_sellada_por_otro(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        PropuestaTurnoAudiencia::query()->where('id_tipo_audiencia', $version->id_tipo_audiencia)
            ->update(['id_usuario_creador' => $revisor->getKey()]);
        $propia = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $version->id_tipo_audiencia, 'Propuesta de otro autor sintetico.');
        $this->habilitar($admin, $revisor, $propia);
        Sanctum::actingAs($revisor);
        $this->postJson(self::BASE.'/versiones/'.$propia->getKey().'/revisiones', $this->revisionHttp($propia))->assertConflict();
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 0);
    }

    private function revisionHttp(VersionReglasAudiencia $version): array
    {
        $precondicion = $this->getJson(self::BASE.'/versiones/'.$version->getKey())->assertOk()->json('data.precondicion_revision');

        return [...$this->datosRevision(), ...$precondicion];
    }

    private function datosRevision(): array
    {
        return ['decision' => 'aprobado', 'observacion' => 'Decision SINTETICA, sin valor juridico.',
            'fuentes_verificadas' => [['referencia' => 'Fuente sintetica.', 'huella_contenido' => str_repeat('b', 64)]],
            'vigencia_comprobada_en' => now('America/La_Paz')->toDateString(), 'confirmacion_humana' => true,
            'fecha_vencimiento' => now()->addDays(2)->toIso8601String()];
    }

    private function datosHabilitacion(User $revisor, VersionReglasAudiencia $version): array
    {
        return ['id_usuario_revisor' => $revisor->getKey(), 'id_tipo_audiencia' => $version->id_tipo_audiencia,
            'alcance' => 'Ambito sintetico.', 'referencia_acreditacion' => 'ACREDITACION SINTETICA sin valor profesional.',
            'huella_acreditacion' => str_repeat('a', 64), 'acreditacion_verificada' => true,
            'fecha_vencimiento' => now()->addDays(3)->toIso8601String()];
    }

    private function habilitar(User $admin, User $revisor, VersionReglasAudiencia $version)
    {
        return app(GestionarRevisionJuridicaReglas::class)->habilitar($admin, $revisor->getKey(), $version->id_tipo_audiencia,
            $this->datosHabilitacion($revisor, $version));
    }

    private function escenario(string $sufijo = 'principal'): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $revisor = User::factory()->create();
        $tipo = TipoAudiencia::create(['codigo' => 'sintetica-'.$sufijo, 'nombre' => 'Audiencia sintetica', 'activo' => true]);
        $etapa = EtapaAudiencia::create(['id_tipo_audiencia' => $tipo->getKey(), 'codigo' => 'inicial', 'nombre' => 'Etapa sintetica',
            'orden' => 1, 'es_inicial' => true, 'activo' => true]);
        $propuesta = new PropuestaTurnoAudiencia(['id_tipo_audiencia' => $tipo->getKey(), 'id_etapa' => $etapa->getKey(),
            'orden' => 1, 'rol' => 'juez', 'acto_propuesto' => 'Acto sintetico', 'descripcion_propuesta' => 'Solo para pruebas.']);
        $propuesta->id_usuario_creador = $admin->getKey();
        $propuesta->save();
        $version = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $tipo->getKey(), 'Version SINTETICA sin valor juridico.');

        return [$admin, $revisor, $version];
    }
}
