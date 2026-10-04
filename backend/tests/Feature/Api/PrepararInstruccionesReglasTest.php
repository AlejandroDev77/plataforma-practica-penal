<?php

namespace Tests\Feature\Api;

use App\Models\EtapaAudiencia;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\TipoAudiencia;
use App\Models\User;
use App\Models\VersionReglasAudiencia;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PrepararInstruccionesReglasTest extends TestCase
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

    public function test_preparacion_exige_sesion_y_administracion_actual(): void
    {
        [$admin, $tipo] = $this->escenario();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->consulta($tipo))->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($this->consulta($tipo))->assertForbidden();
        $this->getJson($this->consulta($tipo, 99999))->assertForbidden();
        $this->postJson($this->sellado($tipo), ['instrucciones' => []])->assertForbidden();
        Sanctum::actingAs($admin);
        $admin->removeRole('administrador_plataforma');
        $this->getJson($this->consulta($tipo))->assertForbidden();
    }

    public function test_consulta_conserva_catalogo_y_no_escribe_ni_expone_instrucciones_referencia(): void
    {
        [, $tipo, $p] = $this->escenario();
        $antes = $p->fresh()->getAttributes();
        $this->getJson($this->consulta($tipo))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.estructura_valida', true)->assertJsonPath('data.execution_authorized', false)
            ->assertJsonPath('data.contenido.propuestas.0.id_propuesta_turno', $p->getKey())
            ->assertJsonMissingPath('data.contenido.turnos_catalogo_referencia')
            ->assertJsonMissingPath('data.contenido.contrato_ejecucion');
        $this->assertSame($antes, $p->fresh()->getAttributes());
        $this->assertDatabaseCount('versiones_reglas_audiencia', 0);
        Http::assertNothingSent();
    }

    public function test_sella_texto_exacto_con_autoria_servidor_y_sin_revisar_o_activar(): void
    {
        [$admin, $tipo, $p] = $this->escenario();
        $antes = $p->fresh()->getAttributes();
        $datos = $this->datos($tipo, $p);
        $texto = "  Instruccion sintetica.\n<script>No ejecutar.</script>  ";
        $datos['instrucciones'][0]['texto'] = $texto;
        $respuesta = $this->postJson($this->sellado($tipo), [...$datos, 'id_usuario_autor' => 99999, 'estado' => 'activo',
            'execution_authorized' => true, 'contenido' => ['falso' => true]])
            ->assertCreated()->assertJsonPath('data.id_usuario_autor', $admin->getKey())
            ->assertJsonPath('data.contenido.contrato_ejecucion.instrucciones.0.texto', $texto)
            ->assertJsonPath('data.revision_juridica.execution_authorized', false)
            ->assertJsonPath('data.revision_juridica.review_approved', false);
        $v = VersionReglasAudiencia::findOrFail($respuesta->json('data.id'));
        $this->assertSame($texto, $v->contenido['contrato_ejecucion']['instrucciones'][0]['texto']);
        $hash = DB::selectOne("SELECT encode(sha256(convert_to(contenido::text, 'UTF8')), 'hex') h FROM versiones_reglas_audiencia WHERE id_version_reglas = ?", [$v->getKey()]);
        $this->assertSame($hash->h, $v->huella_contenido);
        $this->assertSame($antes, $p->fresh()->getAttributes());
        foreach (['revisiones_reglas_audiencia', 'activaciones_reglas_audiencia', 'habilitaciones_revision_juridica', 'simulaciones', 'jobs'] as $tabla) {
            $this->assertDatabaseCount($tabla, 0);
        }
        Http::assertNothingSent();
    }

    public function test_confirmacion_estricta_y_huella_son_obligatorias(): void
    {
        [, $tipo, $p] = $this->escenario();
        $datos = $this->datos($tipo, $p);
        foreach ([false, 1, 'true'] as $valor) {
            $this->postJson($this->sellado($tipo), [...$datos, 'confirmacion_instrucciones' => $valor])
                ->assertUnprocessable()->assertJsonValidationErrors('confirmacion_instrucciones');
        }
        foreach (['confirmacion_instrucciones', 'huella_borrador_esperada'] as $campo) {
            $incompleto = $datos;
            unset($incompleto[$campo]);
            $this->postJson($this->sellado($tipo), $incompleto)->assertUnprocessable()->assertJsonValidationErrors($campo);
        }
        $this->assertDatabaseCount('versiones_reglas_audiencia', 0);
    }

    public function test_instrucciones_invalidas_ajenas_y_campos_adicionales_no_se_sellan(): void
    {
        [, $tipo, $p] = $this->escenario();
        $datos = $this->datos($tipo, $p);
        foreach ([
            null, [], [['id_propuesta_turno' => (string) $p->getKey(), 'texto' => 'Sintetico.']],
            [['id_propuesta_turno' => $p->getKey(), 'texto' => '   ']],
            [['id_propuesta_turno' => $p->getKey(), 'texto' => 'Sintetico.', 'rol' => 'juez']],
            [['id_propuesta_turno' => $p->getKey(), 'texto' => 'Uno.'], ['id_propuesta_turno' => $p->getKey(), 'texto' => 'Dos.']],
        ] as $instrucciones) {
            $this->postJson($this->sellado($tipo), [...$datos, 'instrucciones' => $instrucciones])->assertUnprocessable();
        }
        $this->postJson($this->sellado($tipo), [...$datos, 'instrucciones' => [['id_propuesta_turno' => 99999, 'texto' => 'Ajeno.']]])->assertConflict();
        $this->assertDatabaseCount('versiones_reglas_audiencia', 0);
    }

    public function test_huella_falsa_y_cambio_de_contenido_requieren_consulta_nueva(): void
    {
        [, $tipo, $p] = $this->escenario();
        $datos = $this->datos($tipo, $p);
        $this->postJson($this->sellado($tipo), [...$datos, 'huella_borrador_esperada' => str_repeat('f', 64)])->assertConflict();
        $p->descripcion_propuesta = 'Contenido sintetico modificado.';
        $p->save();
        $this->postJson($this->sellado($tipo), $datos)->assertConflict();
        $this->assertNotSame($datos['huella_borrador_esperada'], $this->getJson($this->consulta($tipo))->json('data.huella_borrador'));
        $this->assertDatabaseCount('versiones_reglas_audiencia', 0);
    }

    public function test_mismo_borrador_no_produce_copias_duplicadas_y_nueva_consulta_si(): void
    {
        [, $tipo, $p] = $this->escenario();
        $datos = $this->datos($tipo, $p);
        $this->postJson($this->sellado($tipo), $datos)->assertCreated();
        $this->postJson($this->sellado($tipo), $datos)->assertConflict();
        $this->postJson($this->sellado($tipo), $this->datos($tipo, $p))->assertCreated()->assertJsonPath('data.numero_version', 2);
        $this->assertDatabaseCount('versiones_reglas_audiencia', 2);
    }

    public function test_sellado_historico_intermedio_tambien_invalida_la_preparacion(): void
    {
        [$admin, $tipo, $p] = $this->escenario();
        $datos = $this->datos($tipo, $p);
        app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $tipo->getKey(), 'Copia historica sintetica.');
        $this->postJson($this->sellado($tipo), $datos)->assertConflict();
        $this->assertDatabaseCount('versiones_reglas_audiencia', 1);
    }

    public function test_borrador_incompatible_se_consulta_sin_aparentar_que_puede_sellarse_con_instrucciones(): void
    {
        [, $tipo, $p] = $this->escenario();
        $tipo->activo = false;
        $tipo->save();
        $this->getJson($this->consulta($tipo))->assertOk()->assertJsonPath('data.estructura_valida', false);
        $this->postJson($this->sellado($tipo), $this->datos($tipo, $p))->assertConflict();
        $this->assertDatabaseCount('versiones_reglas_audiencia', 0);
    }

    public function test_copia_historica_sigue_sin_instrucciones_y_rechaza_cobertura_inventada(): void
    {
        [, $tipo] = $this->escenario();
        $this->postJson($this->sellado($tipo), ['motivo' => 'Historica.', 'huella_borrador_esperada' => str_repeat('a', 64)])
            ->assertUnprocessable()->assertJsonValidationErrors('huella_borrador_esperada');
        $this->postJson($this->sellado($tipo), ['motivo' => 'Historica.'])->assertCreated()
            ->assertJsonMissingPath('data.contenido.contrato_ejecucion')->assertJsonPath('data.revision_instrucciones.estado', 'ausente');
    }

    public function test_limite_de_caracteres_unicode_no_recorta_el_texto(): void
    {
        [, $tipo, $p] = $this->escenario();
        $datos = $this->datos($tipo, $p);
        $datos['instrucciones'][0]['texto'] = str_repeat('😀', 5001);
        $this->postJson($this->sellado($tipo), $datos)->assertUnprocessable()->assertJsonValidationErrors('instrucciones.0.texto');
        $datos['instrucciones'][0]['texto'] = str_repeat('😀', 5000);
        $this->postJson($this->sellado($tipo), $datos)->assertCreated()
            ->assertJsonPath('data.contenido.contrato_ejecucion.instrucciones.0.texto', $datos['instrucciones'][0]['texto']);
    }

    public function test_perder_administracion_tras_consultar_impide_sellar(): void
    {
        [$admin, $tipo, $p] = $this->escenario();
        $datos = $this->datos($tipo, $p);
        $admin->removeRole('administrador_plataforma');
        $this->postJson($this->sellado($tipo), $datos)->assertForbidden();
        $this->assertDatabaseCount('versiones_reglas_audiencia', 0);
    }

    private function escenario(): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $tipo = TipoAudiencia::create(['codigo' => 'sintetica', 'nombre' => 'Audiencia sintetica', 'activo' => true]);
        $etapa = EtapaAudiencia::create(['id_tipo_audiencia' => $tipo->getKey(), 'codigo' => 'unica', 'nombre' => 'Etapa sintetica',
            'orden' => 1, 'es_inicial' => true, 'es_final' => true, 'activo' => true]);
        $p = new PropuestaTurnoAudiencia(['id_tipo_audiencia' => $tipo->getKey(), 'id_etapa' => $etapa->getKey(), 'orden' => 1,
            'rol' => 'abogado_defensor', 'acto_propuesto' => 'Acto sintetico.', 'descripcion_propuesta' => 'No convertir automaticamente este texto.']);
        $p->id_usuario_creador = $admin->getKey();
        $p->save();
        Sanctum::actingAs($admin);

        return [$admin, $tipo, $p];
    }

    private function consulta($tipo, ?int $id = null): string
    {
        return '/api/v1/reglas-audiencia/tipos/'.($id ?? $tipo->getKey()).'/preparacion-instrucciones';
    }

    private function sellado($tipo): string
    {
        return '/api/v1/reglas-audiencia/tipos/'.$tipo->getKey().'/versiones';
    }

    private function datos($tipo, $p): array
    {
        return ['motivo' => 'Copia sintetica con instrucciones.', 'confirmacion_instrucciones' => true,
            'huella_borrador_esperada' => $this->getJson($this->consulta($tipo))->assertOk()->json('data.huella_borrador'),
            'instrucciones' => [['id_propuesta_turno' => $p->getKey(), 'texto' => 'Instruccion escrita expresamente.']]];
    }
}
