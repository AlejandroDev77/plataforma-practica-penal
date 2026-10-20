<?php

namespace Tests\Feature;

use App\Models\AutorVersionRubrica;
use App\Models\Rubrica;
use App\Models\TipoAudiencia;
use App\Models\User;
use App\Models\VersionRubricaEvaluacion;
use App\Services\Evaluaciones\GestionarRevisionRubricas;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDO;
use PDOException;
use Spatie\Permission\Models\Role;
use Tests\Support\EscenarioPracticaReglada;
use Tests\TestCase;

class RevisionRubricasTest extends TestCase
{
    use EscenarioPracticaReglada;

    private function datos(): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $autor = User::factory()->create();
        $revisor = User::factory()->create();
        $tipo = TipoAudiencia::create(['codigo' => 'sintetico', 'nombre' => 'Tipo sintético', 'activo' => true]);
        $rubrica = Rubrica::create(['nombre' => 'Rúbrica sintética', 'id_tipo_audiencia' => $tipo->getKey(),
            'rol_aplicable' => 'abogado_defensor', 'version' => 1, 'activo' => false]);
        $criterio = $rubrica->criterios()->create(['nombre' => 'Criterio sintético', 'descripcion' => 'Sin valor jurídico.',
            'peso' => '1.2500', 'puntaje_maximo' => '10.0000', 'orden' => 1]);
        $g = app(GestionarRevisionRubricas::class);
        $v = $g->sellar($admin, $rubrica->getKey(), 'Copia sintética.', [$autor->getKey()], true);

