<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE archivos_expediente ADD CONSTRAINT archivos_expediente_tamano_bytes CHECK (tamano_bytes >= 0)');
        DB::statement('ALTER TABLE archivos_expediente ADD CONSTRAINT archivos_expediente_cantidad_paginas CHECK (cantidad_paginas > 0)');
        DB::statement('ALTER TABLE paginas_expediente ADD CONSTRAINT paginas_expediente_numero_pagina CHECK (numero_pagina > 0)');
        DB::statement('ALTER TABLE paginas_expediente ADD CONSTRAINT paginas_expediente_confianza CHECK (nivel_confianza BETWEEN 0 AND 1)');
        DB::statement('ALTER TABLE analisis_expediente ADD CONSTRAINT analisis_expediente_version CHECK (version > 0)');
        DB::statement('ALTER TABLE participantes_expediente ADD CONSTRAINT participantes_expediente_confianza CHECK (nivel_confianza BETWEEN 0 AND 1)');
        DB::statement('ALTER TABLE delitos_expediente ADD CONSTRAINT delitos_expediente_confianza CHECK (nivel_confianza BETWEEN 0 AND 1)');
        DB::statement('ALTER TABLE hechos_expediente ADD CONSTRAINT hechos_expediente_orden_cronologico CHECK (orden_cronologico >= 0)');
        DB::statement('ALTER TABLE hechos_expediente ADD CONSTRAINT hechos_expediente_confianza CHECK (nivel_confianza BETWEEN 0 AND 1)');
        DB::statement('ALTER TABLE pruebas_expediente ADD CONSTRAINT pruebas_expediente_confianza CHECK (nivel_confianza BETWEEN 0 AND 1)');
        DB::statement('ALTER TABLE cronologia_expediente ADD CONSTRAINT cronologia_expediente_orden CHECK (orden >= 0)');
        DB::statement('ALTER TABLE cronologia_expediente ADD CONSTRAINT cronologia_expediente_confianza CHECK (nivel_confianza BETWEEN 0 AND 1)');
        DB::statement('ALTER TABLE referencias_expediente ADD CONSTRAINT referencias_expediente_confianza CHECK (nivel_confianza BETWEEN 0 AND 1)');
        DB::statement('ALTER TABLE historial_procesamiento ADD CONSTRAINT historial_procesamiento_intento CHECK (intento > 0)');
        DB::statement('ALTER TABLE fragmentos_documento ADD CONSTRAINT fragmentos_documento_numero_pagina CHECK (numero_pagina > 0)');
        DB::statement('ALTER TABLE fragmentos_documento ADD CONSTRAINT fragmentos_documento_indice_fragmento CHECK (indice_fragmento >= 0)');
        DB::statement('ALTER TABLE fragmentos_documento ADD CONSTRAINT fragmentos_documento_cantidad_tokens CHECK (cantidad_tokens >= 0)');
        DB::statement('ALTER TABLE fragmentos_documento ADD CONSTRAINT fragmentos_documento_dimensiones_embedding CHECK (dimensiones_embedding > 0)');
        DB::statement('ALTER TABLE tipos_audiencia ADD CONSTRAINT tipos_audiencia_orden CHECK (orden >= 0)');
        DB::statement('ALTER TABLE etapas_audiencia ADD CONSTRAINT etapas_audiencia_orden CHECK (orden >= 0)');
        DB::statement('ALTER TABLE intervenciones ADD CONSTRAINT intervenciones_orden CHECK (orden >= 0)');
        DB::statement('ALTER TABLE rubricas ADD CONSTRAINT rubricas_version CHECK (version > 0)');
        DB::statement('ALTER TABLE criterios_rubrica ADD CONSTRAINT criterios_rubrica_orden CHECK (orden >= 0)');
        DB::statement('ALTER TABLE objetos_pendientes_eliminacion ADD CONSTRAINT objetos_pendientes_eliminacion_intentos CHECK (intentos >= 0)');
        DB::statement('ALTER TABLE fragmentos_documento ADD CONSTRAINT fragmentos_origen_exclusivo CHECK (num_nonnulls(id_archivo, id_fuente_juridica) = 1)');
        DB::statement('ALTER TABLE fragmentos_documento ADD CONSTRAINT fragmentos_pagina_privada CHECK (id_pagina IS NULL OR id_archivo IS NOT NULL)');
        DB::statement('ALTER TABLE fragmentos_documento ADD CONSTRAINT fragmentos_modelo_dimension CHECK ((modelo_embedding IS NULL) = (dimensiones_embedding IS NULL))');
        DB::statement('ALTER TABLE referencias_expediente ADD CONSTRAINT referencias_elemento_exclusivo CHECK (num_nonnulls(id_participante, id_delito, id_hecho, id_prueba, id_evento, id_incidencia) = 1)');
        DB::statement('ALTER TABLE historial_procesamiento ADD CONSTRAINT historial_fechas CHECK (fecha_fin IS NULL OR (fecha_inicio IS NOT NULL AND fecha_fin >= fecha_inicio))');
        DB::statement('ALTER TABLE fuentes_juridicas ADD CONSTRAINT fuentes_vigencia CHECK (fecha_fin_vigencia IS NULL OR (fecha_vigencia IS NOT NULL AND fecha_fin_vigencia >= fecha_vigencia))');
        DB::statement('ALTER TABLE fuentes_juridicas ADD CONSTRAINT fuentes_objeto_completo CHECK ((disco IS NULL) = (ruta_archivo IS NULL))');
        DB::statement('ALTER TABLE simulaciones ADD CONSTRAINT simulaciones_fechas CHECK (fecha_fin IS NULL OR (fecha_inicio IS NOT NULL AND fecha_fin >= fecha_inicio))');
        DB::statement('ALTER TABLE transiciones_audiencia ADD CONSTRAINT transiciones_etapas_distintas CHECK (id_etapa_origen <> id_etapa_destino)');
        DB::statement("ALTER TABLE participantes_simulacion ADD CONSTRAINT participantes_control_valido CHECK (controlado_por IN ('usuario', 'ia', 'sistema'))");
        DB::statement("ALTER TABLE intervenciones ADD CONSTRAINT intervenciones_entrada_valida CHECK (tipo_entrada IN ('texto', 'voz', 'sistema'))");
        DB::statement('ALTER TABLE criterios_rubrica ADD CONSTRAINT criterios_peso CHECK (peso > 0)');
        DB::statement('ALTER TABLE criterios_rubrica ADD CONSTRAINT criterios_maximo CHECK (puntaje_maximo > 0)');
        DB::statement('ALTER TABLE resultados_evaluacion ADD CONSTRAINT resultados_puntaje CHECK (puntaje >= 0)');
        DB::statement('ALTER TABLE evaluaciones ADD CONSTRAINT evaluaciones_puntaje CHECK (puntaje_total >= 0)');
        DB::statement("ALTER TABLE expedientes ADD CONSTRAINT expedientes_procesamiento CHECK (estado_procesamiento IN ('pendiente', 'procesando', 'procesado', 'error'))");
        DB::statement("ALTER TABLE archivos_expediente ADD CONSTRAINT archivos_expediente_procesamiento CHECK (estado_procesamiento IN ('pendiente', 'procesando', 'procesado', 'error'))");
        DB::statement("ALTER TABLE analisis_expediente ADD CONSTRAINT analisis_expediente_estado CHECK (estado IN ('pendiente', 'procesando', 'procesado', 'error'))");
        DB::statement("ALTER TABLE historial_procesamiento ADD CONSTRAINT historial_procesamiento_estado CHECK (estado IN ('pendiente', 'procesando', 'procesado', 'error'))");
        DB::statement("ALTER TABLE evaluaciones ADD CONSTRAINT evaluaciones_estado CHECK (estado IN ('pendiente', 'procesando', 'procesado', 'error'))");
        DB::statement("ALTER TABLE simulaciones ADD CONSTRAINT simulaciones_estado CHECK (estado IN ('preparando', 'activa', 'pausada', 'finalizada', 'cancelada', 'error'))");

        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX etapas_inicial_unica ON etapas_audiencia (id_tipo_audiencia) WHERE es_inicial AND activo;
