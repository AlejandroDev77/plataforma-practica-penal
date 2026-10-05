<?php

namespace Tests\Feature;

use App\Models\EtapaAudiencia;
use App\Models\Expediente;
use App\Models\FuenteJuridica;
use App\Models\Intervencion;
use App\Models\MovimientoPracticaReglada;
use App\Models\PropuestaTurnoAudiencia;
use App\Models\RegistroIntervencionReglada;
use App\Models\Simulacion;
use App\Models\TipoAudiencia;
use App\Models\TransicionAudiencia;
use App\Models\User;
use App\Services\Simulaciones\CrearPracticaConReglas;
use App\Services\Simulaciones\GestionarActivacionReglas;
use App\Services\Simulaciones\GestionarRevisionJuridicaReglas;
use App\Services\Simulaciones\MoverPracticaReglada;
use App\Services\Simulaciones\RegistrarDefensaReglada;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DefensaRegladaTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'jurissim_pruebas'
            || config('database.connections.pgsql.url')
            || DB::selectOne('SELECT current_database() nombre')->nombre !== 'jurissim_pruebas') {
            throw new RuntimeException('Estas pruebas requieren PostgreSQL jurissim_pruebas, sin DB_URL ni cache.');
        }
        Http::preventStrayRequests();
    }

    public function test_defensa_deja_registro_fecha_fuentes_y_autoria_sin_ia(): void
    {
        [$e, $s] = $this->lista();
        [$f, $j] = $this->fuentes($e);
        $i = $this->registrar($e, $s, [$f->getKey(), $j->getKey()]);
        $r = RegistroIntervencionReglada::query()->sole();
        $this->assertSame($e['owner']->getKey(), $r->id_usuario_responsable);
        $this->assertSame($i->getKey(), $r->id_intervencion);
        $this->assertSame(2, $i->orden);
        $this->assertSame('texto', $i->tipo_entrada);
        $this->assertNull($i->modelo_ia);
        $this->assertSame("Defensa sintética.\nSin valor jurídico.", $i->contenido);
        $this->assertTrue($r->fecha_creacion->equalTo($i->fecha_intervencion));
        $this->assertSame($r->getKey(), $i->metadatos['id_registro_reglado']);
        $this->assertDatabaseCount('fuentes_intervencion', 2);
        $this->assertSame(['expediente', 'juridica'], array_column(array_column($r->fuentes, 'metadatos'), 'tipo_fuente'));
        $this->assertSame($f->contenido, $r->fuentes[0]['fragmento_utilizado']);
        $this->assertSame(hash('sha256', $j->fuenteJuridica->contenido_original), $r->fuentes[1]['metadatos']['hash_contenido_fuente']);
        $final = app(MoverPracticaReglada::class)->ejecutar($e['owner'], $s->getKey(), 'finalizar', 'activa', $s->id_etapa_actual);
        $this->assertSame('finalizada', $final->estado);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_sin_fuentes_es_una_intervencion_sin_citas_no_una_afirmacion_verificada(): void
    {
        [$e, $s] = $this->lista();
        $i = $this->registrar($e, $s);
        $this->assertSame([], RegistroIntervencionReglada::query()->sole()->fuentes);
        $this->assertDatabaseCount('fuentes_intervencion', 0);
        $this->assertSame(2, $i->orden);
    }

    public function test_contexto_repetido_no_duplica_ni_consume_el_turno_siguiente(): void
    {
        [$e, $s] = $this->lista();
        $this->registrar($e, $s);
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s));
        $this->assertDatabaseCount('registros_intervenciones_regladas', 1);
        $this->assertDatabaseCount('intervenciones', 2);
    }

    public function test_precondiciones_exactas_movimiento_etapa_propuesta_y_orden(): void
    {
        [$e, $s] = $this->lista();
        foreach ([['id_movimiento' => 99999], ['id_etapa' => $e['stages'][0]->getKey()],
            ['id_propuesta_turno' => 99999], ['orden' => 1], ['orden' => 3]] as $cambio) {
            $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s, [], $cambio));
        }
        $this->assertDatabaseCount('registros_intervenciones_regladas', 0);
    }

    public function test_propietario_ajeno_y_legacy_no_disponen_del_comando(): void
    {
        [$e, $s] = $this->lista();
        $this->rechazo(ModelNotFoundException::class, fn () => $this->registrar([...$e, 'owner' => $e['admin']], $s));
        $legacy = $e['owner']->simulaciones()->create(['id_expediente' => $e['case']->getKey(),
            'id_analisis' => $e['analysis']->getKey(), 'id_tipo_audiencia' => $e['type']->getKey(), 'estado' => 'preparando']);
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $legacy));
        $this->assertDatabaseCount('registros_intervenciones_regladas', 0);
    }

    public function test_no_permite_turno_del_juez_ni_practica_sin_iniciar(): void
    {
        $e = $this->escenario();
        $s = $this->crear($e);
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s));
        $s = app(MoverPracticaReglada::class)->ejecutar($e['owner'], $s->getKey(), 'iniciar', 'preparando', null);
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s));
        $this->assertDatabaseCount('intervenciones', 0);
    }

    public function test_retiro_o_revision_del_analisis_no_se_eluden_por_modelo_precargado(): void
    {
        [$e, $s] = $this->lista();
        $this->aprobarAnalisis($e, 'requiere_cambios');
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s));
        $this->aprobarAnalisis($e);
        app(GestionarActivacionReglas::class)->retirar($e['admin'], $e['activation']->getKey(), 'Retiro sintetico.');
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s));
        $this->assertDatabaseCount('registros_intervenciones_regladas', 0);
    }

    public function test_participante_inactivo_ambiguo_o_controlado_por_ia_bloquea(): void
    {
        [$e, $s] = $this->lista();
        $p = $s->participantes()->where('rol', 'abogado_defensor')->sole();
        $p->update(['estado' => 'inactivo']);
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s));
        $p->update(['estado' => 'activo', 'controlado_por' => 'ia']);
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s));
        $p->update(['controlado_por' => 'usuario']);
        $p->replicate()->save();
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s));
    }

    public function test_fuente_de_otro_expediente_aun_del_mismo_propietario_no_es_admisible(): void
    {
        [$e, $s] = $this->lista();
        $case = Expediente::factory()->for($e['owner'], 'usuario')->create();
        [$f] = $this->fuentes([...$e, 'case' => $case]);
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s, [$f->getKey()]));
        $this->assertDatabaseCount('registros_intervenciones_regladas', 0);
        $this->assertDatabaseCount('fuentes_intervencion', 0);
    }

    public function test_pagina_ilegible_o_cita_alterada_rechaza_todo_el_paquete(): void
    {
        [$e, $s] = $this->lista();
        [$f, $j] = $this->fuentes($e);
        $f->pagina->update(['es_legible' => false]);
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s, [$j->getKey(), $f->getKey()]));
        $f->pagina->update(['es_legible' => true]);
        $f->update(['contenido' => 'Texto inventado, ajeno a la pagina.']);
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s, [$f->getKey()]));
        $this->assertDatabaseCount('registros_intervenciones_regladas', 0);
        $this->assertDatabaseCount('fuentes_intervencion', 0);
    }

    public function test_norma_sin_validacion_fuera_de_vigencia_o_reindexacion_obsoleta_no_se_cita(): void
    {
        [$e, $s] = $this->lista();
        [, $j] = $this->fuentes($e);
        $fuente = $j->fuenteJuridica;
        foreach ([['validada' => false], ['estado' => 'borrador'],
            ['fecha_vigencia' => now('America/La_Paz')->addDay()->toDateString()],
            ['fecha_fin_vigencia' => now('America/La_Paz')->subDay()->toDateString()],
            ['contenido_original' => $fuente->contenido_original.' Cambio.']] as $cambio) {
            $anterior = $fuente->getAttributes();
            $fuente->update($cambio);
            $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s, [$j->getKey()]));
            $fuente->setRawAttributes($anterior);
            DB::table('fuentes_juridicas')->where('id_fuente', $fuente->getKey())->update($anterior);
        }
        $this->assertDatabaseCount('registros_intervenciones_regladas', 0);
    }

    public function test_entradas_vacias_largas_ids_repetidos_inexistentes_o_no_enteros(): void
    {
        [$e, $s] = $this->lista();
        foreach ([['contenido' => " \n\t"], ['contenido' => str_repeat('á', 2001)],
            ['ids_fragmento' => [99999]], ['ids_fragmento' => [1, 1]], ['ids_fragmento' => ['1']],
            ['ids_fragmento' => range(1, 11)], ['ids_fragmento' => ['id' => 1]]] as $cambio) {
            $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s, [], $cambio));
        }
        $this->assertDatabaseCount('registros_intervenciones_regladas', 0);
    }

    public function test_sql_no_permite_falsificar_intervencion_o_responsable_y_no_filtra_texto(): void
    {
        [$e, $s] = $this->lista();
        $this->rechazo(QueryException::class, fn () => DB::transaction(fn () => Intervencion::create([
            'id_simulacion' => $s->getKey(), 'id_participante_simulacion' => $s->participantes()->where('rol', 'abogado_defensor')->sole()->getKey(),
            'id_tipo_audiencia' => $s->id_tipo_audiencia, 'id_etapa' => $s->id_etapa_actual,
            'orden' => 2, 'contenido' => 'Falso', 'tipo_entrada' => 'texto'])));
        $fila = $this->entrada($s);
        foreach ([['id_usuario_responsable' => $e['admin']->getKey()], ['orden' => 3],
            ['ids_fragmento' => '[1,1]'], ['ids_fragmento' => '{}']] as $cambio) {
            $this->rechazo(QueryException::class, fn () => DB::transaction(fn () => DB::table('registros_intervenciones_regladas')->insert([
                ...$fila, 'id_simulacion' => $s->getKey(), 'id_usuario_responsable' => $e['owner']->getKey(),
                'ids_fragmento' => '[]', ...$cambio])));
        }
        try {
            $this->registrar($e, $s, [99999], ['contenido' => 'MARCADOR_PRIVADO']);
            $this->fail('Se esperaba rechazo.');
        } catch (DomainException $error) {
            $this->assertStringNotContainsString('MARCADOR_PRIVADO', $error->getMessage());
            $this->assertStringNotContainsString('SELECT', $error->getMessage());
        }
    }

    public function test_sql_servidor_sustituye_autoria_fuentes_fecha_e_identificadores_derivados(): void
    {
        [$e, $s] = $this->lista();
        [$f] = $this->fuentes($e);
        DB::table('registros_intervenciones_regladas')->insert([...$this->entrada($s),
            'id_simulacion' => $s->getKey(), 'id_usuario_responsable' => $e['owner']->getKey(),
            'id_intervencion' => 999999, 'id_vinculacion' => 999999, 'id_participante_simulacion' => 999999,
            'ids_fragmento' => json_encode([$f->getKey()]), 'fuentes' => '[{"metadatos":{"titulo":"Falso"}}]',
            'fecha_creacion' => '2000-01-01']);
        $r = RegistroIntervencionReglada::query()->sole();
        $this->assertNotSame(999999, $r->id_intervencion);
        $this->assertSame('sintetico.pdf', $r->fuentes[0]['metadatos']['titulo']);
        $this->assertNotSame('2000-01-01', $r->fecha_creacion->toDateString());
        $this->assertDatabaseCount('fuentes_intervencion', 1);
    }

    public function test_historial_y_citas_no_se_editan_borran_amplian_o_truncan(): void
    {
        [$e, $s] = $this->lista();
        [$f, $j] = $this->fuentes($e);
        $i = $this->registrar($e, $s, [$f->getKey()]);
        $r = RegistroIntervencionReglada::query()->sole();
        $this->rechazo(LogicException::class, fn () => $r->forceFill(['contenido' => 'Cambio'])->save());
        $this->rechazo(LogicException::class, fn () => $r->delete());
        foreach ([
            fn () => DB::table('registros_intervenciones_regladas')->update(['contenido' => 'Cambio']),
            fn () => DB::table('registros_intervenciones_regladas')->delete(),
            fn () => DB::table('intervenciones')->where('id_intervencion', $i->getKey())->update(['contenido' => 'Cambio']),
            fn () => $i->delete(),
            fn () => DB::table('fuentes_intervencion')->update(['fragmento_utilizado' => 'Cambio']),
            fn () => DB::table('fuentes_intervencion')->delete(),
            fn () => DB::table('fuentes_intervencion')->insert(['id_intervencion' => $i->getKey(),
                'id_fragmento' => $j->getKey(), 'fragmento_utilizado' => $j->contenido]),
            fn () => DB::statement('TRUNCATE registros_intervenciones_regladas'),
            fn () => DB::statement('TRUNCATE fuentes_intervencion'),
            fn () => DB::statement('TRUNCATE intervenciones CASCADE'),
            fn () => $s->participantes()->where('rol', 'abogado_defensor')->update(['rol' => 'fiscal']),
            fn () => $s->delete(),
        ] as $accion) {
            $this->rechazo(QueryException::class, fn () => DB::transaction($accion));
        }
        $m = require database_path('migrations/2026_10_04_000004_registrar_defensa_reglada.php');
        $this->rechazo(RuntimeException::class, fn () => $m->down());
        $this->assertDatabaseCount('registros_intervenciones_regladas', 1);
        $this->assertDatabaseCount('fuentes_intervencion', 1);
    }

    public function test_cambios_a_las_fuentes_vivas_no_reescriben_la_copia_historica(): void
    {
        [$e, $s] = $this->lista();
        [$f, $j] = $this->fuentes($e);
        $this->registrar($e, $s, [$f->getKey(), $j->getKey()]);
        $antes = RegistroIntervencionReglada::query()->sole()->fuentes;
        $f->pagina->update(['texto_extraido' => 'Contenido cambiado.', 'es_legible' => false]);
        $j->fuenteJuridica->update(['contenido_original' => 'Otra version.', 'validada' => false]);
        $this->assertSame($antes, RegistroIntervencionReglada::query()->sole()->fuentes);
        $this->assertSame($antes[0]['fragmento_utilizado'], DB::table('fuentes_intervencion')->orderBy('id_fuente_intervencion')->first()->fragmento_utilizado);
    }

    public function test_rollback_vacio_restaura_el_bloqueo_de_intervenciones(): void
    {
        [$e, $s] = $this->lista();
        $m = require database_path('migrations/2026_10_04_000004_registrar_defensa_reglada.php');
        $m->down();
        $this->rechazo(QueryException::class, fn () => DB::transaction(fn () => Intervencion::create([
            'id_simulacion' => $s->getKey(), 'id_participante_simulacion' => $s->participantes()->where('rol', 'abogado_defensor')->sole()->getKey(),
            'id_tipo_audiencia' => $s->id_tipo_audiencia, 'id_etapa' => $s->id_etapa_actual,
            'orden' => 2, 'contenido' => 'Sintetico.', 'tipo_entrada' => 'texto'])));
        $m->up();
        $this->assertSame(2, $this->registrar($e, $s)->orden);
    }

    public function test_catalogo_editado_no_reasigna_el_turno_de_la_copia(): void
    {
        [$e, $s] = $this->lista();
        PropuestaTurnoAudiencia::query()->where('id_etapa', $s->id_etapa_actual)->update(['rol' => 'fiscal']);
        $e['stages'][1]->update(['activo' => false]);
        $this->assertSame(2, $this->registrar($e, $s)->orden);
    }

    public function test_practica_finalizada_no_recibe_intervenciones_nuevas(): void
    {
        [$e, $s] = $this->lista();
        $this->registrar($e, $s);
        app(MoverPracticaReglada::class)->ejecutar($e['owner'], $s->getKey(), 'finalizar', 'activa', $s->id_etapa_actual);
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s, [], ['orden' => 3]));
        $this->assertDatabaseCount('registros_intervenciones_regladas', 1);
    }

    public function test_fuente_parcial_legible_y_espacios_normalizados_sin_cambiar_cita(): void
    {
        [$e, $s] = $this->lista();
        [$f] = $this->fuentes($e);
        $f->archivo->update(['estado_procesamiento' => 'error']);
        $f->update(['contenido' => "DOCUMENTO  SINTÉTICO\npara pruebas."]);
        $this->registrar($e, $s, [$f->getKey()]);
        $this->assertSame($f->contenido, RegistroIntervencionReglada::query()->sole()->fuentes[0]['fragmento_utilizado']);
    }

    public function test_fragmento_excesivo_bloquea_sin_dejar_registro_parcial(): void
    {
        [$e, $s] = $this->lista();
        [$f] = $this->fuentes($e);
        $f->update(['contenido' => str_repeat('a', 10001)]);
        $this->rechazo(DomainException::class, fn () => $this->registrar($e, $s, [$f->getKey()]));
        $this->assertDatabaseCount('registros_intervenciones_regladas', 0);
        $this->assertDatabaseCount('fuentes_intervencion', 0);
    }

    public function test_sql_participante_auditado_y_fragmento_citado_no_se_eliminan(): void
    {
        [$e, $s] = $this->lista();
        [$f] = $this->fuentes($e);
        $this->registrar($e, $s, [$f->getKey()]);
        $p = $s->participantes()->where('rol', 'abogado_defensor')->sole();
        foreach ([fn () => $p->delete(), fn () => $p->update(['controlado_por' => 'ia'])] as $accion) {
            $this->rechazo(QueryException::class, fn () => DB::transaction($accion));
        }
        // FK diferida: forzar su comprobacion dentro del savepoint de prueba.
        $this->rechazo(QueryException::class, fn () => DB::transaction(function () use ($f): void {
            $f->delete();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }));
        $this->assertDatabaseCount('fuentes_intervencion', 1);
    }

    public function test_contexto_con_snapshot_sql_obsoleto_no_registra(): void
    {
        $conexion = DB::build(config('database.connections.pgsql'));
        $this->assertSame('jurissim_pruebas', $conexion->selectOne('SELECT current_database() nombre')->nombre);
        $conexion->beginTransaction();
        try {
            $conexion->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            try {
                $conexion->statement("INSERT INTO registros_intervenciones_regladas(id_simulacion,id_usuario_responsable,id_movimiento,id_etapa,id_propuesta_turno,orden,contenido) VALUES (1,1,1,1,1,1,'Sintetico.')");
                $this->fail('Se esperaba rechazo de aislamiento.');
            } catch (QueryException $error) {
                $this->assertSame('23514', $error->errorInfo[0]);
                $this->assertStringContainsString('Movimiento exige read committed.', $error->errorInfo[2]);
            }
        } finally {
            $conexion->rollBack();
            $conexion->disconnect();
        }
    }

    private function lista(): array
    {
        $e = $this->escenario();
        $s = app(MoverPracticaReglada::class)->ejecutar($e['owner'], $this->crear($e)->getKey(), 'iniciar', 'preparando', null);
        // Historia de juez futura, solo DDL sintetico en la base de pruebas; sin abrir agentes en produccion.
        DB::transaction(function () use ($s): void {
            DB::statement('ALTER TABLE intervenciones DISABLE TRIGGER impedir_intervencion_vinculada');
            try {
                Intervencion::create(['id_simulacion' => $s->getKey(), 'id_participante_simulacion' => $s->participantes()->where('rol', 'juez')->sole()->getKey(),
                    'id_tipo_audiencia' => $s->id_tipo_audiencia, 'id_etapa' => $s->id_etapa_actual,
                    'orden' => 1, 'contenido' => 'Turno sintetico de juez.', 'tipo_entrada' => 'texto']);
            } finally {
                DB::statement('ALTER TABLE intervenciones ENABLE TRIGGER impedir_intervencion_vinculada');
            }
        });
        $s = app(MoverPracticaReglada::class)->ejecutar($e['owner'], $s->getKey(), 'avanzar', 'activa', $s->id_etapa_actual);

        return [$e, $s];
    }

    private function entrada(Simulacion $s): array
    {
        return ['id_movimiento' => (int) MovimientoPracticaReglada::query()->where('id_simulacion', $s->getKey())->orderByDesc('orden')->value('id_movimiento'),
            'id_etapa' => $s->id_etapa_actual ?? 1, 'id_propuesta_turno' => $s->configuracion['turnos_por_etapa'][$s->id_etapa_actual][0]['id_propuesta_turno'] ?? 1,
            'orden' => 2, 'contenido' => "Defensa sintética.\nSin valor jurídico."];
    }

    private function registrar(array $e, Simulacion $s, array $ids = [], array $cambio = []): Intervencion
    {
        $f = [...$this->entrada($s), 'ids_fragmento' => $ids, ...$cambio];

        return app(RegistrarDefensaReglada::class)->ejecutar($e['owner'], $s->getKey(),
            $f['id_movimiento'], $f['id_etapa'], $f['id_propuesta_turno'], $f['orden'], $f['contenido'], $f['ids_fragmento']);
    }

    private function fuentes(array $e): array
    {
        $archivo = $e['case']->archivos()->create(['nombre_original' => 'sintetico.pdf', 'nombre_almacenado' => 'sintetico.pdf',
            'tipo_mime' => 'application/pdf', 'extension' => 'pdf', 'disco' => 'local', 'ruta_almacenamiento' => 'sintetico.pdf',
            'tamano_bytes' => 128, 'estado_procesamiento' => 'procesado']);
        $pagina = $archivo->paginas()->create(['numero_pagina' => 1, 'localizador' => 'Pagina 1',
            'texto_extraido' => 'Documento sintético para pruebas. Sin valor jurídico.', 'es_legible' => true]);
        $f = $archivo->fragmentos()->create(['id_pagina' => $pagina->getKey(), 'contenido' => 'Documento sintético para pruebas.',
            'indice_fragmento' => 0, 'numero_pagina' => 1]);
        $texto = 'Norma sintética para pruebas. Sin valor jurídico.';
        $fuente = FuenteJuridica::create(['titulo' => 'Norma sintetica', 'tipo_fuente' => 'ley', 'numero_norma' => 'PRUEBA',
            'version' => '1', 'fecha_vigencia' => now('America/La_Paz')->subDay()->toDateString(),
            'contenido_original' => $texto, 'estado' => 'vigente', 'validada' => true]);
        $j = $fuente->fragmentos()->create(['contenido' => 'Norma sintética para pruebas.', 'indice_fragmento' => 0,
            'metadatos' => ['md5_contenido_fuente' => md5($texto)]]);

        return [$f, $j];
    }

    protected function escenario(?int $segundosRevision = null, bool $ramificada = false): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('administrador_plataforma', 'web'));
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $type = TipoAudiencia::create(['nombre' => 'Practica sintetica', 'codigo' => 'sintetica', 'activo' => true]);
        $stages = $instructions = [];
        foreach ($ramificada ? [1, 2, 3, 4] : [1, 2] as $orden) {
            $etapa = EtapaAudiencia::create(['id_tipo_audiencia' => $type->getKey(), 'codigo' => 'sintetica-'.$orden,
                'nombre' => 'Etapa sintetica', 'orden' => $orden, 'es_inicial' => $orden === 1, 'es_final' => $orden === ($ramificada ? 4 : 2), 'activo' => true]);
            $stages[] = $etapa;
            $p = new PropuestaTurnoAudiencia(['id_tipo_audiencia' => $type->getKey(), 'id_etapa' => $etapa->getKey(),
                'orden' => 1, 'rol' => $orden === 1 ? 'juez' : 'abogado_defensor', 'acto_propuesto' => 'Acto sintetico', 'descripcion_propuesta' => 'No es una instruccion.']);
            $p->id_usuario_creador = $admin->getKey();
            $p->save();
            $instructions[] = ['id_propuesta_turno' => $p->getKey(), 'texto' => "  Instruccion sintetica {$orden}.\nSin valor juridico.  "];
        }
        foreach ($ramificada ? [[0, 1], [0, 2], [1, 3], [2, 3]] : [[0, 1]] as [$origen, $destino]) {
            TransicionAudiencia::create(['id_tipo_audiencia' => $type->getKey(), 'id_etapa_origen' => $stages[$origen]->getKey(), 'id_etapa_destino' => $stages[$destino]->getKey(), 'activo' => true]);
        }
        $version = app(SellarVersionReglasAudiencia::class)->ejecutar($admin, $type->getKey(), 'Version sintetica.', $instructions, true);
        $grant = app(GestionarRevisionJuridicaReglas::class)->habilitar($admin, $reviewer->getKey(), $type->getKey(), [
            'alcance' => 'Ambito sintetico sin valor profesional.', 'referencia_acreditacion' => 'Evidencia sintetica.',
            'huella_acreditacion' => str_repeat('a', 64), 'acreditacion_verificada' => true, 'fecha_vencimiento' => now()->addDays(3)->toIso8601String(),
        ]);
        $case = Expediente::factory()->for($owner, 'usuario')->create();
        $analysis = $case->analisis()->create(['estado' => 'procesado', 'version' => 1]);
        $e = compact('admin', 'owner', 'reviewer', 'type', 'stages', 'instructions', 'version', 'grant', 'case', 'analysis');
        $this->aprobarAnalisis($e);
        $review = $this->revisar($e, $segundosRevision);
        $activation = $this->activar($e, $review);

        return [...$e, ...compact('review', 'activation')];
    }

    protected function revisar(array $e, ?int $segundos = null)
    {
        return app(GestionarRevisionJuridicaReglas::class)->registrar($e['reviewer'], $e['version']->getKey(), [
            'decision' => 'aprobado', 'observacion' => 'Revision sintetica sin valor juridico.',
            'fuentes_verificadas' => [['referencia' => 'Fuente sintetica.', 'huella_contenido' => str_repeat('b', 64)]],
            'vigencia_comprobada_en' => now('America/La_Paz')->toDateString(), 'confirmacion_humana' => true,
            'fecha_vencimiento' => ($segundos === null ? now()->addDays(2) : now()->addSeconds($segundos))->toIso8601String(),
        ], null, $e['version']->huella_contenido);
    }

    protected function activar(array $e, $r, ?int $previa = null)
    {
        return app(GestionarActivacionReglas::class)->activar($e['admin'], $e['version']->getKey(), [
            'motivo' => 'Activacion sintetica.', 'confirmacion_humana' => true, 'huella_version_esperada' => $e['version']->huella_contenido,
            'id_revision_esperada' => $r->getKey(), 'id_activacion_previa' => $previa,
        ]);
    }

    protected function aprobarAnalisis(array $e, string $decision = 'aprobado'): void
    {
        $e['analysis']->revisiones()->create(['id_expediente' => $e['case']->getKey(), 'id_usuario' => $e['owner']->getKey(),
            'decision' => $decision, 'observacion' => 'Revision sintetica.']);
    }

    protected function crear(array $e): Simulacion
    {
        return app(CrearPracticaConReglas::class)->ejecutar($e['owner'], $e['case']->getKey(), $e['analysis']->getKey(), $e['type']->getKey(), $e['activation']->getKey());
    }

    private function rechazo(string $clase, callable $accion): void
    {
        try {
            $accion();
        } catch (\Throwable $error) {
            $this->assertInstanceOf($clase, $error, $error->getMessage());

            return;
        }
        $this->fail('Se esperaba rechazo: '.$clase);
    }
}
