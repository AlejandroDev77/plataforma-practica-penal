<?php

namespace Tests\Feature;

use App\Models\EtapaAudiencia;
use App\Models\HabilitacionRevisionJuridica;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\RevisionReglasAudiencia;
use App\Models\TipoAudiencia;
use App\Models\User;
use App\Models\VersionReglasAudiencia;
use App\Services\Simulaciones\ConsultarRevisionJuridicaReglas;
use App\Services\Simulaciones\GestionarRevisionJuridicaReglas;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RevisionJuridicaReglasTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_habilitacion_y_aprobacion_no_asignan_roles_ni_activan_reglas(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->assertSame('no_acreditada', $this->estado($version)['legal_review_status']);
        $habilitacion = $this->habilitar($admin, $revisor, $version);
        $antes = $version->fresh()->getAttributes();
        $revision = $this->registrar($revisor, $version);
        $this->assertSame($habilitacion->getKey(), $revision->id_habilitacion);
        $this->assertSame($version->huella_contenido, $revision->huella_version);
        $this->assertSame($habilitacion->alcance, $revision->alcance_revisado);
        $this->assertSame('aprobado', $this->estado($version)['legal_review_status']);
        $this->assertFalse($this->estado($version)['execution_authorized']);
        $this->assertSame($antes, $version->fresh()->getAttributes());
        $this->assertFalse($revisor->fresh()->hasRole('administrador_plataforma'));
        $this->assertDatabaseMissing('roles', ['name' => 'revisor_juridico']);
        $this->assertDatabaseCount('turnos_etapa_audiencia', 0);
        $this->assertDatabaseCount('simulaciones', 0);
        Http::assertNothingSent();
    }

    public function test_habilitar_exige_admin_y_no_admite_autohabilitacion(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->rechazo(AuthorizationException::class, fn () => $this->habilitar($revisor, $admin, $version));
        $this->rechazo(DomainException::class, fn () => $this->habilitar($admin, $admin, $version));
        $this->assertDatabaseCount('habilitaciones_revision_juridica', 0);
    }

    public function test_un_rol_juridico_sin_habilitacion_no_permite_revisar(): void
    {
        [, $revisor, $version] = $this->escenario();
        $revisor->assignRole(Role::findOrCreate('revisor_juridico', 'web'));
        $this->rechazo(AuthorizationException::class, fn () => $this->registrar($revisor, $version));
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 0);
    }

    public function test_revocar_rol_administrativo_no_se_omite_por_cache_del_modelo(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $admin->load('roles');
        DB::table('model_has_roles')->where('model_id', $admin->getKey())->delete();
        $this->rechazo(AuthorizationException::class, fn () => $this->habilitar($admin, $revisor, $version));
    }

    public function test_no_se_acepta_evidencia_incompleta_confirmacion_implicita_o_expiracion_invalida(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        foreach ([['acreditacion_verificada' => false], ['acreditacion_verificada' => 1],
            ['referencia_acreditacion' => ' '], ['alcance' => ''], ['huella_acreditacion' => 'inventada'],
            ['fecha_vencimiento' => now()->subDay()->toIso8601String()], ['fecha_vencimiento' => '2030-01-01']] as $cambios) {
            $this->rechazo(ValidationException::class, fn () => $this->habilitar($admin, $revisor, $version, $cambios));
        }
        $this->assertDatabaseCount('habilitaciones_revision_juridica', 0);
    }

    public function test_datos_del_cliente_no_fijan_habilitador_autor_rol_o_fecha_de_creacion(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $habilitacion = $this->habilitar($admin, $revisor, $version, [
            'id_usuario_habilitador' => $revisor->getKey(), 'id_usuario_revisor' => $admin->getKey(),
            'estado' => 'activo', 'fecha_creacion' => '2000-01-01', 'rol' => 'administrador_plataforma',
        ]);
        $this->assertSame($admin->getKey(), $habilitacion->id_usuario_habilitador);
        $this->assertSame($revisor->getKey(), $habilitacion->id_usuario_revisor);
        $this->assertNotSame('2000-01-01', $habilitacion->fecha_creacion->toDateString());
    }

    public function test_no_hay_dos_habilitaciones_vigentes_del_mismo_ambito(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $habilitacion = $this->habilitar($admin, $revisor, $version);
        $this->rechazo(DomainException::class, fn () => $this->habilitar($admin, $revisor, $version));
        $this->rechazoSql(fn () => DB::table('habilitaciones_revision_juridica')->insert($this->fila($habilitacion)));
        $this->assertDatabaseCount('habilitaciones_revision_juridica', 1);
    }

    public function test_habilitacion_para_otra_audiencia_no_concede_autoridad(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        [, , $otra] = $this->escenario('otra');
        $this->habilitar($admin, $revisor, $otra);
        $this->rechazo(AuthorizationException::class, fn () => $this->registrar($revisor, $version));
    }

    public function test_el_autor_de_la_version_no_puede_aprobarla(): void
    {
        [$admin, , $version] = $this->escenario();
        $habilitador = User::factory()->create();
        $habilitador->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $this->habilitar($habilitador, $admin, $version);
        $this->rechazo(DomainException::class, fn () => $this->registrar($admin, $version));
    }

    public function test_el_autor_de_una_propuesta_no_puede_aprobar_la_version_de_otro(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        PropuestaTurnoAudiencia::query()->where('id_tipo_audiencia', $version->id_tipo_audiencia)
            ->update(['id_usuario_creador' => $revisor->getKey()]);
        $otra = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $version->id_tipo_audiencia, 'Solo pruebas.');
        $this->habilitar($admin, $revisor, $otra);
        $this->rechazo(DomainException::class, fn () => $this->registrar($revisor, $otra));
    }

    public function test_autoria_desconocida_no_se_considera_independencia_acreditada(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        PropuestaTurnoAudiencia::query()->where('id_tipo_audiencia', $version->id_tipo_audiencia)
            ->update(['id_usuario_creador' => null]);
        $otra = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $version->id_tipo_audiencia, 'Solo pruebas.');
        $this->habilitar($admin, $revisor, $otra);
        $this->rechazo(DomainException::class, fn () => $this->registrar($revisor, $otra));
    }

    public function test_revision_exige_fuentes_fecha_y_confirmacion_humana_explicita(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version);
        foreach ([['confirmacion_humana' => false], ['confirmacion_humana' => 'true'], ['fuentes_verificadas' => []],
            ['fuentes_verificadas' => [['referencia' => 'Sin huella']]], ['observacion' => ' '],
            ['fuentes_verificadas' => ['clave' => ['referencia' => 'Fuente sintetica.', 'huella_contenido' => str_repeat('a', 64)]]],
            ['vigencia_comprobada_en' => now('America/La_Paz')->addDay()->toDateString()],
            ['decision' => 'activo'], ['fecha_vencimiento' => null]] as $cambios) {
            $this->rechazo(ValidationException::class, fn () => $this->registrar($revisor, $version, $cambios));
        }
        $datos = $this->datosRevision();
        unset($datos['fecha_vencimiento']);
        $this->rechazo(ValidationException::class, fn () => $this->gestor()->registrar($revisor, $version->getKey(), $datos));
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 0);
    }

    public function test_una_revision_no_puede_vencer_despues_de_su_habilitacion(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version);
        $this->rechazo(DomainException::class, fn () => $this->registrar($revisor, $version, [
            'fecha_vencimiento' => now()->addDays(10)->toIso8601String(),
        ]));
    }

    public function test_identidad_huella_y_alcance_se_resuelven_en_servidor(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $habilitacion = $this->habilitar($admin, $revisor, $version);
        $revision = $this->registrar($revisor, $version, [
            'id_usuario_revisor' => $admin->getKey(), 'id_habilitacion' => 99999,
            'huella_version' => str_repeat('0', 64), 'alcance_revisado' => 'Otro procedimiento',
        ]);
        $this->assertSame($revisor->getKey(), $revision->id_usuario_revisor);
        $this->assertSame($habilitacion->alcance, $revision->alcance_revisado);
        $this->assertSame($version->huella_contenido, $revision->huella_version);
    }

    public function test_requiere_cambios_posterior_bloquea_la_aprobacion_previa(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version);
        $this->registrar($revisor, $version);
        $datos = [...$this->datosRevision(), 'decision' => 'requiere_cambios'];
        unset($datos['fecha_vencimiento']);
        $this->gestor()->registrar($revisor, $version->getKey(), $datos);
        $this->assertSame('requiere_cambios', $this->estado($version)['legal_review_status']);
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 2);
    }

    public function test_revocar_una_decision_no_reactiva_una_aprobacion_anterior(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version);
        $this->registrar($revisor, $version);
        $ultima = $this->registrar($revisor, $version);
        $revocacion = $this->gestor()->revocarRevision($revisor, $ultima->getKey(), 'Retiro sintetico.');
        $reintento = $this->gestor()->revocarRevision($admin, $ultima->getKey(), 'Reintento autorizado.');
        $this->assertSame($revocacion->getKey(), $reintento->getKey());
        $this->assertSame('revision_revocada', $this->estado($version)['legal_review_status']);
        $this->assertFalse($this->estado($version)['review_approved']);
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 2);
    }

    public function test_revocar_habilitacion_invalida_aprobaciones_y_bloquea_nuevas_decisiones(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $habilitacion = $this->habilitar($admin, $revisor, $version);
        $this->registrar($revisor, $version);
        $revocacion = $this->gestor()->revocarHabilitacion($admin, $habilitacion->getKey(), 'Retiro sintetico.');
        $this->assertSame($revocacion->getKey(), $this->gestor()->revocarHabilitacion($revisor, $habilitacion->getKey(), 'Reintento.')->getKey());
        $this->assertSame('habilitacion_revocada', $this->estado($version)['legal_review_status']);
        $this->rechazo(AuthorizationException::class, fn () => $this->registrar($revisor, $version));
        $nueva = $this->habilitar($admin, $revisor, $version);
        $this->assertNotSame($habilitacion->getKey(), $nueva->getKey());
        $this->assertSame('habilitacion_revocada', $this->estado($version)['legal_review_status']);
        $this->registrar($revisor, $version);
        $this->assertSame('aprobado', $this->estado($version)['legal_review_status']);
    }

    public function test_otra_persona_no_puede_revocar_y_se_exige_motivo(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $habilitacion = $this->habilitar($admin, $revisor, $version);
        $revision = $this->registrar($revisor, $version);
        $otro = User::factory()->create();
        $this->rechazo(AuthorizationException::class, fn () => $this->gestor()->revocarRevision($otro, $revision->getKey(), 'Prueba.'));
        $this->rechazo(AuthorizationException::class, fn () => $this->gestor()->revocarHabilitacion($otro, $habilitacion->getKey(), 'Prueba.'));
        $this->rechazo(ValidationException::class, fn () => $this->gestor()->revocarRevision($revisor, $revision->getKey(), ' '));
        $this->assertSame('aprobado', $this->estado($version)['legal_review_status']);
    }

    public function test_sql_tampoco_puede_falsificar_huella_alcance_identidad_o_fuentes(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version);
        $fila = $this->fila($this->registrar($revisor, $version));
        foreach ([['huella_version' => str_repeat('0', 64)], ['alcance_revisado' => 'Otro alcance'],
            ['id_usuario_revisor' => $admin->getKey()], ['id_tipo_audiencia' => 99999], ['confirmacion_humana' => false],
            ['fuentes_verificadas' => json_encode([['referencia' => 'Sin huella']])],
            ['fecha_vencimiento' => now()->addDays(10)->toIso8601String()]] as $cambios) {
            $this->rechazoSql(fn () => DB::table('revisiones_reglas_audiencia')->insert([...$fila, ...$cambios]));
        }
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 1);
    }

    public function test_sql_rechaza_habilitacion_no_autorizada_o_sin_acreditacion(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $fila = $this->fila($this->habilitar($admin, $revisor, $version));
        $fila['id_usuario_revisor'] = User::factory()->create()->getKey();
        foreach ([['id_usuario_habilitador' => $revisor->getKey()], ['acreditacion_verificada' => false],
            ['id_usuario_revisor' => $admin->getKey()], ['fecha_vencimiento' => now()->subDay()->toIso8601String()]] as $cambios) {
            $this->rechazoSql(fn () => DB::table('habilitaciones_revision_juridica')->insert([...$fila, ...$cambios]));
        }
    }

    public function test_sql_rechaza_una_revision_con_habilitacion_revocada(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $habilitacion = $this->habilitar($admin, $revisor, $version);
        $fila = $this->fila($this->registrar($revisor, $version));
        $this->gestor()->revocarHabilitacion($admin, $habilitacion->getKey(), 'Retiro sintetico.');
        $this->rechazoSql(fn () => DB::table('revisiones_reglas_audiencia')->insert($fila));
    }

    public function test_los_cuatro_registros_no_pueden_editarse_borrarse_o_truncarse(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $habilitacion = $this->habilitar($admin, $revisor, $version);
        $revision = $this->registrar($revisor, $version);
        $revRevision = $this->gestor()->revocarRevision($revisor, $revision->getKey(), 'Retiro sintetico.');
        $revHabilitacion = $this->gestor()->revocarHabilitacion($admin, $habilitacion->getKey(), 'Retiro sintetico.');
        foreach ([$habilitacion, $revision, $revRevision, $revHabilitacion] as $registro) {
            $tabla = $registro->getTable();
            $this->rechazo(LogicException::class, fn () => $registro->delete());
            $this->rechazoSql(fn () => DB::table($tabla)->update(['fecha_creacion' => now()]));
            $this->rechazoSql(fn () => DB::table($tabla)->delete());
            // CASCADE evita que una FK produzca un falso positivo en lugar del trigger.
            $this->rechazoSql(fn () => DB::statement('TRUNCATE TABLE '.$tabla.' CASCADE'));
            $this->assertDatabaseCount($tabla, 1);
        }
    }

    public function test_los_vencimientos_se_resuelven_con_el_reloj_de_postgresql(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version, ['fecha_vencimiento' => now()->addSeconds(4)->toIso8601String()]);
        $this->registrar($revisor, $version, ['fecha_vencimiento' => now()->addSeconds(2)->toIso8601String()]);
        foreach (['revision_vencida', 'habilitacion_vencida'] as $esperado) {
            $limite = microtime(true) + 8;
            do {
                $estado = $this->estado($version)['legal_review_status'];
                if ($estado === $esperado) {
                    break;
                }
                usleep(100_000);
            } while (microtime(true) < $limite);
            $this->assertSame($esperado, $estado);
        }
        $this->rechazo(AuthorizationException::class, fn () => $this->registrar($revisor, $version));
    }

    public function test_sql_tambien_exige_independencia_con_una_habilitacion_de_contexto_valido(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version);
        $fila = $this->fila($this->registrar($revisor, $version));
        $otroAdmin = User::factory()->create();
        $otroAdmin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $habilitacionAutor = $this->habilitar($otroAdmin, $admin, $version);
        $fila['id_habilitacion'] = $habilitacionAutor->getKey();
        $fila['id_usuario_revisor'] = $admin->getKey();
        $this->rechazoSql(fn () => DB::table('revisiones_reglas_audiencia')->insert($fila));
    }

    public function test_una_vista_transaccional_antigua_no_puede_omitir_revocaciones(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $fila = $this->fila($this->habilitar($admin, $revisor, $version));
        config(['database.connections.jurissim_revision_guardia' => config('database.connections.pgsql')]);
        $conexion = DB::connection('jurissim_revision_guardia');
        $this->assertSame('jurissim_pruebas', $conexion->selectOne('select current_database() as nombre')->nombre);
        $conexion->beginTransaction();
        try {
            $conexion->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            try {
                $conexion->table('habilitaciones_revision_juridica')->insert($fila);
                $this->fail('No debe escribir con una vista transaccional antigua.');
            } catch (QueryException $error) {
                $this->assertSame('23514', $error->errorInfo[0]);
                $this->assertStringContainsString('read committed', $error->getMessage());
            }
        } finally {
            $conexion->rollBack();
            DB::purge('jurissim_revision_guardia');
        }
    }

    public function test_las_cuentas_con_historial_no_pueden_borrarse_por_cascada(): void
    {
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version);
        $this->registrar($revisor, $version);
        $this->rechazoSql(fn () => DB::table('users')->where('id', $revisor->getKey())->delete());
        $this->assertDatabaseCount('revisiones_reglas_audiencia', 1);
    }

    public function test_rollback_preserva_historial_y_solo_revierte_tablas_vacias(): void
    {
        $activacion = require database_path('migrations/2026_10_04_000001_registrar_activaciones_reglas.php');
        $activacion->down();
        $migracion = require database_path('migrations/2026_10_01_000002_registrar_revision_juridica_reglas.php');
        $migracion->down();
        $this->assertFalse(Schema::hasTable('habilitaciones_revision_juridica'));
        $migracion->up();
        $activacion->up();
        [$admin, $revisor, $version] = $this->escenario();
        $this->habilitar($admin, $revisor, $version);
        $this->rechazo(RuntimeException::class, fn () => $migracion->down());
        $this->assertDatabaseCount('habilitaciones_revision_juridica', 1);
    }

    private function rechazo(string $clase, callable $operacion): void
    {
        try {
            $operacion();
        } catch (\Exception $error) {
            $this->assertInstanceOf($clase, $error);

            return;
        }
        $this->fail('Se acepto una operacion que debe rechazarse.');
    }

    private function rechazoSql(callable $operacion): void
    {
        try {
            DB::transaction($operacion);
            $this->fail('SQL acepto una operacion que debe rechazarse.');
        } catch (QueryException $error) {
            $this->assertContains($error->errorInfo[0], ['23514', '23503', '23505', '42501']);
        }
    }

    private function fila($registro): array
    {
        $fila = $registro->getAttributes();
        unset($fila[$registro->getKeyName()]);

        return $fila;
    }

    private function gestor(): GestionarRevisionJuridicaReglas
    {
        return app(GestionarRevisionJuridicaReglas::class);
    }

    private function estado(VersionReglasAudiencia $version): array
    {
        return app(ConsultarRevisionJuridicaReglas::class)->ejecutar($version->getKey());
    }

    private function habilitar(User $admin, User $revisor, VersionReglasAudiencia $version, array $cambios = []): HabilitacionRevisionJuridica
    {
        return $this->gestor()->habilitar($admin, $revisor->getKey(), $version->id_tipo_audiencia, [
            'alcance' => 'Ambito exclusivamente sintetico.', 'referencia_acreditacion' => 'ACREDITACION SINTETICA sin valor profesional.',
            'huella_acreditacion' => str_repeat('a', 64), 'acreditacion_verificada' => true,
            'fecha_vencimiento' => now()->addDays(3)->toIso8601String(), ...$cambios,
        ]);
    }

    private function datosRevision(): array
    {
        return [
            'decision' => 'aprobado', 'observacion' => 'Revision SINTETICA sin valor juridico.',
            'fuentes_verificadas' => [['referencia' => 'Fuente exclusivamente sintetica.', 'huella_contenido' => str_repeat('b', 64)]],
            'vigencia_comprobada_en' => now('America/La_Paz')->toDateString(), 'confirmacion_humana' => true,
            'fecha_vencimiento' => now()->addDays(2)->toIso8601String(),
        ];
    }

    private function registrar(User $revisor, VersionReglasAudiencia $version, array $cambios = []): RevisionReglasAudiencia
    {
        return $this->gestor()->registrar($revisor, $version->getKey(), [...$this->datosRevision(), ...$cambios]);
    }

    private function escenario(string $sufijo = 'principal'): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $revisor = User::factory()->create();
        $tipo = TipoAudiencia::create(['nombre' => 'Audiencia sintetica', 'codigo' => 'sintetica-'.$sufijo, 'activo' => true]);
        $etapa = EtapaAudiencia::create([
            'id_tipo_audiencia' => $tipo->getKey(), 'codigo' => 'inicial', 'nombre' => 'Etapa sintetica',
            'orden' => 1, 'es_inicial' => true, 'activo' => true,
        ]);
        $propuesta = new PropuestaTurnoAudiencia([
            'id_tipo_audiencia' => $tipo->getKey(), 'id_etapa' => $etapa->getKey(), 'orden' => 1,
            'rol' => 'juez', 'acto_propuesto' => 'Acto sintetico', 'descripcion_propuesta' => 'Solo para pruebas.',
        ]);
        $propuesta->id_usuario_creador = $admin->getKey();
        $propuesta->save();
        $version = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $tipo->getKey(), 'Version exclusivamente sintetica.');

        return [$admin, $revisor, $version];
    }
}