CREATE UNIQUE INDEX etapas_final_unica ON etapas_audiencia (id_tipo_audiencia) WHERE es_final AND activo;

-- Guarda las claves antes de que las cascadas SQL eliminen el registro.
-- La cola no tiene FK al expediente: debe sobrevivir a su eliminación.
CREATE OR REPLACE FUNCTION jurissim_registrar_objeto_eliminado() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ruta text;
BEGIN
    IF TG_TABLE_NAME = 'fuentes_juridicas' THEN ruta := OLD.ruta_archivo;
    ELSE ruta := OLD.ruta_almacenamiento;
    END IF;
    IF ruta IS NOT NULL AND OLD.disco IS NOT NULL THEN
        INSERT INTO objetos_pendientes_eliminacion
            (disco, ruta_almacenamiento, estado, intentos, fecha_creacion)
        VALUES (OLD.disco, ruta, 'pendiente', 0, CURRENT_TIMESTAMP)
        ON CONFLICT (disco, ruta_almacenamiento) DO NOTHING;
    END IF;
    RETURN OLD;
END;
$$;
CREATE TRIGGER archivos_objeto_eliminado BEFORE DELETE ON archivos_expediente
FOR EACH ROW EXECUTE FUNCTION jurissim_registrar_objeto_eliminado();
CREATE TRIGGER fuentes_objeto_eliminado BEFORE DELETE ON fuentes_juridicas
FOR EACH ROW EXECUTE FUNCTION jurissim_registrar_objeto_eliminado();