        return compact('admin', 'autor', 'revisor', 'tipo', 'rubrica', 'criterio', 'g', 'v');
    }

    private function habilitacion(): array
    {
        return ['alcance' => 'Copia sintética, sin acreditación profesional real.', 'referencia_acreditacion' => 'Evidencia sintética.',
            'huella_acreditacion' => str_repeat('a', 64), 'acreditacion_verificada' => true,
            'fecha_vencimiento' => now()->addDays(2)->toIso8601String()];
    }

    private function revision($v, array $extra = []): array
    {
        return ['decision' => 'aprobado', 'observacion' => 'Revisión sintética, sin valor jurídico.',
            'huella_version_revisada' => $v->huella_contenido, 'id_revision_previa' => null,
            'confirmacion_humana' => true, 'autoria_revisada' => true,
            'vigencia_comprobada_en' => now('America/La_Paz')->toDateString(),
            'fecha_vencimiento' => now()->addDay()->toIso8601String(),
            'fuentes_verificadas' => [['referencia' => 'Referencia sintética.', 'huella_contenido' => str_repeat('b', 64)]], ...$extra];
    }

    private function rechazar(string $clase, \Closure $operacion): void
    {
        try {
            $operacion();
            $this->fail('El registro debía rechazarse.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($clase, $error);
        }
    }

    private function completa(array $e): array
    {
        $h = $e['g']->habilitar($e['admin'], $e['revisor']->getKey(), $e['v']->getKey(), $this->habilitacion());
        $r = $e['g']->revisar($e['revisor'], $e['v']->getKey(), $h->getKey(), $this->revision($e['v']));

        return [$h, $r];
    }

    public function test_captura_exacta_huella_de_postgresql_y_ediciones_no_cambian_copia(): void
    {
        $e = $this->datos();
        $v = $e['v'];
        $this->assertSame(1, $v->numero_version);
        $this->assertSame('1.25', (string) $v->contenido['criterios'][0]['peso']);
        $this->assertSame($e['autor']->getKey(), $v->ids_autores_declarados[0]);
        $calculada = DB::selectOne('SELECT jurissim_huella_rubrica(contenido,ids_autores_declarados,id_usuario_preparador) huella FROM versiones_rubricas_evaluacion WHERE id_version_rubrica=?', [$v->getKey()])->huella;
        $this->assertSame($calculada, $v->huella_contenido);
        $contenido = $v->contenido;
        $e['criterio']->update(['descripcion' => 'Catálogo modificado.']);
        $e['rubrica']->update(['nombre' => 'Catálogo modificado.', 'version' => 2]);
        $this->assertSame($contenido, $v->fresh()->contenido);
        $otra = $e['g']->sellar($e['admin'], $e['rubrica']->getKey(), 'Nueva copia.', [$e['autor']->getKey()], true);
        $this->assertSame(2, $otra->numero_version);
        $this->assertSame(2, $otra->contenido['rubrica']['version_catalogo']);
        $this->assertNotSame($v->huella_contenido, $otra->huella_contenido);
        $this->assertSame('no_acreditada', $e['g']->estado($otra->getKey())['estado_revision']);
        $this->assertDatabaseCount('evaluaciones', 0);
        Http::assertNothingSent();
    }

    public function test_sql_no_confia_en_contenido_numero_hash_tipo_o_fecha_suministrados(): void
    {
        $e = $this->datos();
        $id = DB::table('versiones_rubricas_evaluacion')->insertGetId(['id_rubrica' => $e['rubrica']->getKey(),
            'id_usuario_preparador' => $e['admin']->getKey(), 'motivo' => 'Copia sintética.',
            'ids_autores_declarados' => json_encode([$e['autor']->getKey()]), 'confirmacion_autoria_declarada' => true,
            'numero_version' => 999, 'contenido' => '{"falso":true}', 'id_tipo_audiencia' => 999999,
            'huella_contenido' => str_repeat('0', 64), 'fecha_creacion' => '2000-01-01'], 'id_version_rubrica');
        $v = VersionRubricaEvaluacion::findOrFail($id);
        $this->assertSame(2, $v->numero_version);
        $this->assertSame($e['tipo']->getKey(), $v->id_tipo_audiencia);
        $this->assertSame($e['v']->contenido, $v->contenido);
        $this->assertSame($e['v']->huella_contenido, $v->huella_contenido);
        $this->assertTrue($v->fecha_creacion->greaterThan(now()->subMinute()));
    }

    public function test_sellar_exige_admin_actual_y_declaracion_nativa_completa(): void
    {
        $e = $this->datos();
        $this->rechazar(DomainException::class, fn () => $e['g']->sellar($e['autor'], $e['rubrica']->getKey(), 'Copia.', [$e['autor']->getKey()], true));
        foreach ([[[], true], [[1, 1], true], [['1'], true], [[1], 'true'], [[1], false], [[999999], true]] as [$autores, $confirmacion]) {
            $this->rechazar(DomainException::class, fn () => $e['g']->sellar($e['admin'], $e['rubrica']->getKey(), 'Copia.', $autores, $confirmacion));
        }
        $e['admin']->hasRole('administrador_plataforma');
        DB::table('model_has_roles')->where('model_id', $e['admin']->getKey())->delete();
        $this->rechazar(DomainException::class, fn () => $e['g']->sellar($e['admin'], $e['rubrica']->getKey(), 'Copia.', [$e['autor']->getKey()], true));
        $this->assertDatabaseCount('versiones_rubricas_evaluacion', 1);
    }

    public function test_rubrica_invalida_no_se_sella_ni_recupera_valores_por_defecto(): void
    {
        $e = $this->datos();
        foreach ([['rol_aplicable' => null], ['rol_aplicable' => 'fiscal'], ['id_tipo_audiencia' => null], ['nombre' => ' ']] as $extra) {
            $original = $e['rubrica']->getAttributes();
            $e['rubrica']->update($extra);
            $this->rechazar(DomainException::class, fn () => $e['g']->sellar($e['admin'], $e['rubrica']->getKey(), 'Copia.', [$e['autor']->getKey()], true));
            $e['rubrica']->forceFill($original)->save();
        }
        $e['criterio']->update(['descripcion' => str_repeat('a', 2001)]);
        $this->rechazar(DomainException::class, fn () => $e['g']->sellar($e['admin'], $e['rubrica']->getKey(), 'Copia.', [$e['autor']->getKey()], true));
        $e['criterio']->update(['descripcion' => "\u{00A0}\u{2003}"]);
        $this->rechazar(DomainException::class, fn () => $e['g']->sellar($e['admin'], $e['rubrica']->getKey(), 'Copia.', [$e['autor']->getKey()], true));
        $e['criterio']->delete();
        $this->rechazar(DomainException::class, fn () => $e['g']->sellar($e['admin'], $e['rubrica']->getKey(), 'Copia.', [$e['autor']->getKey()], true));
    }

    public function test_revision_independiente_es_trazable_pero_no_activa_ni_ejecuta(): void
    {
        $e = $this->datos();
        [$h, $r] = $this->completa($e);
        $d = $e['g']->estado($e['v']->getKey());
        $this->assertSame('aprobado', $d['estado_revision']);
        $this->assertFalse($d['execution_authorized']);
        $this->assertSame([], $d['allowed_actions']);
        $this->assertSame($e['revisor']->getKey(), $r->id_usuario_revisor);
        $this->assertSame($e['v']->getKey(), $h->id_version_rubrica);
        $this->assertSame($e['v']->huella_contenido, $r->huella_version_revisada);
        $this->assertFalse($e['rubrica']->fresh()->activo);
        $this->assertDatabaseCount('activaciones_reglas_audiencia', 0);
        $this->assertDatabaseCount('evaluaciones', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_habilitacion_es_especifica_para_copia_y_no_hereda_reglas(): void
    {
        $e = $this->datos();
        $this->rechazar(DomainException::class, fn () => $e['g']->revisar($e['admin'], $e['v']->getKey(), 1, $this->revision($e['v'])));
        $h = $e['g']->habilitar($e['admin'], $e['revisor']->getKey(), $e['v']->getKey(), $this->habilitacion());
        $this->rechazar(DomainException::class, fn () => $e['g']->habilitar($e['admin'], $e['revisor']->getKey(), $e['v']->getKey(), $this->habilitacion()));
        $otra = $e['g']->sellar($e['admin'], $e['rubrica']->getKey(), 'Otra copia.', [$e['autor']->getKey()], true);
        $this->rechazar(DomainException::class, fn () => $e['g']->revisar($e['revisor'], $otra->getKey(), $h->getKey(), $this->revision($otra)));
        $this->rechazar(DomainException::class, fn () => $e['g']->habilitar($e['autor'], $e['revisor']->getKey(), $otra->getKey(), $this->habilitacion()));
        $this->rechazar(DomainException::class, fn () => $e['g']->habilitar($e['admin'], $e['admin']->getKey(), $otra->getKey(), $this->habilitacion()));
        $this->assertDatabaseCount('habilitaciones_revision_rubricas', 1);
    }

    public function test_preparador_y_autores_declarados_no_pueden_revisar_su_copia(): void
    {
        $e = $this->datos();
        $otroAdmin = User::factory()->create();
        $otroAdmin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        foreach ([$e['admin'], $e['autor']] as $actor) {
            $h = $e['g']->habilitar($otroAdmin, $actor->getKey(), $e['v']->getKey(), $this->habilitacion());
            $this->rechazar(DomainException::class, fn () => $e['g']->revisar($actor, $e['v']->getKey(), $h->getKey(), $this->revision($e['v'])));
        }
        $this->assertDatabaseCount('revisiones_rubricas_evaluacion', 0);
    }

    public function test_confirmaciones_evidencia_y_campos_son_estrictos(): void
    {
        $e = $this->datos();
        $this->rechazar(ValidationException::class, fn () => $e['g']->habilitar($e['admin'], $e['revisor']->getKey(), $e['v']->getKey(), [...$this->habilitacion(), 'acreditacion_verificada' => 'true']));
        $h = $e['g']->habilitar($e['admin'], $e['revisor']->getKey(), $e['v']->getKey(), $this->habilitacion());
        foreach ([['confirmacion_humana' => 'true'], ['autoria_revisada' => false], ['fuentes_verificadas' => []],
            ['id_usuario_revisor' => 1], ['huella_version_revisada' => 'invalid'],
            ['fuentes_verificadas' => [['referencia' => 'X', 'huella_contenido' => str_repeat('b', 64), 'extra' => true]]]] as $extra) {
            $this->rechazar(ValidationException::class, fn () => $e['g']->revisar($e['revisor'], $e['v']->getKey(), $h->getKey(), $this->revision($e['v'], $extra)));
        }
        foreach ([['huella_version_revisada' => str_repeat('f', 64)], ['fecha_vencimiento' => now()->addDays(3)->toIso8601String()],
            ['vigencia_comprobada_en' => now('America/La_Paz')->addDay()->toDateString()]] as $extra) {
            $this->rechazar(DomainException::class, fn () => $e['g']->revisar($e['revisor'], $e['v']->getKey(), $h->getKey(), $this->revision($e['v'], $extra)));
        }
        $this->assertDatabaseCount('revisiones_rubricas_evaluacion', 0);
    }

    public function test_revision_previa_impide_doble_envio_y_no_resucita_aprobacion_anterior(): void
    {
        $e = $this->datos();
        [$h, $r] = $this->completa($e);
        $this->rechazar(DomainException::class, fn () => $e['g']->revisar($e['revisor'], $e['v']->getKey(), $h->getKey(), $this->revision($e['v'])));
        $nueva = $e['g']->revisar($e['revisor'], $e['v']->getKey(), $h->getKey(), $this->revision($e['v'],
            ['id_revision_previa' => $r->getKey(), 'decision' => 'requiere_cambios', 'fecha_vencimiento' => null]));
        $this->assertSame('requiere_cambios', $e['g']->estado($e['v']->getKey())['estado_revision']);
        $e['g']->retirarRevision($e['revisor'], $nueva->getKey(), 'Retiro sintético.');
        $this->assertSame('revision_retirada', $e['g']->estado($e['v']->getKey())['estado_revision']);
        $this->assertDatabaseCount('revisiones_rubricas_evaluacion', 2);
    }

    public function test_retiros_requieren_responsable_y_bloquean_nuevas_decisiones(): void
    {
        $e = $this->datos();
        [$h, $r] = $this->completa($e);
        $this->rechazar(DomainException::class, fn () => $e['g']->retirarHabilitacion($e['autor'], $h->getKey(), 'Retiro.'));
        $this->rechazar(DomainException::class, fn () => $e['g']->retirarRevision($e['autor'], $r->getKey(), 'Retiro.'));
        $e['g']->retirarHabilitacion($e['admin'], $h->getKey(), 'Retiro sintético.');
        $this->assertSame('habilitacion_retirada', $e['g']->estado($e['v']->getKey())['estado_revision']);
        $this->rechazar(DomainException::class, fn () => $e['g']->revisar($e['revisor'], $e['v']->getKey(), $h->getKey(), $this->revision($e['v'], ['id_revision_previa' => $r->getKey()])));
        $this->rechazar(DomainException::class, fn () => $e['g']->retirarHabilitacion($e['admin'], $h->getKey(), 'Retiro duplicado.'));
    }

    public function test_modelos_sql_y_truncate_preservan_todos_los_registros(): void
    {
        $e = $this->datos();
        [$h, $r] = $this->completa($e);
        $retiroR = $e['g']->retirarRevision($e['revisor'], $r->getKey(), 'Retiro sintético.');
        $retiroH = $e['g']->retirarHabilitacion($e['admin'], $h->getKey(), 'Retiro sintético.');
        foreach ([$e['v'], $h, $r, $retiroR, $retiroH, AutorVersionRubrica::query()->sole()] as $registro) {
            $registro->fecha_creacion = now()->subDay();
            $this->rechazar(LogicException::class, fn () => $registro->save());
            $this->rechazar(LogicException::class, fn () => $registro->delete());
            foreach (['update', 'delete', 'truncate'] as $accion) {
                try {
                    DB::transaction(function () use ($registro, $accion): void {
                        if ($accion === 'truncate') {
                            DB::statement('TRUNCATE '.$registro->getTable().' CASCADE');
                        } elseif ($accion === 'delete') {
                            DB::table($registro->getTable())->delete();
                        } else {
                            DB::table($registro->getTable())->update(['fecha_creacion' => now()->subDay()]);
                        }
                    });
                    $this->fail('No debe cambiar registros inmutables.');
                } catch (QueryException $error) {
                    $this->assertSame('23514', $error->errorInfo[0]);
                }
            }
            $this->assertDatabaseCount($registro->getTable(), 1);
        }
        try {
            DB::transaction(fn () => DB::table('users')->where('id', $e['autor']->getKey())->delete());
            $this->fail('Debe conservar la referencia al autor declarado.');
        } catch (QueryException $error) {
            $this->assertSame('23503', $error->errorInfo[0]);
        }
    }

    public function test_bloqueo_parental_serializa_sellos_y_no_reserva_numero_por_max_sin_lock(): void
    {
        $e = $this->datos();
        $c = config('database.connections.pgsql');
        $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname=jurissim_pruebas", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('jurissim_pruebas', $pdo->query('SELECT current_database()')->fetchColumn());
        $pdo->exec("SET lock_timeout='75ms'");
        DB::transaction(function () use ($e, $pdo): void {
            DB::table('rubricas')->where('id_rubrica', $e['rubrica']->getKey())->lockForUpdate()->first();
            $pdo->beginTransaction();
            try {
                $q = $pdo->prepare('INSERT INTO versiones_rubricas_evaluacion (id_rubrica,id_usuario_preparador,motivo,ids_autores_declarados,confirmacion_autoria_declarada) VALUES (?,?,?,?::jsonb,true)');
                $q->execute([$e['rubrica']->getKey(), $e['admin']->getKey(), 'Copia simultánea.', json_encode([$e['autor']->getKey()])]);
                $this->fail('El otro sello debe esperar el bloqueo de la rúbrica.');
            } catch (PDOException $error) {
                $this->assertSame('55P03', $error->errorInfo[0]);
            } finally {
                $pdo->rollBack();
            }
        });
        $v = $e['g']->sellar($e['admin'], $e['rubrica']->getKey(), 'Copia posterior.', [$e['autor']->getKey()], true);
        $this->assertSame(2, $v->numero_version);
        $this->assertDatabaseCount('versiones_rubricas_evaluacion', 2);
    }

    public function test_rollback_no_destruye_historial_no_vacio(): void
    {
        $e = $this->datos();
        $migration = require database_path('migrations/2026_10_06_000001_versionar_revision_rubricas.php');
        $this->rechazar(\RuntimeException::class, fn () => $migration->down());
        $this->assertDatabaseCount('versiones_rubricas_evaluacion', 1);
        $this->assertSame('no_acreditada', $e['g']->estado($e['v']->getKey())['estado_revision']);
    }

    public function test_rollback_vacio_y_reaplicacion_preservan_esquema_anterior(): void
    {
        $contextos = require database_path('migrations/2026_10_20_000001_persistir_contextos_evaluacion_reglada.php');
        $activaciones = require database_path('migrations/2026_10_06_000002_activar_copias_rubricas.php');
        $contextos->down();
        $activaciones->down();
        $migration = require database_path('migrations/2026_10_06_000001_versionar_revision_rubricas.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('versiones_rubricas_evaluacion'));
        $this->assertTrue(Schema::hasTable('rubricas'));
        $migration->up();
        $activaciones->up();
        $contextos->up();
        $e = $this->datos();
        $this->assertSame('no_acreditada', $e['g']->estado($e['v']->getKey())['estado_revision']);
    }

    public function test_reloj_de_postgresql_revoca_efecto_al_vencer_sin_editar_registros(): void
    {
        $e = $this->datos();
        $fecha = fn (int $segundos): string => CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp()+make_interval(secs=>?) fecha', [$segundos])->fecha)->toIso8601String();
        $h = $e['g']->habilitar($e['admin'], $e['revisor']->getKey(), $e['v']->getKey(),
            [...$this->habilitacion(), 'fecha_vencimiento' => $fecha(8)]);
        $e['g']->revisar($e['revisor'], $e['v']->getKey(), $h->getKey(), $this->revision($e['v'], ['fecha_vencimiento' => $fecha(3)]));
        $this->assertSame('aprobado', $e['g']->estado($e['v']->getKey())['estado_revision']);
        DB::selectOne('SELECT pg_sleep(4)');
        $this->assertSame('revision_vencida', $e['g']->estado($e['v']->getKey())['estado_revision']);
        DB::selectOne('SELECT pg_sleep(5)');
        $this->assertSame('habilitacion_vencida', $e['g']->estado($e['v']->getKey())['estado_revision']);
        $this->rechazar(DomainException::class, fn () => $e['g']->revisar($e['revisor'], $e['v']->getKey(), $h->getKey(), $this->revision($e['v'])));
        $this->assertDatabaseCount('revisiones_rubricas_evaluacion', 1);
    }

    public function test_autoria_normalizada_no_admite_otras_personas_y_protege_la_fk(): void
    {
        $e = $this->datos();
        $this->assertDatabaseCount('autores_version_rubrica', 1);
        $this->rechazar(QueryException::class, fn () => DB::transaction(fn () => DB::table('autores_version_rubrica')
            ->insert(['id_version_rubrica' => $e['v']->getKey(), 'id_usuario_autor' => $e['revisor']->getKey()])));
        $this->rechazar(QueryException::class, fn () => DB::transaction(fn () => DB::table('users')->where('id', $e['autor']->getKey())->update(['id' => 999999])));
        $this->assertDatabaseCount('autores_version_rubrica', 1);
        Http::assertNothingSent();
    }

    public function test_funcion_de_contrato_rechaza_estructuras_incompletas_y_campos_extra(): void
    {
        $e = $this->datos();
        foreach ([null, [], ['formato' => 1], [...$e['v']->contenido, 'extra' => true],
            [...$e['v']->contenido, 'criterios' => [['id_criterio' => 1]]]] as $invalido) {
            $this->assertFalse((bool) DB::selectOne('SELECT jurissim_validar_copia_rubrica(?::jsonb) valido', [json_encode($invalido)])->valido);
        }
        $this->assertTrue((bool) DB::selectOne('SELECT jurissim_validar_copia_rubrica(contenido) valido FROM versiones_rubricas_evaluacion WHERE id_version_rubrica=?', [$e['v']->getKey()])->valido);
    }

    public function test_borrado_concurrente_de_autor_no_deja_una_copia_sin_referencia(): void
    {
        $e = $this->datos();
        $nuevoAutor = User::factory()->create();
        $c = config('database.connections.pgsql');
        $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname=jurissim_pruebas", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('jurissim_pruebas', $pdo->query('SELECT current_database()')->fetchColumn());
        $pdo->exec("SET lock_timeout='75ms'");
        DB::transaction(function () use ($e, $pdo, $nuevoAutor): void {
            DB::table('users')->where('id', $nuevoAutor->getKey())->delete();
            $pdo->beginTransaction();
            try {
                $q = $pdo->prepare('INSERT INTO versiones_rubricas_evaluacion (id_rubrica,id_usuario_preparador,motivo,ids_autores_declarados,confirmacion_autoria_declarada) VALUES (?,?,?,?::jsonb,true)');
                $q->execute([$e['rubrica']->getKey(), $e['admin']->getKey(), 'Copia simultánea.', json_encode([$nuevoAutor->getKey()])]);
                $this->fail('La FK debe impedir confirmar una copia mientras se borra el autor.');
            } catch (PDOException $error) {
                $this->assertSame('55P03', $error->errorInfo[0]);
            } finally {
                $pdo->rollBack();
            }
        });
        $this->assertDatabaseCount('versiones_rubricas_evaluacion', 1);
        $this->assertDatabaseCount('autores_version_rubrica', 1);
    }
}
