<?php

namespace Tests\Feature;

use App\Models\AnalisisExpediente;
use App\Models\ArchivoExpediente;
use App\Models\CriterioRubrica;
use App\Models\CronologiaExpediente;
use App\Models\DelitoExpediente;
use App\Models\EtapaAudiencia;
use App\Models\Evaluacion;
use App\Models\Expediente;
use App\Models\FragmentoDocumento;
use App\Models\FuenteIntervencion;
use App\Models\FuenteJuridica;
use App\Models\HechoExpediente;
use App\Models\HistorialProcesamiento;
use App\Models\IncidenciaAnalisis;
use App\Models\Intervencion;
use App\Models\ObjetoPendienteEliminacion;
use App\Models\PaginaExpediente;
use App\Models\ParticipanteExpediente;
use App\Models\ParticipanteSimulacion;
use App\Models\PruebaExpediente;
use App\Models\ReferenciaExpediente;
use App\Models\ResultadoEvaluacion;
use App\Models\Rubrica;
use App\Models\Simulacion;
use App\Models\TipoAudiencia;
use App\Models\TransicionAudiencia;
use App\Models\User;
use Database\Seeders\AudienciasSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BaseDatosJurissimTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'jurissim_pruebas'
            || config('database.connections.pgsql.url')) {
            throw new \RuntimeException('Las pruebas destructivas requieren PostgreSQL jurissim_pruebas, sin DB_URL.');
        }
    }

    public function test_migraciones_y_convenciones_del_dominio(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        foreach ([
            Expediente::class, ArchivoExpediente::class, PaginaExpediente::class, AnalisisExpediente::class,
            ParticipanteExpediente::class, DelitoExpediente::class, HechoExpediente::class, PruebaExpediente::class,
            CronologiaExpediente::class, IncidenciaAnalisis::class, ReferenciaExpediente::class,
            HistorialProcesamiento::class, FuenteJuridica::class, FragmentoDocumento::class,
            TipoAudiencia::class, EtapaAudiencia::class, TransicionAudiencia::class, Simulacion::class,
            ParticipanteSimulacion::class, Intervencion::class, FuenteIntervencion::class, Rubrica::class,
            CriterioRubrica::class, Evaluacion::class, ResultadoEvaluacion::class, ObjetoPendienteEliminacion::class,
        ] as $clase) {
            $modelo = new $clase;
            $this->assertTrue(Schema::hasTable($modelo->getTable()));
            $columnas = Schema::getColumnListing($modelo->getTable());
            $this->assertContains($modelo->getKeyName(), $columnas);
            $this->assertContains('fecha_creacion', $columnas);
            $this->assertNotContains('created_at', $columnas);
            $this->assertNotContains('updated_at', $columnas);
        }
        $this->assertTrue(Schema::hasColumn('users', 'created_at'));
        $this->assertSame('jsonb', Schema::getColumnType('analisis_expediente', 'datos_estructurados'));
    }

    public function test_seeders_son_idempotentes_y_no_insertan_usuarios_o_expedientes(): void
    {
        $this->seed(DatabaseSeeder::class);
        TipoAudiencia::where('codigo', 'incidental')->update(['activo' => true]);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('tipos_audiencia', 3);
        $this->assertDatabaseCount('etapas_audiencia', 7);
        $this->assertDatabaseCount('transiciones_audiencia', 6);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('expedientes', 0);
        $this->assertTrue(TipoAudiencia::where('codigo', 'incidental')->sole()->activo);
        $this->assertFalse(TipoAudiencia::where('codigo', 'juicio_oral')->sole()->activo);
        $this->assertTrue(EtapaAudiencia::where('codigo', 'apertura')->sole()->es_inicial);
        $this->assertTrue(EtapaAudiencia::where('codigo', 'cierre')->sole()->es_final);
    }

    public function test_usuario_archivos_paginas_y_timestamps_personalizados(): void
    {
        $usuario = User::factory()->create();
        $expedientes = Expediente::factory()->count(2)->for($usuario, 'usuario')->create();
        $archivos = ArchivoExpediente::factory()->count(2)->for($expedientes[0], 'expediente')->create();
        foreach ([1, 2] as $numero) {
            $archivos[0]->paginas()->create(['numero_pagina' => $numero, 'localizador' => 'Página '.$numero, 'texto_extraido' => 'Texto.', 'uso_ocr' => true, 'nivel_confianza' => 0.9]);
        }
        $this->assertCount(2, $usuario->expedientes);
        $this->assertCount(2, $expedientes[0]->archivos);
        $this->assertCount(2, $archivos[0]->paginas);
        $pagina = $archivos[0]->paginas->first();
        $this->assertTrue($pagina->archivo->is($archivos[0]));
        $this->assertTrue($pagina->uso_ocr);
        $this->assertSame('Página 1', $pagina->localizador);
        $this->assertNotNull($pagina->fecha_creacion);
        $this->assertArrayNotHasKey('ruta_almacenamiento', $archivos[0]->toArray());
    }

    public function test_propiedad_no_es_asignable_desde_datos_del_cliente(): void
    {
        $propietario = User::factory()->create();
        $otro = User::factory()->create();
        $expediente = $propietario->expedientes()->create(['titulo' => 'Privado', 'id_usuario' => $otro->id]);
        $this->assertSame($propietario->id, $expediente->id_usuario);
        $this->assertFalse((new Simulacion)->isFillable('id_usuario'));
    }

    public function test_analisis_versionados_elementos_y_trazabilidad(): void
    {
        $archivo = ArchivoExpediente::factory()->create();
        $expediente = $archivo->expediente;
        $analisis = $expediente->analisis()->create(['version' => 1, 'datos_estructurados' => ['faltantes' => ['fecha']]]);
        $otro = $expediente->analisis()->create(['version' => 2]);
        $pagina = $archivo->paginas()->create(['numero_pagina' => 1, 'texto_extraido' => 'Texto original']);
        $hecho = $analisis->hechos()->create(['id_expediente' => $expediente->getKey(), 'descripcion' => 'Hecho extraído']);
        $referencia = $hecho->referencias()->create([
            'id_expediente' => $expediente->getKey(), 'id_analisis' => $analisis->getKey(),
            'id_archivo' => $archivo->getKey(), 'id_pagina' => $pagina->getKey(), 'texto_fuente' => 'Texto original',
        ]);
        $this->assertFalse($hecho->fresh()->confirmado);
        $this->assertCount(2, $expediente->analisis);
        $this->assertCount(0, $otro->hechos);
        $this->assertSame(['faltantes' => ['fecha']], $analisis->fresh()->datos_estructurados);
        $this->assertTrue($referencia->pagina->is($pagina));
        $this->assertTrue($referencia->hecho->is($hecho));
        $delito = $analisis->delitos()->create(['id_expediente' => $expediente->getKey(), 'nombre_delito' => 'Sin validar', 'articulo_referido' => 'Referencia no verificada']);
        $this->assertFalse($delito->fresh()->confirmado);
    }

    public function test_duplicar_numero_pagina_es_rechazado(): void
    {
        $archivo = ArchivoExpediente::factory()->create();
        $archivo->paginas()->create(['numero_pagina' => 1]);
        $this->expectException(QueryException::class);
        $archivo->paginas()->create(['numero_pagina' => 1]);
    }

    public function test_duplicar_version_analisis_es_rechazado(): void
    {
        $expediente = Expediente::factory()->create();
        $expediente->analisis()->create(['version' => 1]);
        $this->expectException(QueryException::class);
        $expediente->analisis()->create(['version' => 1]);
    }

    public function test_extraer_con_analisis_de_otro_expediente_es_rechazado(): void
    {
        $a = Simulacion::factory()->create();
        $b = Expediente::factory()->create();
        $this->expectException(QueryException::class);
        HechoExpediente::create(['id_expediente' => $b->getKey(), 'id_analisis' => $a->id_analisis, 'descripcion' => 'Inválido']);
    }

    public function test_referencia_a_pagina_de_otro_archivo_es_rechazada(): void
    {
        $simulacion = Simulacion::factory()->create();
        $a = ArchivoExpediente::factory()->create(['id_expediente' => $simulacion->id_expediente]);
        $b = ArchivoExpediente::factory()->create(['id_expediente' => $simulacion->id_expediente]);
        $pagina = $b->paginas()->create(['numero_pagina' => 1]);
        $hecho = $simulacion->analisis->hechos()->create(['id_expediente' => $simulacion->id_expediente, 'descripcion' => 'Hecho']);
        $this->expectException(QueryException::class);
        $hecho->referencias()->create([
            'id_expediente' => $simulacion->id_expediente, 'id_analisis' => $simulacion->id_analisis,
            'id_archivo' => $a->getKey(), 'id_pagina' => $pagina->getKey(),
        ]);
    }

    public function test_confianza_fuera_de_rango_es_rechazada(): void
    {
        $archivo = ArchivoExpediente::factory()->create();
        $this->expectException(QueryException::class);
        $archivo->paginas()->create(['numero_pagina' => 1, 'nivel_confianza' => 1.5]);
    }

    public function test_simulacion_fija_analisis_y_ordena_intervenciones(): void
    {
        $simulacion = Simulacion::factory()->create();
        foreach ([3, 1, 2] as $orden) {
            Intervencion::factory()->create(['id_simulacion' => $simulacion->getKey(), 'orden' => $orden]);
        }
        $this->assertTrue($simulacion->usuario->is($simulacion->expediente->usuario));
        $this->assertTrue($simulacion->analisis->expediente->is($simulacion->expediente));
        $this->assertSame([1, 2, 3], $simulacion->intervenciones->pluck('orden')->all());
        $this->assertTrue($simulacion->intervenciones->first()->participante->simulacion->is($simulacion));
    }

    public function test_orden_duplicado_de_intervencion_es_rechazado(): void
    {
        $intervencion = Intervencion::factory()->create();
        $this->expectException(QueryException::class);
        Intervencion::factory()->create(['id_simulacion' => $intervencion->id_simulacion, 'orden' => 1]);
    }

    public function test_simulacion_no_puede_usar_expediente_de_otro_usuario(): void
    {
        $usuario = User::factory()->create();
        $this->expectException(QueryException::class);
        Simulacion::factory()->create(['id_usuario' => $usuario->id]);
    }

    public function test_etapa_de_otro_tipo_audiencia_es_rechazada(): void
    {
        $this->seed(AudienciasSeeder::class);
        $tipo = TipoAudiencia::where('codigo', 'incidental')->sole();
        $etapa = $tipo->etapas()->create(['codigo' => 'apertura', 'nombre' => 'Apertura', 'orden' => 1]);
        $this->expectException(QueryException::class);
        Simulacion::factory()->create(['id_etapa_actual' => $etapa->getKey()]);
    }

    public function test_simulacion_no_admite_analisis_de_otro_expediente_al_commit(): void
    {
        $otra = Simulacion::factory()->create();
        $this->expectException(QueryException::class);
        Simulacion::factory()->create(['id_analisis' => $otra->id_analisis]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_participante_detectado_de_otro_analisis_es_rechazado_al_commit(): void
    {
        $a = Intervencion::factory()->create();
        $b = Simulacion::factory()->create();
        $detectado = $b->analisis->participantes()->create([
            'id_expediente' => $b->id_expediente, 'nombre' => 'Persona', 'tipo_participante' => 'imputado',
        ]);
        $this->expectException(QueryException::class);
        $a->participante->update(['id_participante_expediente' => $detectado->getKey()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_analisis_en_uso_no_se_elimina_individualmente(): void
    {
        $simulacion = Simulacion::factory()->create();
        $this->expectException(QueryException::class);
        $simulacion->analisis->delete();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_intervencion_no_puede_usar_participante_de_otra_simulacion(): void
    {
        $a = Intervencion::factory()->create();
        $this->expectException(QueryException::class);
        Intervencion::factory()->create(['id_participante_simulacion' => $a->id_participante_simulacion]);
    }

    public function test_fragmento_juridico_y_privado_tienen_relaciones_validas(): void
    {
        $archivo = ArchivoExpediente::factory()->create();
        $privado = $archivo->fragmentos()->create(['contenido' => 'Texto privado', 'indice_fragmento' => 0]);
        $fuente = FuenteJuridica::create(['titulo' => 'Material de prueba', 'tipo_fuente' => 'otro']);
        $publico = $fuente->fragmentos()->create(['contenido' => 'Texto de referencia', 'indice_fragmento' => 0]);
        $this->assertTrue($privado->archivo->is($archivo));
        $this->assertTrue($publico->fuenteJuridica->is($fuente));
        $this->assertFalse($fuente->fresh()->validada);
    }

    public function test_fragmento_con_dos_origenes_es_rechazado(): void
    {
        $archivo = ArchivoExpediente::factory()->create();
        $fuente = FuenteJuridica::create(['titulo' => 'Fuente', 'tipo_fuente' => 'otro']);
        $this->expectException(QueryException::class);
        $archivo->fragmentos()->create(['id_fuente_juridica' => $fuente->getKey(), 'contenido' => 'Texto', 'indice_fragmento' => 0]);
    }

    public function test_cita_de_otro_expediente_es_rechazada(): void
    {
        $intervencion = Intervencion::factory()->create();
        $archivo = ArchivoExpediente::factory()->create();
        $fragmento = $archivo->fragmentos()->create(['contenido' => 'Privado', 'indice_fragmento' => 0]);
        $this->expectException(QueryException::class);
        $intervencion->fuentes()->create(['id_fragmento' => $fragmento->getKey(), 'fragmento_utilizado' => 'Privado']);
    }

    private function evaluacionConCriterio(): array
    {
        $simulacion = Simulacion::factory()->create();
        $rubrica = Rubrica::create(['nombre' => 'Rúbrica de prueba '.fake()->uuid()]);
        $criterio = $rubrica->criterios()->create(['nombre' => 'Argumentación', 'descripcion' => 'Fundamentación', 'peso' => 1, 'puntaje_maximo' => 10, 'orden' => 1]);
        $evaluacion = $simulacion->evaluaciones()->create(['id_rubrica' => $rubrica->getKey()]);

        return [$evaluacion, $criterio];
    }

    public function test_evaluacion_resultados_y_relaciones(): void
    {
        [$evaluacion, $criterio] = $this->evaluacionConCriterio();
        $resultado = $evaluacion->resultados()->create(['id_criterio' => $criterio->getKey(), 'id_rubrica' => $criterio->id_rubrica, 'puntaje' => 8, 'evidencia' => 'Referencia de prueba']);
        $this->assertTrue($resultado->criterio->is($criterio));
        $this->assertTrue($resultado->evaluacion->rubrica->is($criterio->rubrica));
        $this->assertSame('8.0000', $resultado->fresh()->puntaje);
    }

    public function test_criterio_de_otra_rubrica_es_rechazado(): void
    {
        [$evaluacion] = $this->evaluacionConCriterio();
        [, $criterio] = $this->evaluacionConCriterio();
        $this->expectException(QueryException::class);
        $evaluacion->resultados()->create(['id_criterio' => $criterio->getKey(), 'id_rubrica' => $evaluacion->id_rubrica, 'puntaje' => 8]);
    }

    public function test_puntaje_superior_al_maximo_es_rechazado(): void
    {
        [$evaluacion, $criterio] = $this->evaluacionConCriterio();
        $this->expectException(QueryException::class);
        $evaluacion->resultados()->create(['id_criterio' => $criterio->getKey(), 'id_rubrica' => $criterio->id_rubrica, 'puntaje' => 11]);
    }

    public function test_no_se_reescribe_un_criterio_ya_evaluado(): void
    {
        [, $criterio] = $this->evaluacionConCriterio();
        $this->expectException(QueryException::class);
        $criterio->update(['puntaje_maximo' => 5]);
    }

    public function test_eliminar_expediente_por_sql_limpia_dependencias_y_conserva_claves_fisicas(): void
    {
        $intervencion = Intervencion::factory()->create();
        $simulacion = $intervencion->simulacion;
        $archivo = ArchivoExpediente::factory()->create(['id_expediente' => $simulacion->id_expediente]);
        $archivo->paginas()->create(['numero_pagina' => 1]);
        $fragmento = $archivo->fragmentos()->create(['contenido' => 'Privado', 'indice_fragmento' => 0]);
        $intervencion->fuentes()->create(['id_fragmento' => $fragmento->getKey(), 'fragmento_utilizado' => 'Privado']);
        $participante = $simulacion->analisis->participantes()->create(['id_expediente' => $simulacion->id_expediente, 'nombre' => 'Persona de prueba', 'tipo_participante' => 'imputado']);
        $simulacion->participantes->first()->update(['id_participante_expediente' => $participante->getKey()]);
        $hecho = $simulacion->analisis->hechos()->create(['id_expediente' => $simulacion->id_expediente, 'descripcion' => 'Hecho']);
        $hecho->referencias()->create(['id_expediente' => $simulacion->id_expediente, 'id_analisis' => $simulacion->id_analisis, 'id_archivo' => $archivo->getKey()]);
        $archivo->procesamientos()->create(['id_expediente' => $simulacion->id_expediente, 'tipo' => 'extraccion']);
        $rubrica = Rubrica::create(['nombre' => 'Rúbrica para cascada']);
        $criterio = $rubrica->criterios()->create(['nombre' => 'Criterio', 'descripcion' => 'Prueba', 'peso' => 1, 'puntaje_maximo' => 10, 'orden' => 1]);
        $evaluacion = $simulacion->evaluaciones()->create(['id_rubrica' => $rubrica->getKey()]);
        $evaluacion->resultados()->create(['id_rubrica' => $rubrica->getKey(), 'id_criterio' => $criterio->getKey(), 'puntaje' => 5]);
        $fuente = FuenteJuridica::create(['titulo' => 'Normativa compartida', 'tipo_fuente' => 'otro']);
        $fuente->fragmentos()->create(['contenido' => 'Compartido', 'indice_fragmento' => 0]);
        DB::table('expedientes')->where('id_expediente', $simulacion->id_expediente)->delete();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        foreach (['expedientes', 'archivos_expediente', 'paginas_expediente', 'analisis_expediente', 'participantes_expediente', 'hechos_expediente', 'referencias_expediente', 'historial_procesamiento', 'simulaciones', 'participantes_simulacion', 'intervenciones', 'fuentes_intervencion'] as $tabla) {
            $this->assertDatabaseCount($tabla, 0);
        }
        $this->assertDatabaseCount('fragmentos_documento', 1);
        $this->assertDatabaseCount('fuentes_juridicas', 1);
        $this->assertDatabaseCount('evaluaciones', 0);
        $this->assertDatabaseCount('resultados_evaluacion', 0);
        $this->assertDatabaseCount('rubricas', 1);
        $this->assertDatabaseHas('objetos_pendientes_eliminacion', ['ruta_almacenamiento' => $archivo->ruta_almacenamiento, 'estado' => 'pendiente']);
    }

    public function test_eliminar_usuario_limpia_expedientes_y_simulaciones(): void
    {
        $simulacion = Simulacion::factory()->create();
        ArchivoExpediente::factory()->create(['id_expediente' => $simulacion->id_expediente]);
        $simulacion->usuario->delete();
        $this->assertDatabaseCount('expedientes', 0);
        $this->assertDatabaseCount('simulaciones', 0);
        $this->assertDatabaseCount('archivos_expediente', 0);
        $this->assertDatabaseCount('objetos_pendientes_eliminacion', 1);
    }

    public function test_eliminacion_revertida_no_deja_tareas_de_borrado(): void
    {
        $archivo = ArchivoExpediente::factory()->create();
        DB::beginTransaction();
        $archivo->expediente->delete();
        $this->assertDatabaseCount('objetos_pendientes_eliminacion', 1);
        DB::rollBack();
        $this->assertDatabaseCount('objetos_pendientes_eliminacion', 0);
        $this->assertDatabaseHas('archivos_expediente', ['id_archivo' => $archivo->getKey()]);
    }

    public function test_fuente_citada_no_se_borra_y_preserva_auditoria(): void
    {
        $intervencion = Intervencion::factory()->create();
        $fuente = FuenteJuridica::create(['titulo' => 'Fuente', 'tipo_fuente' => 'otro']);
        $fragmento = $fuente->fragmentos()->create(['contenido' => 'Texto', 'indice_fragmento' => 0]);
        $intervencion->fuentes()->create(['id_fragmento' => $fragmento->getKey(), 'fragmento_utilizado' => 'Texto']);
        $this->expectException(QueryException::class);
        $fuente->delete();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }
}