CREATE OR REPLACE FUNCTION jurissim_validar_fuente_intervencion() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE expediente_fragmento bigint; expediente_simulacion bigint;
BEGIN
    SELECT a.id_expediente INTO expediente_fragmento
    FROM fragmentos_documento f JOIN archivos_expediente a USING (id_archivo)
    WHERE f.id_fragmento = NEW.id_fragmento;
    SELECT s.id_expediente INTO expediente_simulacion
    FROM intervenciones i JOIN simulaciones s USING (id_simulacion)
    WHERE i.id_intervencion = NEW.id_intervencion;
    IF expediente_fragmento IS NOT NULL AND expediente_fragmento IS DISTINCT FROM expediente_simulacion THEN
        RAISE EXCEPTION 'La fuente privada pertenece a otro expediente' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER fuentes_intervencion_contexto BEFORE INSERT OR UPDATE ON fuentes_intervencion
FOR EACH ROW EXECUTE FUNCTION jurissim_validar_fuente_intervencion();

-- Una vez referenciados, los contextos no se reasignan a otro propietario/origen.
-- Los contenidos y estados continúan siendo editables.
CREATE OR REPLACE FUNCTION jurissim_contexto_inmutable() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE atributo text;
BEGIN
    FOREACH atributo IN ARRAY TG_ARGV LOOP
        IF (to_jsonb(NEW)->atributo) IS DISTINCT FROM (to_jsonb(OLD)->atributo) THEN
            RAISE EXCEPTION 'El contexto % de % es inmutable', atributo, TG_TABLE_NAME USING ERRCODE = '23514';
        END IF;
    END LOOP;
    RETURN NEW;
END;
$$;
CREATE TRIGGER expediente_propietario_inmutable BEFORE UPDATE ON expedientes
FOR EACH ROW EXECUTE FUNCTION jurissim_contexto_inmutable('id_usuario');
CREATE TRIGGER archivo_contexto_inmutable BEFORE UPDATE ON archivos_expediente
FOR EACH ROW EXECUTE FUNCTION jurissim_contexto_inmutable('id_expediente', 'disco', 'ruta_almacenamiento');
CREATE TRIGGER fragmento_contexto_inmutable BEFORE UPDATE ON fragmentos_documento
FOR EACH ROW EXECUTE FUNCTION jurissim_contexto_inmutable('id_archivo', 'id_fuente_juridica', 'id_pagina');
CREATE TRIGGER simulacion_contexto_inmutable BEFORE UPDATE ON simulaciones
FOR EACH ROW EXECUTE FUNCTION jurissim_contexto_inmutable('id_usuario', 'id_expediente', 'id_analisis', 'id_tipo_audiencia');
CREATE TRIGGER intervencion_contexto_inmutable BEFORE UPDATE ON intervenciones
FOR EACH ROW EXECUTE FUNCTION jurissim_contexto_inmutable('id_simulacion', 'id_tipo_audiencia');
CREATE TRIGGER fuente_objeto_inmutable BEFORE UPDATE ON fuentes_juridicas
FOR EACH ROW EXECUTE FUNCTION jurissim_contexto_inmutable('disco', 'ruta_archivo');

