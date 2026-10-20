<?php

namespace Tests\Feature\Api;

use App\Models\EtapaAudiencia;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\TipoAudiencia;
use App\Models\User;
use App\Services\Simulaciones\GestionarRevisionJuridicaReglas;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RevisionInstruccionesReglasTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/reglas-audiencia';

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

    public function test_detalle_muestra_instruccion_exacta_sin_recortar_y_listado_no_la_expone(): void
    {
        [, , $v] = $this->escenario();
        $contenido = $v->contenido;
        $this->getJson(self::BASE.'/versiones/'.$v->getKey())->assertOk()
            ->assertJsonPath('data.revision_instrucciones.estado', 'disponible')
            ->assertJsonPath('data.revision_instrucciones.requiere_confirmacion', true)
            ->assertJsonPath('data.contenido.contrato_ejecucion', $contenido['contrato_ejecucion'])
            ->assertJsonPath('data.huella_contenido', $v->huella_contenido)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(self::BASE.'/versiones')->assertOk()->assertJsonMissingPath('data.0.contenido')
            ->assertJsonMissingPath('data.0.revision_instrucciones');
        $this->assertSame($contenido, $v->fresh()->contenido);
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 0);
        Http::assertNothingSent();
    }

    public function test_acceso_ajeno_se_rechaza_antes_de_validar_o_mostrar_instrucciones(): void
    {
        [, , $v] = $this->escenario();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson(self::BASE.'/versiones/'.$v->getKey())->assertNotFound();
        $this->postJson($this->ruta($v), ['confirmacion_instrucciones' => true])->assertNotFound();
        $this->getJson(self::BASE.'/versiones')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_confirmacion_especifica_debe_ser_true_y_huella_obligatoria(): void
    {
        [, , $v] = $this->escenario();
        $datos = $this->datos($v);
        unset($datos['confirmacion_instrucciones']);
        $this->postJson($this->ruta($v), $datos)->assertUnprocessable()->assertJsonValidationErrors('confirmacion_instrucciones');
        foreach ([false, 1, 'true'] as $valor) {
            $this->postJson($this->ruta($v), [...$this->datos($v), 'confirmacion_instrucciones' => $valor])
                ->assertUnprocessable()->assertJsonValidationErrors('confirmacion_instrucciones');
        }
        $datos = $this->datos($v);
        unset($datos['huella_ejecucion_revisada']);
        $this->postJson($this->ruta($v), $datos)->assertUnprocessable()->assertJsonValidationErrors('huella_ejecucion_revisada');
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 0);
    }

    public function test_huella_ajena_y_lectura_desactualizada_no_crean_decisiones(): void
    {
        [, , $v] = $this->escenario();
        $datos = $this->datos($v);
        $this->postJson($this->ruta($v), [...$datos, 'huella_ejecucion_revisada' => str_repeat('f', 64)])->assertConflict();
        $this->postJson($this->ruta($v), $datos)->assertCreated();
        $this->postJson($this->ruta($v), $datos)->assertConflict();
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 1);
    }

    public function test_revision_cubre_instrucciones_pero_no_activa_ni_cambia_autoria(): void
    {
        [$admin, $revisor, $v] = $this->escenario();
        $antes = $v->getAttributes();
        $respuesta = $this->postJson($this->ruta($v), [...$this->datos($v),
            'id_usuario_revisor' => $admin->getKey(), 'id_habilitacion' => 99999,
            'contrato_ejecucion' => ['instrucciones' => []], 'execution_authorized' => true,
        ])->assertCreated()->assertJsonPath('data.huella_ejecucion_revisada', $v->huella_contenido)
            ->assertJsonPath('data.id_usuario_revisor', $revisor->getKey())->assertJsonPath('data.execution_authorized', false);
        $this->getJson($this->ruta($v))->assertOk()->assertJsonPath('data.0.huella_ejecucion_revisada', $v->huella_contenido)
            ->assertJsonPath('data.0.id', $respuesta->json('data.id'));
        $this->getJson(self::BASE.'/versiones/'.$v->getKey())->assertOk()->assertJsonPath('data.revision_juridica.review_approved', true)
            ->assertJsonPath('data.revision_juridica.execution_authorized', false);
        $this->assertSame($antes, $v->fresh()->getAttributes());
        foreach (['activaciones_reglas_audiencia', 'simulaciones', 'turnos_etapa_audiencia', 'jobs'] as $tabla) {
            $this->assertDatabaseCount($tabla, 0);
        }
        Http::assertNothingSent();
    }

    public function test_requiere_cambios_tambien_incluye_cobertura_y_no_exige_vencimiento(): void
    {
        [, , $v] = $this->escenario();
        $datos = $this->datos($v);
        unset($datos['fecha_vencimiento']);
        $this->postJson($this->ruta($v), [...$datos, 'decision' => 'requiere_cambios'])->assertCreated()
            ->assertJsonPath('data.decision', 'requiere_cambios')->assertJsonPath('data.fecha_vencimiento', null)
            ->assertJsonPath('data.huella_ejecucion_revisada', $v->huella_contenido);
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 0);
    }

    public function test_una_copia_historica_no_admite_cobertura_inventada(): void
    {
        [, , $v] = $this->escenario(false);
        $datos = $this->datos($v);
        $this->getJson(self::BASE.'/versiones/'.$v->getKey())->assertOk()
            ->assertJsonPath('data.revision_instrucciones.estado', 'ausente')->assertJsonPath('data.revision_instrucciones.requiere_confirmacion', false)
            ->assertJsonMissingPath('data.contenido.contrato_ejecucion');
        $this->postJson($this->ruta($v), $datos)->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmacion_instrucciones', 'huella_ejecucion_revisada']);
        unset($datos['confirmacion_instrucciones'], $datos['huella_ejecucion_revisada']);
        $this->postJson($this->ruta($v), $datos)->assertCreated()->assertJsonPath('data.huella_ejecucion_revisada', null);
    }

    public function test_contrato_desconocido_o_incompleto_no_se_filtra_para_aparentar_lectura_valida(): void
    {
        [$admin, , $v] = $this->escenario();
        foreach ([['perfil' => 'futuro'], ['instrucciones' => []], ['dato_privado' => 'No serializar campos desconocidos.']] as $cambio) {
            $contenido = $v->contenido;
            $contenido['contrato_ejecucion'] = [...$contenido['contrato_ejecucion'], ...$cambio];
            $fila = $v->getAttributes();
            unset($fila['id_version_reglas']);
            $fila['contenido'] = json_encode($contenido, JSON_THROW_ON_ERROR);
            $fila['numero_version'] = 1 + DB::table('versiones_reglas_audiencia')->max('numero_version');
            $id = DB::table('versiones_reglas_audiencia')->insertGetId($fila, 'id_version_reglas');
            $detalle = $this->getJson(self::BASE.'/versiones/'.$id)->assertOk()
                ->assertJsonPath('data.revision_instrucciones.estado', 'incompatible')->assertJsonPath('data.revision_instrucciones.requiere_confirmacion', true)
                ->assertJsonMissingPath('data.contenido.contrato_ejecucion');
            $datos = [...$this->datos($v), ...$detalle->json('data.precondicion_revision'), 'huella_ejecucion_revisada' => $detalle->json('data.huella_contenido')];
            $this->postJson(self::BASE.'/versiones/'.$id.'/revisiones', $datos)->assertConflict();
        }
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 0);
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 0);
    }

    public function test_admin_sin_habilitacion_no_aprueba_el_contrato(): void
    {
        [$admin, , $v] = $this->escenario();
        Sanctum::actingAs($admin);
        $this->postJson($this->ruta($v), $this->datos($v))->assertForbidden();
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 0);
    }

    public function test_retiro_de_habilitacion_bloquea_un_formulario_previamente_abierto(): void
    {
        [$admin, , $v, $h] = $this->escenario();
        $datos = $this->datos($v);
        app(GestionarRevisionJuridicaReglas::class)->revocarHabilitacion($admin, $h->getKey(), 'Retiro sintetico.');
        $this->postJson($this->ruta($v), $datos)->assertNotFound();
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 0);
    }

    private function ruta($v): string
    {
        return self::BASE.'/versiones/'.$v->getKey().'/revisiones';
    }

    private function datos($v): array
    {
        $precondicion = $this->getJson(self::BASE.'/versiones/'.$v->getKey())->assertOk()->json('data.precondicion_revision');

        return [...$precondicion, 'decision' => 'aprobado', 'observacion' => 'Decision sintetica sin valor juridico.',
            'fuentes_verificadas' => [['referencia' => 'Fuente sintetica.', 'huella_contenido' => str_repeat('a', 64)]],
            'vigencia_comprobada_en' => now('America/La_Paz')->toDateString(), 'confirmacion_humana' => true,
            'fecha_vencimiento' => now()->addDays(2)->toIso8601String(), 'confirmacion_instrucciones' => true,
            'huella_ejecucion_revisada' => $v->huella_contenido];
    }

    private function escenario(bool $contrato = true): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $revisor = User::factory()->create();
        $tipo = TipoAudiencia::create(['codigo' => 'sintetica', 'nombre' => 'Audiencia sintetica', 'activo' => true]);
        $etapa = EtapaAudiencia::create(['id_tipo_audiencia' => $tipo->getKey(), 'codigo' => 'unica', 'nombre' => 'Etapa sintetica',
            'orden' => 1, 'es_inicial' => true, 'es_final' => true, 'activo' => true]);
        $p = new PropuestaTurnoAudiencia(['id_tipo_audiencia' => $tipo->getKey(), 'id_etapa' => $etapa->getKey(), 'orden' => 1,
            'rol' => 'abogado_defensor', 'acto_propuesto' => 'Acto sintetico.', 'descripcion_propuesta' => 'Solo para pruebas.']);
        $p->id_usuario_creador = $admin->getKey();
        $p->save();
        $instrucciones = [['id_propuesta_turno' => $p->getKey(), 'texto' => "  Texto sintetico.\n<script>No interpretar como HTML.</script>  "]];
        $v = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $tipo->getKey(), 'Copia sintetica.', $contrato ? $instrucciones : null, $contrato);
        $h = app(GestionarRevisionJuridicaReglas::class)->habilitar($admin, $revisor->getKey(), $tipo->getKey(), [
            'alcance' => 'Ambito sintetico.', 'referencia_acreditacion' => 'Acreditacion sintetica.', 'huella_acreditacion' => str_repeat('b', 64),
            'acreditacion_verificada' => true, 'fecha_vencimiento' => now()->addDays(3)->toIso8601String(),
        ]);
        Sanctum::actingAs($revisor);

        return [$admin, $revisor, $v, $h];
    }
}
