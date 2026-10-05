<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_intervenciones_regladas', function (Blueprint $table): void {
            $table->id('id_registro');
            $table->unsignedBigInteger('id_intervencion')->unique();
            // El trigger reserva el ID e inserta la transcripcion dentro de la misma sentencia.
            $table->foreign('id_intervencion')->references('id_intervencion')->on('intervenciones')->noActionOnDelete()->deferrable()->initiallyImmediate(false);
            $table->foreignId('id_simulacion')->constrained('simulaciones', 'id_simulacion')->restrictOnDelete();
            $table->foreignId('id_vinculacion')->constrained('vinculaciones_reglas_practica', 'id_vinculacion')->restrictOnDelete();
            $table->foreignId('id_movimiento')->constrained('movimientos_practicas_regladas', 'id_movimiento')->restrictOnDelete();
            $table->foreignId('id_usuario_responsable')->constrained('users')->restrictOnDelete();
            $table->foreignId('id_participante_simulacion')->constrained('participantes_simulacion', 'id_participante_simulacion')->restrictOnDelete();
            $table->unsignedBigInteger('id_etapa');
            $table->unsignedBigInteger('id_propuesta_turno');
            $table->integer('orden');
            $table->text('contenido');
            $table->jsonb('ids_fragmento')->default('[]');
            $table->jsonb('fuentes')->default('[]');
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->unique(['id_simulacion', 'orden']);
        });
        DB::statement("ALTER TABLE registros_intervenciones_regladas ADD CHECK (orden > 0 AND id_etapa > 0 AND id_propuesta_turno > 0 AND char_length(contenido) BETWEEN 1 AND 2000 AND btrim(regexp_replace(contenido,'[[:space:]]+',' ','g')) <> '' AND jsonb_typeof(ids_fragmento)='array' AND jsonb_typeof(fuentes)='array' AND jsonb_array_length(fuentes)<=10 AND octet_length(fuentes::text)<=100000)");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION jurissim_normalizar_cita(texto text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
                SELECT lower(btrim(regexp_replace(COALESCE(texto,''),'[[:space:]]+',' ','g')));
            $$;
            CREATE OR REPLACE FUNCTION jurissim_fuentes_defensa(identificadores jsonb, expediente bigint) RETURNS jsonb LANGUAGE plpgsql AS $$
            DECLARE identificador jsonb; f fragmentos_documento; a archivos_expediente; p paginas_expediente;
                j fuentes_juridicas; meta jsonb; resultado jsonb := '[]'::jsonb; hoy date;
            BEGIN
                IF identificadores IS NULL OR jsonb_typeof(identificadores) <> 'array' THEN
                    RAISE EXCEPTION 'Seleccion incompatible.' USING ERRCODE='23514';
                END IF;
                IF jsonb_array_length(identificadores)>10 OR EXISTS (SELECT 1 FROM jsonb_array_elements(identificadores) e
                    WHERE jsonb_typeof(e)<>'number' OR e::text !~ '^[1-9][0-9]{0,17}$')
                    OR (SELECT count(DISTINCT e) FROM jsonb_array_elements(identificadores) e) <> jsonb_array_length(identificadores) THEN
                    RAISE EXCEPTION 'Seleccion incompatible.' USING ERRCODE='23514';
                END IF;
                -- Orden determinista entre fragmentos. Conflictos con escritores externos fallan sin reintento.
                PERFORM 1 FROM fragmentos_documento WHERE id_fragmento IN (SELECT e::text::bigint FROM jsonb_array_elements(identificadores) e)
                    ORDER BY id_fragmento FOR UPDATE;
                FOR identificador IN SELECT e FROM jsonb_array_elements(identificadores) e LOOP
                    SELECT * INTO f FROM fragmentos_documento WHERE id_fragmento=identificador::text::bigint;
                    IF f.id_fragmento IS NULL OR jurissim_normalizar_cita(f.contenido)='' OR char_length(f.contenido)>10000 THEN
                        RAISE EXCEPTION 'Fuente no verificable.' USING ERRCODE='23514';
                    END IF;
                    IF f.id_archivo IS NOT NULL AND f.id_fuente_juridica IS NULL THEN
                        SELECT * INTO a FROM archivos_expediente WHERE id_archivo=f.id_archivo FOR UPDATE;
                        SELECT * INTO p FROM paginas_expediente WHERE id_pagina=f.id_pagina FOR UPDATE;
                        IF a.id_archivo IS NULL OR a.id_expediente IS DISTINCT FROM expediente OR a.estado_procesamiento NOT IN ('procesado','error')
                            OR p.id_pagina IS NULL OR p.id_archivo IS DISTINCT FROM f.id_archivo OR NOT p.es_legible
                            OR strpos(jurissim_normalizar_cita(p.texto_extraido),jurissim_normalizar_cita(f.contenido))=0 THEN
                            RAISE EXCEPTION 'Fuente no verificable.' USING ERRCODE='23514';
                        END IF;
                        meta := jsonb_build_object('tipo_fuente','expediente','titulo',a.nombre_original,
                            'numero_pagina',p.numero_pagina,'localizador',p.localizador,
                            'hash_contenido_pagina',encode(sha256(convert_to(p.texto_extraido,'UTF8')),'hex'));
                    ELSIF f.id_archivo IS NULL AND f.id_fuente_juridica IS NOT NULL THEN
                        SELECT * INTO j FROM fuentes_juridicas WHERE id_fuente=f.id_fuente_juridica FOR UPDATE;
                        hoy := timezone('America/La_Paz',clock_timestamp())::date;
                        IF j.id_fuente IS NULL OR NOT j.validada OR j.estado <> 'vigente' OR j.fecha_vigencia IS NULL
                            OR j.fecha_vigencia > hoy OR (j.fecha_fin_vigencia IS NOT NULL AND j.fecha_fin_vigencia < hoy)
                            OR strpos(jurissim_normalizar_cita(j.contenido_original),jurissim_normalizar_cita(f.contenido))=0
                            OR f.metadatos->>'md5_contenido_fuente' IS DISTINCT FROM md5(j.contenido_original) THEN
                            RAISE EXCEPTION 'Fuente no verificable.' USING ERRCODE='23514';
                        END IF;
                        meta := jsonb_build_object('tipo_fuente','juridica','titulo',j.titulo,'categoria',j.tipo_fuente,
                            'numero_norma',j.numero_norma,'version',j.version,'fecha_vigencia',j.fecha_vigencia,
                            'fecha_fin_vigencia',j.fecha_fin_vigencia,'hash_contenido_fuente',encode(sha256(convert_to(j.contenido_original,'UTF8')),'hex'));
                    ELSE RAISE EXCEPTION 'Fuente no verificable.' USING ERRCODE='23514'; END IF;
                    resultado := resultado || jsonb_build_array(jsonb_build_object('id_fragmento',f.id_fragmento,
                        'fragmento_utilizado',f.contenido,'metadatos',meta));
                END LOOP;
                RETURN resultado;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_validar_registro_defensa() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE s simulaciones; m movimientos_practicas_regladas; p participantes_simulacion; t jsonb; n integer;
            BEGIN
                PERFORM jurissim_contexto_practica_bloqueado(NEW.id_simulacion,NEW.id_usuario_responsable);
                SELECT * INTO s FROM simulaciones WHERE id_simulacion=NEW.id_simulacion;
                SELECT * INTO m FROM movimientos_practicas_regladas WHERE id_simulacion=s.id_simulacion ORDER BY orden DESC LIMIT 1;
                IF s.estado <> 'activa' OR s.fecha_inicio IS NULL OR s.fecha_fin IS NOT NULL OR m.accion='finalizar'
                    OR NEW.id_movimiento IS DISTINCT FROM m.id_movimiento OR NEW.id_etapa IS DISTINCT FROM s.id_etapa_actual
                    OR m.id_etapa_destino IS DISTINCT FROM s.id_etapa_actual OR m.estado_destino <> 'activa'
                    OR NEW.orden <> (SELECT count(*)+1 FROM intervenciones WHERE id_simulacion=s.id_simulacion) THEN
                    RAISE EXCEPTION 'Turno con contexto obsoleto.' USING ERRCODE='23514';
                END IF;
                -- Historia parcial: etapas anteriores completas y etapa actual aun sin concluir.
                IF EXISTS (
                    WITH recorrido AS (SELECT id_etapa_destino id_etapa,orden paso FROM movimientos_practicas_regladas
                        WHERE id_simulacion=s.id_simulacion AND accion IN ('iniciar','avanzar')),
                    transcripcion AS (SELECT i.*,actor.rol,actor.controlado_por,actor.id_analisis,actor.id_expediente,
                        row_number() OVER (ORDER BY i.orden) posicion,
                        row_number() OVER (PARTITION BY i.id_etapa ORDER BY i.orden) posicion_etapa
                        FROM intervenciones i JOIN participantes_simulacion actor USING (id_participante_simulacion,id_simulacion)
                        WHERE i.id_simulacion=s.id_simulacion),
                    verificadas AS (SELECT i.*,r.paso,lag(r.paso) OVER (ORDER BY i.orden) paso_anterior,
                        s.configuracion#>ARRAY['turnos_por_etapa',i.id_etapa::text,(i.posicion_etapa-1)::text] esperado
                        FROM transcripcion i LEFT JOIN recorrido r USING (id_etapa))
                    SELECT 1 FROM verificadas WHERE paso IS NULL OR paso < paso_anterior OR orden <> posicion
                        OR esperado IS NULL OR rol IS DISTINCT FROM esperado->>'rol'
                        OR controlado_por IS DISTINCT FROM (CASE WHEN rol='abogado_defensor' THEN 'usuario' ELSE 'ia' END)
                        OR id_tipo_audiencia <> s.id_tipo_audiencia OR id_analisis <> s.id_analisis OR id_expediente <> s.id_expediente
                        OR jurissim_normalizar_cita(contenido)=''
                ) OR EXISTS (SELECT 1 FROM movimientos_practicas_regladas r WHERE r.id_simulacion=s.id_simulacion
                    AND r.accion IN ('iniciar','avanzar') AND r.id_etapa_destino <> s.id_etapa_actual
                    AND jsonb_array_length(s.configuracion#>ARRAY['turnos_por_etapa',r.id_etapa_destino::text]) <>
                        (SELECT count(*) FROM intervenciones i WHERE i.id_simulacion=s.id_simulacion AND i.id_etapa=r.id_etapa_destino)) THEN
                    RAISE EXCEPTION 'Historia incompatible.' USING ERRCODE='23514';
                END IF;
                SELECT count(*) INTO n FROM intervenciones WHERE id_simulacion=s.id_simulacion AND id_etapa=s.id_etapa_actual;
                t := s.configuracion#>ARRAY['turnos_por_etapa',s.id_etapa_actual::text,n::text];
                IF t IS NULL OR t->>'rol' <> 'abogado_defensor' OR NEW.id_propuesta_turno IS DISTINCT FROM (t->>'id_propuesta_turno')::bigint THEN
                    RAISE EXCEPTION 'No corresponde un turno de defensa.' USING ERRCODE='23514';
                END IF;
                PERFORM 1 FROM participantes_simulacion WHERE id_simulacion=s.id_simulacion ORDER BY id_participante_simulacion FOR UPDATE;
                IF (SELECT count(*) FROM participantes_simulacion WHERE id_simulacion=s.id_simulacion AND rol='abogado_defensor') <> 1 THEN
                    RAISE EXCEPTION 'Defensa ambigua.' USING ERRCODE='23514';
                END IF;
                SELECT * INTO p FROM participantes_simulacion WHERE id_simulacion=s.id_simulacion AND rol='abogado_defensor';
                IF p.controlado_por <> 'usuario' OR p.estado <> 'activo' OR p.id_analisis IS DISTINCT FROM s.id_analisis
                    OR p.id_expediente IS DISTINCT FROM s.id_expediente THEN
                    RAISE EXCEPTION 'Defensa no disponible.' USING ERRCODE='23514';
                END IF;
                NEW.id_vinculacion := (SELECT id_vinculacion FROM vinculaciones_reglas_practica WHERE id_simulacion=s.id_simulacion);
                NEW.id_participante_simulacion := p.id_participante_simulacion;
                NEW.fuentes := jurissim_fuentes_defensa(NEW.ids_fragmento,s.id_expediente);
                NEW.id_intervencion := nextval(pg_get_serial_sequence('intervenciones','id_intervencion'));
                NEW.fecha_creacion := clock_timestamp();
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER validar_registro_defensa BEFORE INSERT ON registros_intervenciones_regladas
                FOR EACH ROW EXECUTE FUNCTION jurissim_validar_registro_defensa();
            CREATE TRIGGER registro_defensa_inmutable BEFORE UPDATE OR DELETE ON registros_intervenciones_regladas
                FOR EACH ROW EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica();
            CREATE TRIGGER registro_defensa_no_truncar BEFORE TRUNCATE ON registros_intervenciones_regladas
                FOR EACH STATEMENT EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica();
            CREATE OR REPLACE FUNCTION jurissim_impedir_intervencion_vinculada() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE r registros_intervenciones_regladas; s simulaciones;
            BEGIN
                IF TG_OP='UPDATE' AND EXISTS (SELECT 1 FROM vinculaciones_reglas_practica WHERE id_simulacion=OLD.id_simulacion) THEN
                    RAISE EXCEPTION 'Intervencion vinculada inmutable.' USING ERRCODE='23514';
                END IF;
                IF EXISTS (SELECT 1 FROM vinculaciones_reglas_practica WHERE id_simulacion=NEW.id_simulacion) THEN
                    SELECT * INTO r FROM registros_intervenciones_regladas WHERE id_intervencion=NEW.id_intervencion;
                    PERFORM jurissim_contexto_practica_bloqueado(NEW.id_simulacion,r.id_usuario_responsable);
                    SELECT * INTO s FROM simulaciones WHERE id_simulacion=NEW.id_simulacion;
                    IF TG_OP <> 'INSERT' OR r.id_registro IS NULL OR s.estado <> 'activa'
                        OR ROW(NEW.id_simulacion,NEW.id_participante_simulacion,NEW.id_etapa,NEW.orden,NEW.contenido,NEW.fecha_intervencion,NEW.fecha_creacion)
                            IS DISTINCT FROM ROW(r.id_simulacion,r.id_participante_simulacion,r.id_etapa,r.orden,r.contenido,r.fecha_creacion,r.fecha_creacion)
                        OR NEW.id_tipo_audiencia <> s.id_tipo_audiencia OR NEW.tipo_entrada <> 'texto' OR NEW.modelo_ia IS NOT NULL
                        OR NEW.fecha_actualizacion IS NOT NULL OR NEW.metadatos IS DISTINCT FROM jsonb_build_object(
                            'id_registro_reglado',r.id_registro,'id_usuario_responsable',r.id_usuario_responsable,'id_propuesta_turno',r.id_propuesta_turno) THEN
                        RAISE EXCEPTION 'Intervencion sin registro validado.' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_aplicar_registro_defensa() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE tipo bigint; f jsonb;
            BEGIN
                SELECT id_tipo_audiencia INTO tipo FROM simulaciones WHERE id_simulacion=NEW.id_simulacion;
                INSERT INTO intervenciones(id_intervencion,id_simulacion,id_participante_simulacion,id_tipo_audiencia,id_etapa,orden,
                    contenido,tipo_entrada,metadatos,fecha_intervencion,fecha_creacion)
                VALUES (NEW.id_intervencion,NEW.id_simulacion,NEW.id_participante_simulacion,tipo,NEW.id_etapa,NEW.orden,NEW.contenido,'texto',
                    jsonb_build_object('id_registro_reglado',NEW.id_registro,'id_usuario_responsable',NEW.id_usuario_responsable,
                        'id_propuesta_turno',NEW.id_propuesta_turno),NEW.fecha_creacion,NEW.fecha_creacion);
                FOR f IN SELECT e FROM jsonb_array_elements(NEW.fuentes) e LOOP
                    INSERT INTO fuentes_intervencion(id_intervencion,id_fragmento,fragmento_utilizado,metadatos,fecha_creacion)
                    VALUES (NEW.id_intervencion,(f->>'id_fragmento')::bigint,f->>'fragmento_utilizado',f->'metadatos',NEW.fecha_creacion);
                END LOOP;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER aplicar_registro_defensa AFTER INSERT ON registros_intervenciones_regladas
                FOR EACH ROW EXECUTE FUNCTION jurissim_aplicar_registro_defensa();
            CREATE OR REPLACE FUNCTION jurissim_proteger_evidencia_defensa() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE r registros_intervenciones_regladas; esperado jsonb;
            BEGIN
                IF TG_OP='TRUNCATE' THEN
                    IF EXISTS (SELECT 1 FROM registros_intervenciones_regladas) THEN
                        RAISE EXCEPTION 'Transcripcion auditada no truncable.' USING ERRCODE='23514';
                    END IF;
                    RETURN NULL;
                END IF;
                IF TG_TABLE_NAME='participantes_simulacion' THEN
                    IF EXISTS (SELECT 1 FROM registros_intervenciones_regladas WHERE id_participante_simulacion=OLD.id_participante_simulacion)
                        AND (TG_OP='DELETE' OR ROW(NEW.id_participante_simulacion,NEW.id_simulacion,NEW.id_analisis,NEW.id_expediente,NEW.rol,NEW.controlado_por)
                            IS DISTINCT FROM ROW(OLD.id_participante_simulacion,OLD.id_simulacion,OLD.id_analisis,OLD.id_expediente,OLD.rol,OLD.controlado_por)) THEN
                        RAISE EXCEPTION 'Identidad auditada inmutable.' USING ERRCODE='23514';
                    END IF;
                ELSE
                    IF TG_OP IN ('UPDATE','DELETE') AND EXISTS (SELECT 1 FROM registros_intervenciones_regladas WHERE id_intervencion=OLD.id_intervencion) THEN
                        RAISE EXCEPTION 'Evidencia auditada inmutable.' USING ERRCODE='23514';
                    END IF;
                    IF TG_TABLE_NAME='fuentes_intervencion' AND TG_OP IN ('INSERT','UPDATE') THEN
                        SELECT * INTO r FROM registros_intervenciones_regladas WHERE id_intervencion=NEW.id_intervencion;
                        IF r.id_registro IS NOT NULL THEN
                            SELECT e INTO esperado FROM jsonb_array_elements(r.fuentes) e WHERE (e->>'id_fragmento')::bigint=NEW.id_fragmento;
                            IF esperado IS NULL OR NEW.fragmento_utilizado IS DISTINCT FROM esperado->>'fragmento_utilizado'
                                OR NEW.metadatos IS DISTINCT FROM esperado->'metadatos' OR NEW.fecha_creacion IS DISTINCT FROM r.fecha_creacion THEN
                                RAISE EXCEPTION 'Fuente ajena al registro.' USING ERRCODE='23514';
                            END IF;
                        ELSIF EXISTS (SELECT 1 FROM intervenciones i JOIN vinculaciones_reglas_practica l USING(id_simulacion) WHERE i.id_intervencion=NEW.id_intervencion) THEN
                            RAISE EXCEPTION 'Fuente sin registro.' USING ERRCODE='23514';
                        END IF;
                    END IF;
                END IF;
                IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER proteger_evidencia_defensa BEFORE DELETE ON intervenciones
                FOR EACH ROW EXECUTE FUNCTION jurissim_proteger_evidencia_defensa();
            CREATE TRIGGER proteger_fuentes_defensa BEFORE INSERT OR UPDATE OR DELETE ON fuentes_intervencion
                FOR EACH ROW EXECUTE FUNCTION jurissim_proteger_evidencia_defensa();
            CREATE TRIGGER proteger_identidad_defensa BEFORE UPDATE OR DELETE ON participantes_simulacion
                FOR EACH ROW EXECUTE FUNCTION jurissim_proteger_evidencia_defensa();
            CREATE TRIGGER transcripcion_defensa_no_truncar BEFORE TRUNCATE ON intervenciones
                FOR EACH STATEMENT EXECUTE FUNCTION jurissim_proteger_evidencia_defensa();
            CREATE TRIGGER fuentes_defensa_no_truncar BEFORE TRUNCATE ON fuentes_intervencion
                FOR EACH STATEMENT EXECUTE FUNCTION jurissim_proteger_evidencia_defensa();
            SQL);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE registros_intervenciones_regladas, intervenciones, fuentes_intervencion, participantes_simulacion IN ACCESS EXCLUSIVE MODE');
            if (DB::table('registros_intervenciones_regladas')->exists()) {
                throw new RuntimeException('No se puede revertir: hay intervenciones auditadas que deben preservarse.');
            }
            DB::unprepared(<<<'SQL'
                DROP TRIGGER proteger_evidencia_defensa ON intervenciones;
                DROP TRIGGER proteger_fuentes_defensa ON fuentes_intervencion;
                DROP TRIGGER proteger_identidad_defensa ON participantes_simulacion;
                DROP TRIGGER transcripcion_defensa_no_truncar ON intervenciones;
                DROP TRIGGER fuentes_defensa_no_truncar ON fuentes_intervencion;
                CREATE OR REPLACE FUNCTION jurissim_impedir_intervencion_vinculada() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM vinculaciones_reglas_practica WHERE id_simulacion=NEW.id_simulacion)
                        OR (TG_OP='UPDATE' AND EXISTS (SELECT 1 FROM vinculaciones_reglas_practica WHERE id_simulacion=OLD.id_simulacion)) THEN
                        RAISE EXCEPTION 'Intervenciones vinculadas pendientes de integrar.' USING ERRCODE='23514';
                    END IF;
                    RETURN NEW;
                END;
                $$;
                SQL);
            Schema::drop('registros_intervenciones_regladas');
            DB::unprepared('DROP FUNCTION jurissim_proteger_evidencia_defensa(); DROP FUNCTION jurissim_aplicar_registro_defensa(); DROP FUNCTION jurissim_validar_registro_defensa(); DROP FUNCTION jurissim_fuentes_defensa(jsonb,bigint); DROP FUNCTION jurissim_normalizar_cita(text);');
        });
    }
};