CREATE OR REPLACE FUNCTION jurissim_validar_resultado() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE maximo numeric;
BEGIN
    SELECT puntaje_maximo INTO maximo FROM criterios_rubrica WHERE id_criterio = NEW.id_criterio;
    IF NEW.puntaje > maximo THEN
        RAISE EXCEPTION 'El puntaje supera el máximo del criterio' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER resultados_puntaje_maximo BEFORE INSERT OR UPDATE ON resultados_evaluacion
FOR EACH ROW EXECUTE FUNCTION jurissim_validar_resultado();

CREATE OR REPLACE FUNCTION jurissim_proteger_criterio_evaluado() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM evaluaciones WHERE id_rubrica = OLD.id_rubrica)
        AND (NEW.nombre, NEW.descripcion, NEW.peso, NEW.puntaje_maximo, NEW.orden, NEW.id_rubrica)
        IS DISTINCT FROM (OLD.nombre, OLD.descripcion, OLD.peso, OLD.puntaje_maximo, OLD.orden, OLD.id_rubrica) THEN
        RAISE EXCEPTION 'Cree una nueva versión de rúbrica para modificar criterios evaluados' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER criterios_version_evaluada BEFORE UPDATE ON criterios_rubrica
FOR EACH ROW EXECUTE FUNCTION jurissim_proteger_criterio_evaluado();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER criterios_version_evaluada ON criterios_rubrica');
        DB::statement('DROP TRIGGER resultados_puntaje_maximo ON resultados_evaluacion');
        DB::statement('DROP TRIGGER fuentes_intervencion_contexto ON fuentes_intervencion');
        DB::statement('DROP TRIGGER archivos_objeto_eliminado ON archivos_expediente');
        DB::statement('DROP TRIGGER fuentes_objeto_eliminado ON fuentes_juridicas');
        foreach ([
            'expedientes' => 'expediente_propietario_inmutable',
            'archivos_expediente' => 'archivo_contexto_inmutable',
            'fragmentos_documento' => 'fragmento_contexto_inmutable',
            'simulaciones' => 'simulacion_contexto_inmutable',
            'intervenciones' => 'intervencion_contexto_inmutable',
            'fuentes_juridicas' => 'fuente_objeto_inmutable',
        ] as $tabla => $trigger) {
            DB::statement("DROP TRIGGER {$trigger} ON {$tabla}");
        }
        DB::statement('DROP FUNCTION jurissim_registrar_objeto_eliminado()');
        DB::statement('DROP FUNCTION jurissim_validar_fuente_intervencion()');
        DB::statement('DROP FUNCTION jurissim_contexto_inmutable()');
        DB::statement('DROP FUNCTION jurissim_validar_resultado()');
        DB::statement('DROP FUNCTION jurissim_proteger_criterio_evaluado()');
        DB::statement('DROP INDEX etapas_inicial_unica');
        DB::statement('DROP INDEX etapas_final_unica');
        DB::statement('ALTER TABLE archivos_expediente DROP CONSTRAINT archivos_expediente_tamano_bytes');
        DB::statement('ALTER TABLE archivos_expediente DROP CONSTRAINT archivos_expediente_cantidad_paginas');
        DB::statement('ALTER TABLE paginas_expediente DROP CONSTRAINT paginas_expediente_numero_pagina');
        DB::statement('ALTER TABLE paginas_expediente DROP CONSTRAINT paginas_expediente_confianza');
        DB::statement('ALTER TABLE analisis_expediente DROP CONSTRAINT analisis_expediente_version');
        DB::statement('ALTER TABLE participantes_expediente DROP CONSTRAINT participantes_expediente_confianza');
        DB::statement('ALTER TABLE delitos_expediente DROP CONSTRAINT delitos_expediente_confianza');
        DB::statement('ALTER TABLE hechos_expediente DROP CONSTRAINT hechos_expediente_orden_cronologico');
        DB::statement('ALTER TABLE hechos_expediente DROP CONSTRAINT hechos_expediente_confianza');
        DB::statement('ALTER TABLE pruebas_expediente DROP CONSTRAINT pruebas_expediente_confianza');
        DB::statement('ALTER TABLE cronologia_expediente DROP CONSTRAINT cronologia_expediente_orden');
        DB::statement('ALTER TABLE cronologia_expediente DROP CONSTRAINT cronologia_expediente_confianza');
        DB::statement('ALTER TABLE referencias_expediente DROP CONSTRAINT referencias_expediente_confianza');
        DB::statement('ALTER TABLE historial_procesamiento DROP CONSTRAINT historial_procesamiento_intento');
        DB::statement('ALTER TABLE fragmentos_documento DROP CONSTRAINT fragmentos_documento_numero_pagina');
        DB::statement('ALTER TABLE fragmentos_documento DROP CONSTRAINT fragmentos_documento_indice_fragmento');
        DB::statement('ALTER TABLE fragmentos_documento DROP CONSTRAINT fragmentos_documento_cantidad_tokens');
        DB::statement('ALTER TABLE fragmentos_documento DROP CONSTRAINT fragmentos_documento_dimensiones_embedding');
        DB::statement('ALTER TABLE tipos_audiencia DROP CONSTRAINT tipos_audiencia_orden');
        DB::statement('ALTER TABLE etapas_audiencia DROP CONSTRAINT etapas_audiencia_orden');
        DB::statement('ALTER TABLE intervenciones DROP CONSTRAINT intervenciones_orden');
        DB::statement('ALTER TABLE rubricas DROP CONSTRAINT rubricas_version');
        DB::statement('ALTER TABLE criterios_rubrica DROP CONSTRAINT criterios_rubrica_orden');
        DB::statement('ALTER TABLE objetos_pendientes_eliminacion DROP CONSTRAINT objetos_pendientes_eliminacion_intentos');
        DB::statement('ALTER TABLE fragmentos_documento DROP CONSTRAINT fragmentos_origen_exclusivo');
        DB::statement('ALTER TABLE fragmentos_documento DROP CONSTRAINT fragmentos_pagina_privada');
        DB::statement('ALTER TABLE fragmentos_documento DROP CONSTRAINT fragmentos_modelo_dimension');
        DB::statement('ALTER TABLE referencias_expediente DROP CONSTRAINT referencias_elemento_exclusivo');
        DB::statement('ALTER TABLE historial_procesamiento DROP CONSTRAINT historial_fechas');
        DB::statement('ALTER TABLE fuentes_juridicas DROP CONSTRAINT fuentes_vigencia');
        DB::statement('ALTER TABLE fuentes_juridicas DROP CONSTRAINT fuentes_objeto_completo');
        DB::statement('ALTER TABLE simulaciones DROP CONSTRAINT simulaciones_fechas');
        DB::statement('ALTER TABLE transiciones_audiencia DROP CONSTRAINT transiciones_etapas_distintas');
        DB::statement('ALTER TABLE participantes_simulacion DROP CONSTRAINT participantes_control_valido');
        DB::statement('ALTER TABLE intervenciones DROP CONSTRAINT intervenciones_entrada_valida');
        DB::statement('ALTER TABLE criterios_rubrica DROP CONSTRAINT criterios_peso');
        DB::statement('ALTER TABLE criterios_rubrica DROP CONSTRAINT criterios_maximo');
        DB::statement('ALTER TABLE resultados_evaluacion DROP CONSTRAINT resultados_puntaje');
        DB::statement('ALTER TABLE evaluaciones DROP CONSTRAINT evaluaciones_puntaje');
        DB::statement('ALTER TABLE expedientes DROP CONSTRAINT expedientes_procesamiento');
        DB::statement('ALTER TABLE archivos_expediente DROP CONSTRAINT archivos_expediente_procesamiento');
        DB::statement('ALTER TABLE analisis_expediente DROP CONSTRAINT analisis_expediente_estado');
        DB::statement('ALTER TABLE historial_procesamiento DROP CONSTRAINT historial_procesamiento_estado');
        DB::statement('ALTER TABLE evaluaciones DROP CONSTRAINT evaluaciones_estado');
        DB::statement('ALTER TABLE simulaciones DROP CONSTRAINT simulaciones_estado');
    }
};
