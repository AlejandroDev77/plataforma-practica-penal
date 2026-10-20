<?php

namespace Tests\Feature;

use App\Models\EtapaAudiencia;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\TipoAudiencia;
use App\Models\TransicionAudiencia;
use App\Models\User;
use App\Models\VersionReglasAudiencia;
use App\Services\Simulaciones\ConsultarPreparacionVersionReglas;
use App\Services\Simulaciones\GestionarRevisionJuridicaReglas;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PreparacionVersionReglasTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'jurissim_pruebas'
            || config('database.connections.pgsql.url')
            || DB::selectOne('select current_database() as nombre')->nombre !== 'jurissim_pruebas') {
            throw new RuntimeException('Estas pruebas requieren PostgreSQL jurissim_pruebas, sin DB_URL ni cache.');
        }
        Http::preventStrayRequests();
    }

    public function test_estructura_correcta_sin_revision_sigue_bloqueada(): void
    {
        [$admin, , $version] = $this->escenario();
        $r = $this->consultar($admin, $version);
        $this->assertTrue($r['validacion_estructural']['estructura_valida']);
        $this->assertFalse($r['prerrequisitos_preliminares_cumplidos']);
        $this->assertSame('no_acreditada', $r['revision_juridica']['legal_review_status']);
        $this->assertSame(['revision_juridica_no_vigente'], $r['bloqueos_preliminares']);
        $this->assertFalse($r['execution_authorized']);
        $this->assertContains('activacion_separada_pendiente', $r['blocking_reasons']);
    }

    public function test_revision_y_estructura_validas_no_activan_y_consultas_no_escriben(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->aprobar($admin, $revisor, $version);
        $antes = $this->fotografia();
        $r = $this->consultar($admin, $version);
        $this->assertSame($r, $this->consultar($admin, $version));
        $this->assertTrue($r['read_only']);
        $this->assertTrue($r['prerrequisitos_preliminares_cumplidos']);
        $this->assertSame([], $r['bloqueos_preliminares']);
        $this->assertSame(['activacion_separada_pendiente'], $r['blocking_reasons']);
        $this->assertFalse($r['execution_authorized']);
        $this->assertFalse($r['revision_juridica']['execution_authorized']);
        $this->assertTrue($r['requires_separate_activation']);
        $this->assertSame($version->huella_contenido, $r['huella_version']);
        $this->assertSame($antes, $this->fotografia());
        Http::assertNothingSent();
    }

    public function test_revisor_habilitado_y_admin_institucional_no_reciben_acceso_tecnico(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->aprobar($admin, $revisor, $version);
        $institucional = User::factory()->create();
        $institucional->assignRole(Role::findOrCreate('administrador_institucional', 'web'));
        foreach ([$revisor, $institucional, User::factory()->create()] as $actor) {
            try {
                $this->consultar($actor, $version);
                $this->fail('La preparacion tecnica requiere administracion de plataforma.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_roles_precargados_no_omiten_la_revocacion(): void
    {
        [$admin, , $version] = $this->escenario();
        $admin->load('roles');
        DB::table('model_has_roles')->where('model_id', $admin->getKey())->delete();
        $this->expectException(AuthorizationException::class);
        $this->consultar($admin, $version);
    }

    public function test_version_inexistente_no_se_reemplaza_por_otra(): void
    {
        [$admin] = $this->escenario();
        $this->expectException(ModelNotFoundException::class);
        app(ConsultarPreparacionVersionReglas::class)->ejecutar($admin, 999999);
    }

    public function test_validacion_usa_copia_sellada_no_borrador_modificado(): void
    {
        [$admin, $revisor, $version, $tipo] = $this->escenario();
        $this->aprobar($admin, $revisor, $version);
        $antes = $this->consultar($admin, $version);
        TransicionAudiencia::query()->where('id_tipo_audiencia', $tipo->getKey())->delete();
        PropuestaTurnoAudiencia::query()->where('id_tipo_audiencia', $tipo->getKey())->update(['descripcion_propuesta' => 'Borrador distinto.']);
        $tipo->update(['activo' => false]);
        $this->assertSame($antes, $this->consultar($admin, $version));
        // La disponibilidad actual y un permiso transaccional no forman parte de esta fotografia.
        $nueva = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $tipo->getKey(), 'Copia nueva sintetica.');
        $r = $this->consultar($admin, $nueva);
        $this->assertFalse($r['prerrequisitos_preliminares_cumplidos']);
        $this->assertContains('tipo_inactivo', $r['bloqueos_preliminares']);
        $this->assertSame('no_acreditada', $r['revision_juridica']['legal_review_status']);
    }

    public function test_aprobar_version_incompleta_no_sustituye_estructura(): void
    {
        [$admin, $revisor, , $tipo] = $this->escenario();
        TransicionAudiencia::query()->where('id_tipo_audiencia', $tipo->getKey())->delete();
        $version = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $tipo->getKey(), 'Grafo incompleto sintetico.');
        $this->aprobar($admin, $revisor, $version);
        $r = $this->consultar($admin, $version);
        $this->assertTrue($r['revision_juridica']['review_approved']);
        $this->assertFalse($r['prerrequisitos_preliminares_cumplidos']);
        $this->assertContains('etapa_sin_camino_al_cierre', $r['bloqueos_preliminares']);
        $this->assertFalse($r['execution_authorized']);
    }

    public function test_ultima_decision_y_retiro_no_recuperan_aprobacion_previa(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->aprobar($admin, $revisor, $version);
        $gestor = app(GestionarRevisionJuridicaReglas::class);
        $datos = $this->datosRevision();
        $datos['decision'] = 'requiere_cambios';
        unset($datos['fecha_vencimiento']);
        $ultima = $gestor->registrar($revisor, $version->getKey(), $datos);
        $this->assertSame('requiere_cambios', $this->consultar($admin, $version)['revision_juridica']['legal_review_status']);
        $gestor->revocarRevision($revisor, $ultima->getKey(), 'Retiro sintetico.');
        $r = $this->consultar($admin, $version);
        $this->assertSame('revision_revocada', $r['revision_juridica']['legal_review_status']);
        $this->assertFalse($r['prerrequisitos_preliminares_cumplidos']);
    }

    public function test_retiro_y_renovacion_de_habilitacion_exigen_nueva_revision(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        [$habilitacion] = $this->aprobar($admin, $revisor, $version);
        app(GestionarRevisionJuridicaReglas::class)->revocarHabilitacion($admin, $habilitacion->getKey(), 'Retiro sintetico.');
        $this->assertFalse($this->consultar($admin, $version)['prerrequisitos_preliminares_cumplidos']);
        $this->habilitar($admin, $revisor, $version);
        $r = $this->consultar($admin, $version);
        $this->assertSame('habilitacion_revocada', $r['revision_juridica']['legal_review_status']);
        $this->assertFalse($r['prerrequisitos_preliminares_cumplidos']);
    }

    public function test_vencimiento_del_reloj_postgresql_invalida_diagnostico_previo(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version);
        app(GestionarRevisionJuridicaReglas::class)->registrar($revisor, $version->getKey(),
            [...$this->datosRevision(), 'fecha_vencimiento' => now()->addSeconds(2)->toIso8601String()]);
        $this->assertTrue($this->consultar($admin, $version)['prerrequisitos_preliminares_cumplidos']);
        $limite = microtime(true) + 5;
        do {
            $r = $this->consultar($admin, $version);
            if ($r['revision_juridica']['legal_review_status'] === 'revision_vencida') {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $limite);
        $this->assertSame('revision_vencida', $r['revision_juridica']['legal_review_status']);
        $this->assertFalse($r['prerrequisitos_preliminares_cumplidos']);
    }

    public function test_huella_se_comprueba_sobre_jsonb_completo_y_no_acepta_alteracion_privilegiada(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->aprobar($admin, $revisor, $version);
        DB::transaction(function () use ($version): void {
            // Solo jurissim_pruebas: simular DDL privilegiado que el propietario puede realizar.
            DB::statement('ALTER TABLE versiones_reglas_audiencia DISABLE TRIGGER versiones_reglas_inmutables');
            try {
                DB::table('versiones_reglas_audiencia')->where('id_version_reglas', $version->getKey())->update(['huella_contenido' => str_repeat('0', 64)]);
            } finally {
                DB::statement('ALTER TABLE versiones_reglas_audiencia ENABLE TRIGGER versiones_reglas_inmutables');
            }
        });
        $r = $this->consultar($admin, $version);
        $this->assertContains('huella_version_incompatible', $r['bloqueos_preliminares']);
        $this->assertFalse($r['prerrequisitos_preliminares_cumplidos']);
        $this->assertFalse($r['execution_authorized']);
    }

    public function test_contenido_sql_excesivo_se_rechaza_sin_generar_plan(): void
    {
        [$admin, , $version] = $this->escenario();
        $fila = $version->getAttributes();
        unset($fila['id_version_reglas']);
        $contenido = $version->contenido;
        $contenido['metadato_sintetico'] = str_repeat('x', 2_000_001);
        $fila['contenido'] = json_encode($contenido);
        $fila['numero_version'] = 2;
        $id = DB::table('versiones_reglas_audiencia')->insertGetId($fila, 'id_version_reglas');
        $r = app(ConsultarPreparacionVersionReglas::class)->ejecutar($admin, $id);
        $this->assertContains('contenido_excesivo', $r['bloqueos_preliminares']);
        $this->assertFalse($r['prerrequisitos_preliminares_cumplidos']);
        $this->assertArrayNotHasKey('turnos_por_etapa', $r);
    }

    private function fotografia(): array
    {
        $tablas = ['versiones_reglas_audiencia', 'revisiones_reglas_audiencia', 'habilitaciones_revision_juridica',
            'revocaciones_revision_reglas', 'revocaciones_habilitacion_juridica',
            'propuestas_turnos_audiencia', 'turnos_etapa_audiencia', 'tipos_audiencia', 'etapas_audiencia',
            'transiciones_audiencia', 'simulaciones', 'intervenciones', 'jobs'];

        return array_map(fn ($tabla) => DB::table($tabla)->get()->toJson(), $tablas);
    }

    private function consultar(User $admin, VersionReglasAudiencia $version): array
    {
        return app(ConsultarPreparacionVersionReglas::class)->ejecutar($admin, $version->getKey());
    }

    private function habilitar(User $admin, User $revisor, VersionReglasAudiencia $version)
    {
        return app(GestionarRevisionJuridicaReglas::class)->habilitar($admin, $revisor->getKey(), $version->id_tipo_audiencia, [
            'alcance' => 'Ambito sintetico para pruebas, sin valor profesional.', 'referencia_acreditacion' => 'Acreditacion sintetica.',
            'huella_acreditacion' => str_repeat('a', 64), 'acreditacion_verificada' => true,
            'fecha_vencimiento' => now()->addDays(3)->toIso8601String(),
        ]);
    }

    private function datosRevision(): array
    {
        return ['decision' => 'aprobado', 'observacion' => 'Revision sintetica sin valor juridico.',
            'fuentes_verificadas' => [['referencia' => 'Fuente sintetica.', 'huella_contenido' => str_repeat('b', 64)]],
            'vigencia_comprobada_en' => now('America/La_Paz')->toDateString(), 'confirmacion_humana' => true,
            'fecha_vencimiento' => now()->addDays(2)->toIso8601String()];
    }

    private function aprobar(User $admin, User $revisor, VersionReglasAudiencia $version): array
    {
        return [$this->habilitar($admin, $revisor, $version),
            app(GestionarRevisionJuridicaReglas::class)->registrar($revisor, $version->getKey(), $this->datosRevision())];
    }

    private function escenario(): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $revisor = User::factory()->create();
        $tipo = TipoAudiencia::create(['nombre' => 'Audiencia sintetica', 'codigo' => 'sintetica', 'activo' => true]);
        $etapas = [];
        foreach ([1, 2] as $orden) {
            $etapa = EtapaAudiencia::create(['id_tipo_audiencia' => $tipo->getKey(), 'codigo' => 'sintetica-'.$orden,
                'nombre' => 'Etapa sintetica', 'orden' => $orden, 'es_inicial' => $orden === 1, 'es_final' => $orden === 2, 'activo' => true]);
            $etapas[] = $etapa;
            $propuesta = new PropuestaTurnoAudiencia(['id_tipo_audiencia' => $tipo->getKey(), 'id_etapa' => $etapa->getKey(),
                'orden' => 1, 'rol' => 'juez', 'acto_propuesto' => 'Acto sintetico', 'descripcion_propuesta' => 'Solo para pruebas.']);
            $propuesta->id_usuario_creador = $admin->getKey();
            $propuesta->save();
        }
        TransicionAudiencia::create(['id_tipo_audiencia' => $tipo->getKey(), 'id_etapa_origen' => $etapas[0]->getKey(),
            'id_etapa_destino' => $etapas[1]->getKey(), 'activo' => true]);
        $version = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $tipo->getKey(), 'Version sintetica.');

        return [$admin, $revisor, $version, $tipo];
    }
}
