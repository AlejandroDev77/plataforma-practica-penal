<?php

namespace Tests\Feature;

use App\Models\EtapaAudiencia;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\TipoAudiencia;
use App\Models\TransicionAudiencia;
use App\Models\TurnoEtapaAudiencia;
use App\Models\User;
use App\Models\VersionReglasAudiencia;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VersionReglasAudienciaTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if ($this->app->configurationIsCached()
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'jurissim_pruebas'
            || config('database.connections.pgsql.url')
            || DB::selectOne('select current_database() as nombre')->nombre !== 'jurissim_pruebas') {
            throw new RuntimeException('Estas pruebas requieren PostgreSQL jurissim_pruebas, sin DB_URL ni cache de configuracion.');
        }
        Http::preventStrayRequests();
    }

    public function test_sella_una_version_completa_sin_aprobar_ni_activar_reglas(): void
    {
        [$autor, $tipo, $etapa, $propuesta] = $this->borrador();
        $antes = $propuesta->fresh()->getAttributes();
        $version = $this->sellar($autor, $tipo);
        $this->assertSame(1, $version->numero_version);
        $this->assertSame($autor->getKey(), $version->id_usuario_autor);
        $this->assertSame($tipo->getKey(), $version->id_tipo_audiencia);
        $this->assertSame(1, $version->contenido['formato']);
        $this->assertSame($etapa->getKey(), $version->contenido['etapas'][0]['id_etapa']);
        $this->assertSame($propuesta->getKey(), $version->contenido['propuestas'][0]['id_propuesta_turno']);
        $this->assertSame('borrador', $version->contenido['propuestas'][0]['estado']);
        $this->assertSame([], $version->contenido['turnos_catalogo_referencia']);
        $this->assertSame($antes, $propuesta->fresh()->getAttributes());
        $this->assertSame(0, TurnoEtapaAudiencia::query()->count());
        $this->assertDatabaseCount('simulaciones', 0);
        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertArrayNotHasKey('aprobado', $version->getAttributes());
        $this->assertFalse(Schema::hasColumn('versiones_reglas_audiencia', 'estado_aprobacion'));
        Http::assertNothingSent();
    }

    public function test_el_admin_no_es_revisor_juridico_por_sellar_un_borrador(): void
    {
        [$autor, $tipo] = $this->borrador();
        $this->sellar($autor, $tipo);
        $this->assertFalse($autor->fresh()->hasRole('revisor_juridico'));
        $this->assertDatabaseMissing('roles', ['name' => 'revisor_juridico']);
    }

    public function test_una_cuenta_sin_permiso_no_puede_sellar(): void
    {
        [, $tipo] = $this->borrador();
        $this->expectException(AuthorizationException::class);
        $this->sellar(User::factory()->create(), $tipo);
    }

    public function test_los_roles_precargados_no_sobreviven_a_la_revocacion(): void
    {
        [$autor, $tipo] = $this->borrador();
        $autor->load('roles');
        DB::table('model_has_roles')->where('model_id', $autor->getKey())->delete();
        $this->expectException(AuthorizationException::class);
        $this->sellar($autor, $tipo);
    }

    public function test_no_sella_un_catalogo_sin_propuestas_y_no_deja_version_parcial(): void
    {
        [$autor, $tipo, , $propuesta] = $this->borrador();
        $propuesta->delete();
        try {
            $this->sellar($autor, $tipo);
            $this->fail('No debe sellarse un catalogo sin propuestas.');
        } catch (DomainException) {
            $this->assertDatabaseCount('versiones_reglas_audiencia', 0);
        }
    }

    public function test_rechaza_motivos_vacios_o_demasiado_largos(): void
    {
        [$autor, $tipo] = $this->borrador();
        foreach ([" \t\n", str_repeat('a', 2001)] as $motivo) {
            try {
                $this->sellar($autor, $tipo, $motivo);
                $this->fail('Motivo invalido aceptado.');
            } catch (DomainException) {
                $this->assertDatabaseCount('versiones_reglas_audiencia', 0);
            }
        }
        $version = $this->sellar($autor, $tipo, '  Motivo valido  ');
        $this->assertSame('Motivo valido', $version->motivo);
    }

    public function test_un_tipo_inexistente_no_produce_una_version(): void
    {
        [$autor] = $this->borrador();
        $this->expectException(ModelNotFoundException::class);
        app(SellarVersionReglasAudiencia::class)->ejecutar($autor, 999999, 'Prueba sintetica');
    }

    public function test_un_borrador_excesivo_no_consume_un_numero_de_version(): void
    {
        [$autor, $tipo, , $propuesta] = $this->borrador();
        $propuesta->update(['descripcion_propuesta' => str_repeat('a', 2_000_001)]);
        try {
            $this->sellar($autor, $tipo);
            $this->fail('No debe sellarse un borrador de mas de 2 MB.');
        } catch (DomainException $error) {
            $this->assertStringContainsString('2 MB', $error->getMessage());
            $this->assertDatabaseCount('versiones_reglas_audiencia', 0);
        }
        $propuesta->update(['descripcion_propuesta' => 'Descripcion sintetica acotada.']);
        $this->assertSame(1, $this->sellar($autor, $tipo)->numero_version);
    }

    public function test_las_restricciones_de_numero_y_motivo_tambien_se_aplican_en_sql(): void
    {
        [$autor, $tipo] = $this->borrador();
        $fila = $this->fila($this->sellar($autor, $tipo));
        foreach ([
            ['numero_version' => 0],
            ['numero_version' => 2, 'motivo' => " \t\n"],
            ['numero_version' => 2, 'motivo' => str_repeat('a', 2001)],
        ] as $cambios) {
            $datos = [...$fila, ...$cambios];
            $this->rechazoSql(fn () => DB::table('versiones_reglas_audiencia')->insert($datos));
        }
        $this->assertDatabaseCount('versiones_reglas_audiencia', 1);
    }

    public function test_las_ediciones_y_eliminaciones_de_borrador_no_cambian_la_version_sellada(): void
    {
        [$autor, $tipo, $etapa, $propuesta] = $this->borrador();
        $version = $this->sellar($autor, $tipo);
        $antes = $version->getAttributes();
        $propuesta->update(['descripcion_propuesta' => 'Nueva descripcion sintetica.']);
        $etapa->update(['nombre' => 'Nuevo nombre']);
        $tipo->update(['activo' => false]);
        $nueva = $this->sellar($autor, $tipo, 'Revision tecnica posterior.');
        $this->assertSame(2, $nueva->numero_version);
        $this->assertNotSame($version->huella_contenido, $nueva->huella_contenido);
        $propuesta->delete();
        $etapa->delete();
        $this->assertSame($antes, $version->fresh()->getAttributes());
        $this->assertSame('Nuevo nombre', $nueva->contenido['etapas'][0]['nombre']);
    }

    public function test_la_numeracion_es_por_tipo_y_la_duplicidad_no_se_admite(): void
    {
        [$autor, $tipo] = $this->borrador('uno');
        [, $otroTipo] = $this->borrador('dos');
        $v1 = $this->sellar($autor, $tipo);
        $this->assertSame(2, $this->sellar($autor, $tipo)->numero_version);
        $this->assertSame(1, $this->sellar($autor, $otroTipo)->numero_version);
        $this->rechazoSql(fn () => DB::table('versiones_reglas_audiencia')->insert($this->fila($v1)));
        $this->assertDatabaseCount('versiones_reglas_audiencia', 3);
    }

    public function test_la_huella_es_calculada_por_postgresql_y_no_por_el_cliente(): void
    {
        [$autor, $tipo] = $this->borrador();
        $version = $this->sellar($autor, $tipo);
        $fila = $this->fila($version);
        $fila['numero_version'] = 2;
        $fila['huella_contenido'] = str_repeat('0', 64);
        $id = DB::table('versiones_reglas_audiencia')->insertGetId($fila, 'id_version_reglas');
        $this->assertSame($version->huella_contenido, VersionReglasAudiencia::findOrFail($id)->huella_contenido);
        $texto = DB::selectOne('SELECT contenido::text AS texto FROM versiones_reglas_audiencia WHERE id_version_reglas = ?', [$id])->texto;
        $this->assertSame(hash('sha256', $texto), $version->huella_contenido);
    }

    public function test_la_estructura_o_el_tipo_de_un_snapshot_invalido_se_rechazan(): void
    {
        [$autor, $tipo] = $this->borrador();
        $version = $this->sellar($autor, $tipo);
        $fila = $this->fila($version);
        $fila['numero_version'] = 2;
        $invalidos = [[], ['formato' => 1], [...$version->contenido, 'formato' => 2], [...$version->contenido, 'etapas' => null]];
        $otroTipo = $version->contenido;
        $otroTipo['tipo']['id_tipo_audiencia'] = $tipo->getKey() + 1;
        $invalidos[] = $otroTipo;
        foreach ($invalidos as $contenido) {
            $datos = [...$fila, 'contenido' => json_encode($contenido, JSON_THROW_ON_ERROR)];
            $this->rechazoSql(fn () => DB::table('versiones_reglas_audiencia')->insert($datos));
        }
        $this->assertDatabaseCount('versiones_reglas_audiencia', 1);
    }

    public function test_no_se_mezclan_propuestas_de_otros_tipos(): void
    {
        [$autor, $tipo] = $this->borrador('uno');
        $this->borrador('dos');
        $version = $this->sellar($autor, $tipo);
        $this->assertCount(1, $version->contenido['propuestas']);
        $this->assertCount(1, $version->contenido['etapas']);
        $this->assertSame($tipo->getKey(), $version->contenido['propuestas'][0]['id_tipo_audiencia']);
    }

    public function test_conserva_transiciones_y_turnos_solo_como_referencia(): void
    {
        [$autor, $tipo, $etapa] = $this->borrador();
        $destino = EtapaAudiencia::create([
            'id_tipo_audiencia' => $tipo->getKey(), 'codigo' => 'final', 'nombre' => 'Final sintetico',
            'orden' => 2, 'es_final' => true, 'activo' => false,
        ]);
        $transicion = TransicionAudiencia::create([
            'id_tipo_audiencia' => $tipo->getKey(), 'id_etapa_origen' => $etapa->getKey(),
            'id_etapa_destino' => $destino->getKey(), 'activo' => false,
        ]);
        $turno = TurnoEtapaAudiencia::create([
            'id_tipo_audiencia' => $tipo->getKey(), 'id_etapa' => $etapa->getKey(),
            'orden' => 1, 'rol' => 'juez', 'instruccion' => 'Referencia sintetica.', 'activo' => false,
        ]);
        $version = $this->sellar($autor, $tipo);
        $this->assertSame($transicion->getKey(), $version->contenido['transiciones'][0]['id_transicion']);
        $this->assertFalse($version->contenido['transiciones'][0]['activo']);
        $this->assertSame($turno->getKey(), $version->contenido['turnos_catalogo_referencia'][0]['id_turno_etapa']);
        $this->assertFalse($turno->fresh()->activo);
        $this->assertSame(2, count($version->contenido['etapas']));
    }

    public function test_eloquent_no_permite_editar_una_version(): void
    {
        [$autor, $tipo] = $this->borrador();
        $version = $this->sellar($autor, $tipo);
        $version->motivo = 'Alteracion';
        $this->expectException(LogicException::class);
        $version->save();
    }

    public function test_eloquent_no_permite_eliminar_una_version(): void
    {
        [$autor, $tipo] = $this->borrador();
        $this->expectException(LogicException::class);
        $this->sellar($autor, $tipo)->delete();
    }

    public function test_el_query_builder_tampoco_puede_editar_o_eliminar_versiones(): void
    {
        [$autor, $tipo] = $this->borrador();
        $version = $this->sellar($autor, $tipo);
        $this->rechazoSql(fn () => DB::table('versiones_reglas_audiencia')->where('id_version_reglas', $version->getKey())->update(['motivo' => 'Alteracion']));
        $this->rechazoSql(fn () => DB::table('versiones_reglas_audiencia')->delete());
        $this->rechazoSql(fn () => DB::statement('TRUNCATE TABLE versiones_reglas_audiencia CASCADE'));
        $this->assertDatabaseCount('versiones_reglas_audiencia', 1);
    }

    public function test_el_autor_y_el_tipo_no_pueden_eliminarse_por_cascada(): void
    {
        [$autor, $tipo] = $this->borrador();
        $this->sellar($autor, $tipo);
        $this->rechazoSql(fn () => DB::table('users')->where('id', $autor->getKey())->delete());
        $this->rechazoSql(fn () => DB::table('tipos_audiencia')->where('id_tipo_audiencia', $tipo->getKey())->delete());
        $this->assertDatabaseCount('versiones_reglas_audiencia', 1);
    }

    public function test_la_asignacion_masiva_no_recibe_autoria_ni_contenido_del_cliente(): void
    {
        $this->expectException(MassAssignmentException::class);
        new VersionReglasAudiencia(['id_usuario_autor' => 1, 'contenido' => ['aprobado' => true]]);
    }

    public function test_el_rollback_no_borra_versiones_existentes(): void
    {
        [$autor, $tipo] = $this->borrador();
        $this->sellar($autor, $tipo);
        $migracion = require database_path('migrations/2026_10_01_000001_crear_versiones_reglas_audiencia.php');
        try {
            $migracion->down();
            $this->fail('No debe destruir versiones selladas.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('deben preservarse', $error->getMessage());
            $this->assertDatabaseCount('versiones_reglas_audiencia', 1);
        }
    }

    public function test_la_migracion_vacia_puede_revertirse_y_reinstalarse(): void
    {
        $activacionesRubricas = require database_path('migrations/2026_10_06_000002_activar_copias_rubricas.php');
        $rubricas = require database_path('migrations/2026_10_06_000001_versionar_revision_rubricas.php');
        $activacionesRubricas->down();
        $rubricas->down();
        $movimientos = require database_path('migrations/2026_10_04_000003_registrar_movimientos_practicas.php');
        $defensa = require database_path('migrations/2026_10_04_000004_registrar_defensa_reglada.php');
        $agentes = require database_path('migrations/2026_10_04_000005_confirmar_agentes_reglados.php');
        $agentes->down();
        $defensa->down();
        $movimientos->down();
        $vinculacion = require database_path('migrations/2026_10_04_000002_vincular_practicas_reglas.php');
        $vinculacion->down();
        $activacion = require database_path('migrations/2026_10_04_000001_registrar_activaciones_reglas.php');
        $activacion->down();
        $revision = require database_path('migrations/2026_10_01_000002_registrar_revision_juridica_reglas.php');
        $revision->down();
        $migracion = require database_path('migrations/2026_10_01_000001_crear_versiones_reglas_audiencia.php');
        $migracion->down();
        $this->assertFalse(Schema::hasTable('versiones_reglas_audiencia'));
        $migracion->up();
        $revision->up();
        $activacion->up();
        $vinculacion->up();
        $movimientos->up();
        $defensa->up();
        $agentes->up();
        $rubricas->up();
        $activacionesRubricas->up();
        $this->assertTrue(Schema::hasTable('versiones_reglas_audiencia'));
    }

    private function rechazoSql(callable $accion): void
    {
        try {
            DB::transaction($accion);
            $this->fail('La base acepto una operacion que debe rechazar.');
        } catch (QueryException $error) {
            $this->assertContains($error->errorInfo[0], ['23514', '23503', '23505']);
        }
    }

    private function sellar(User $autor, TipoAudiencia $tipo, string $motivo = 'Version exclusivamente sintetica.'): VersionReglasAudiencia
    {
        return app(SellarVersionReglasAudiencia::class)->ejecutar($autor, $tipo->getKey(), $motivo);
    }

    private function fila(VersionReglasAudiencia $version): array
    {
        $fila = $version->getAttributes();
        unset($fila['id_version_reglas']);

        return $fila;
    }

    private function borrador(string $sufijo = 'principal'): array
    {
        $autor = User::factory()->create();
        $autor->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $tipo = TipoAudiencia::create(['nombre' => 'Tipo sintetico '.$sufijo, 'codigo' => 'prueba-'.$sufijo, 'activo' => true]);
        $etapa = EtapaAudiencia::create([
            'id_tipo_audiencia' => $tipo->getKey(), 'codigo' => 'inicial', 'nombre' => 'Etapa sintetica',
            'orden' => 1, 'es_inicial' => true, 'activo' => true,
        ]);
        $propuesta = new PropuestaTurnoAudiencia([
            'id_tipo_audiencia' => $tipo->getKey(), 'id_etapa' => $etapa->getKey(), 'orden' => 1,
            'rol' => 'juez', 'acto_propuesto' => 'Acto sintetico', 'descripcion_propuesta' => 'Solo para pruebas.',
        ]);
        $propuesta->id_usuario_creador = $autor->getKey();
        $propuesta->save();

        return [$autor, $tipo, $etapa, $propuesta];
    }
}
