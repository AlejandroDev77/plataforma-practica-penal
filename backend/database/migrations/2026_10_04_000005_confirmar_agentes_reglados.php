<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borradores_agentes_reglados', function (Blueprint $table): void {
            $table->id('id_borrador');
            $table->foreignId('id_simulacion')->constrained('simulaciones', 'id_simulacion')->restrictOnDelete();
            $table->foreignId('id_usuario_responsable')->constrained('users')->restrictOnDelete();
            $table->foreignId('id_movimiento')->constrained('movimientos_practicas_regladas', 'id_movimiento')->restrictOnDelete();
            $table->unsignedBigInteger('id_etapa');
            $table->unsignedBigInteger('id_propuesta_turno');
            $table->integer('orden');
            $table->jsonb('ids_fragmento');
            $table->jsonb('preparacion');
            $table->jsonb('respuesta');
            $table->string('proveedor', 20);
            $table->string('modelo_ia', 150);
            $table->boolean('autorizar_externo')->default(false);
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_vencimiento');
            $table->index(['id_simulacion', 'orden']);
        });
        Schema::table('registros_intervenciones_regladas', function (Blueprint $table): void {
            $table->foreignId('id_borrador')->nullable()->unique()->constrained('borradores_agentes_reglados', 'id_borrador')->restrictOnDelete();
            $table->boolean('confirmacion_humana')->default(false);
        });
        DB::statement("ALTER TABLE borradores_agentes_reglados ADD CHECK (orden>0 AND id_etapa>0 AND id_propuesta_turno>0 AND proveedor IN ('ollama','openai') AND btrim(modelo_ia)<>'' AND (proveedor<>'openai' OR autorizar_externo) AND jsonb_typeof(preparacion)='object' AND octet_length(preparacion::text)<=200000 AND jsonb_typeof(respuesta)='object' AND octet_length(respuesta::text)<=20000 AND fecha_vencimiento>fecha_creacion)");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION jurissim_preparar_agente_reglado(identificador bigint, responsable bigint,
                movimiento bigint, etapa bigint, propuesta bigint, orden_esperado integer, seleccion jsonb) RETURNS jsonb LANGUAGE plpgsql AS $$
            DECLARE s simulaciones; m movimientos_practicas_regladas; p participantes_simulacion; c jsonb; t jsonb;
                n integer; fuentes jsonb; fuente jsonb; contexto_fuentes jsonb := '[]'::jsonb;
                titulo text; localizador text; nombre_etapa text; copia jsonb; revision bigint;
            BEGIN
                c := jurissim_contexto_practica_bloqueado(identificador,responsable);
                SELECT * INTO s FROM simulaciones WHERE id_simulacion=identificador;
                SELECT * INTO m FROM movimientos_practicas_regladas WHERE id_simulacion=identificador ORDER BY orden DESC LIMIT 1;
                IF s.estado<>'activa' OR s.fecha_inicio IS NULL OR s.fecha_fin IS NOT NULL
                    OR m.id_movimiento IS DISTINCT FROM movimiento OR s.id_etapa_actual IS DISTINCT FROM etapa
                    OR m.estado_destino<>'activa' OR m.id_etapa_destino IS DISTINCT FROM etapa
                    OR orden_esperado <> (SELECT count(*)+1 FROM intervenciones WHERE id_simulacion=identificador) THEN
                    RAISE EXCEPTION 'Turno de agente obsoleto.' USING ERRCODE='23514';
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

                SELECT count(*) INTO n FROM intervenciones WHERE id_simulacion=identificador AND id_etapa=etapa;
                t := s.configuracion#>ARRAY['turnos_por_etapa',etapa::text,n::text];
                IF t IS NULL OR t->>'rol' NOT IN ('juez','fiscal') OR (t->>'id_propuesta_turno')::bigint IS DISTINCT FROM propuesta
                    OR jurissim_normalizar_cita(t->>'instruccion')='' OR char_length(t->>'instruccion')>1500 THEN
                    RAISE EXCEPTION 'Turno de agente incompatible.' USING ERRCODE='23514';
                END IF;
                PERFORM 1 FROM participantes_simulacion WHERE id_simulacion=identificador ORDER BY id_participante_simulacion FOR UPDATE;
                IF (SELECT count(*) FROM participantes_simulacion WHERE id_simulacion=identificador AND rol=t->>'rol')<>1 THEN
                    RAISE EXCEPTION 'Agente ambiguo.' USING ERRCODE='23514';
                END IF;
                SELECT * INTO p FROM participantes_simulacion WHERE id_simulacion=identificador AND rol=t->>'rol';
                IF p.controlado_por<>'ia' OR p.estado<>'activo' OR p.id_analisis IS DISTINCT FROM s.id_analisis OR p.id_expediente IS DISTINCT FROM s.id_expediente THEN
                    RAISE EXCEPTION 'Agente no disponible.' USING ERRCODE='23514';
                END IF;
                fuentes := jurissim_fuentes_defensa(seleccion,s.id_expediente);
                IF jsonb_array_length(fuentes)>8 OR octet_length(fuentes::text)>100000 THEN
                    RAISE EXCEPTION 'Seleccion excesiva.' USING ERRCODE='23514';
                END IF;
                FOR fuente IN SELECT e FROM jsonb_array_elements(fuentes) e LOOP
                    titulo := left(COALESCE(NULLIF(btrim(fuente#>>'{metadatos,titulo}'),''),'Fuente seleccionada'),300);
                    localizador := left(COALESCE(NULLIF(btrim(fuente#>>'{metadatos,localizador}'),''),NULLIF(btrim(fuente#>>'{metadatos,numero_norma}'),''),'Referencia seleccionada'),200);
                    contexto_fuentes := contexto_fuentes || jsonb_build_array(jsonb_build_object('id',(fuente->>'id_fragmento')::bigint,
                        'kind',fuente#>>'{metadatos,tipo_fuente}','title',titulo,'locator',localizador,'excerpt',left(fuente->>'fragmento_utilizado',1500)));
                END LOOP;
                SELECT left(e->>'nombre',100) INTO nombre_etapa FROM jsonb_array_elements(c->'etapas') e WHERE (e->>'id_etapa')::bigint=etapa;
                SELECT id_revision INTO revision FROM revisiones_analisis WHERE id_analisis=s.id_analisis ORDER BY fecha_creacion DESC,id_revision DESC LIMIT 1;
                copia := jsonb_build_object(
                    'contexto',jsonb_build_object('simulation_id',identificador::text,'actor_id',p.id_participante_simulacion::text,
                        'actor_role',p.rol,'phase',nombre_etapa,'turn_instruction',t->>'instruccion',
                        'visible_facts','[]'::jsonb,'sources',contexto_fuentes,'transcript','[]'::jsonb),
                    'fuentes',fuentes,'participante',jsonb_build_array(p.id_participante_simulacion,p.id_simulacion,p.id_analisis,p.id_expediente,p.rol,p.controlado_por,p.estado,p.nombre_mostrado),
                    'id_vinculacion',(SELECT id_vinculacion FROM vinculaciones_reglas_practica WHERE id_simulacion=identificador),
                    'id_movimiento',movimiento,'id_etapa',etapa,'id_propuesta_turno',propuesta,'orden',orden_esperado,'id_revision_analisis',revision,
                    'huella_analisis',(SELECT encode(sha256(convert_to(COALESCE(datos_estructurados,'{}'::jsonb)::text,'UTF8')),'hex') FROM analisis_expediente WHERE id_analisis=s.id_analisis),
                    'huella_historial',(SELECT encode(sha256(convert_to(COALESCE(jsonb_agg(jsonb_build_array(i.id_intervencion,i.orden,i.id_etapa,i.contenido,a.rol,a.controlado_por) ORDER BY i.orden),'[]'::jsonb)::text,'UTF8')),'hex')
                        FROM intervenciones i JOIN participantes_simulacion a USING(id_simulacion,id_participante_simulacion) WHERE i.id_simulacion=identificador));
                RETURN copia;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_validar_borrador_agente() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE actual jsonb; ids jsonb; permitido jsonb;
            BEGIN
                actual := jurissim_preparar_agente_reglado(NEW.id_simulacion,NEW.id_usuario_responsable,NEW.id_movimiento,
                    NEW.id_etapa,NEW.id_propuesta_turno,NEW.orden,NEW.ids_fragmento);
                IF NEW.preparacion IS DISTINCT FROM actual OR jsonb_typeof(NEW.respuesta)<>'object'
                    OR NEW.respuesta - ARRAY['speaker_role','content','used_source_ids','requires_human_review'] <> '{}'::jsonb
                    OR NEW.respuesta->>'speaker_role' IS DISTINCT FROM actual#>>'{contexto,actor_role}'
                    OR NEW.respuesta->'requires_human_review' IS DISTINCT FROM 'true'::jsonb
                    OR jsonb_typeof(NEW.respuesta->'content') IS DISTINCT FROM 'string'
                    OR jurissim_normalizar_cita(NEW.respuesta->>'content')='' OR char_length(NEW.respuesta->>'content')>2000
                    OR jsonb_typeof(NEW.respuesta->'used_source_ids') IS DISTINCT FROM 'array' THEN
                    RAISE EXCEPTION 'Respuesta de agente incompatible.' USING ERRCODE='23514';
                END IF;
                ids := NEW.respuesta->'used_source_ids';
                SELECT COALESCE(jsonb_agg(e->'id'),'[]'::jsonb) INTO permitido FROM jsonb_array_elements(actual#>'{contexto,sources}') e;
                IF jsonb_array_length(ids)>8 OR EXISTS (SELECT 1 FROM jsonb_array_elements(ids) e WHERE jsonb_typeof(e)<>'number' OR e::text !~ '^[1-9][0-9]{0,17}$' OR NOT permitido @> jsonb_build_array(e))
                    OR (SELECT count(DISTINCT e) FROM jsonb_array_elements(ids) e)<>jsonb_array_length(ids) THEN
                    RAISE EXCEPTION 'Citas fuera de seleccion.' USING ERRCODE='23514';
                END IF;
                NEW.fecha_creacion := clock_timestamp();
                NEW.fecha_vencimiento := NEW.fecha_creacion + interval '10 minutes';
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER validar_borrador_agente BEFORE INSERT ON borradores_agentes_reglados FOR EACH ROW EXECUTE FUNCTION jurissim_validar_borrador_agente();
            CREATE TRIGGER borrador_agente_inmutable BEFORE UPDATE OR DELETE ON borradores_agentes_reglados FOR EACH ROW EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica();
            CREATE TRIGGER borrador_agente_no_truncar BEFORE TRUNCATE ON borradores_agentes_reglados FOR EACH STATEMENT EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica();
            DROP TRIGGER validar_registro_defensa ON registros_intervenciones_regladas;
            CREATE TRIGGER validar_registro_defensa BEFORE INSERT ON registros_intervenciones_regladas
                FOR EACH ROW WHEN (NEW.id_borrador IS NULL) EXECUTE FUNCTION jurissim_validar_registro_defensa();
            DROP TRIGGER aplicar_registro_defensa ON registros_intervenciones_regladas;
            CREATE TRIGGER aplicar_registro_defensa AFTER INSERT ON registros_intervenciones_regladas
                FOR EACH ROW WHEN (NEW.id_borrador IS NULL) EXECUTE FUNCTION jurissim_aplicar_registro_defensa();
            CREATE OR REPLACE FUNCTION jurissim_validar_confirmacion_agente() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE b borradores_agentes_reglados; actual jsonb;
            BEGIN
                SELECT * INTO b FROM borradores_agentes_reglados WHERE id_borrador=NEW.id_borrador;
                IF b.id_borrador IS NULL OR NOT NEW.confirmacion_humana
                    OR ROW(NEW.id_simulacion,NEW.id_usuario_responsable,NEW.id_movimiento,NEW.id_etapa,NEW.id_propuesta_turno,NEW.orden)
                        IS DISTINCT FROM ROW(b.id_simulacion,b.id_usuario_responsable,b.id_movimiento,b.id_etapa,b.id_propuesta_turno,b.orden)
                    OR b.fecha_vencimiento<=clock_timestamp() THEN
                    RAISE EXCEPTION 'Borrador no confirmable.' USING ERRCODE='23514';
                END IF;
                actual := jurissim_preparar_agente_reglado(b.id_simulacion,b.id_usuario_responsable,b.id_movimiento,b.id_etapa,b.id_propuesta_turno,b.orden,b.ids_fragmento);
                IF actual IS DISTINCT FROM b.preparacion OR b.fecha_vencimiento<=clock_timestamp() THEN
                    RAISE EXCEPTION 'Contexto de borrador cambio.' USING ERRCODE='23514';
                END IF;
                NEW.id_vinculacion := (actual->>'id_vinculacion')::bigint;
                NEW.id_participante_simulacion := (actual#>>'{contexto,actor_id}')::bigint;
                NEW.contenido := b.respuesta->>'content';
                NEW.ids_fragmento := b.respuesta->'used_source_ids';
                -- El acta conserva exactamente el extracto enviado, no el final del fragmento que el modelo no vio.
                SELECT COALESCE(jsonb_agg(jsonb_set(e,'{fragmento_utilizado}',to_jsonb(left(e->>'fragmento_utilizado',1500))) ORDER BY posicion),'[]'::jsonb) INTO NEW.fuentes
                    FROM jsonb_array_elements(actual->'fuentes') WITH ORDINALITY AS f(e,posicion)
                    WHERE NEW.ids_fragmento @> jsonb_build_array(e->'id_fragmento');
                NEW.id_intervencion := nextval(pg_get_serial_sequence('intervenciones','id_intervencion'));
                NEW.fecha_creacion := clock_timestamp();
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER validar_confirmacion_agente BEFORE INSERT ON registros_intervenciones_regladas
                FOR EACH ROW WHEN (NEW.id_borrador IS NOT NULL) EXECUTE FUNCTION jurissim_validar_confirmacion_agente();
            CREATE OR REPLACE FUNCTION jurissim_impedir_intervencion_vinculada() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE r registros_intervenciones_regladas; s simulaciones; b borradores_agentes_reglados; meta jsonb;
            BEGIN
                IF TG_OP='UPDATE' AND EXISTS (SELECT 1 FROM vinculaciones_reglas_practica WHERE id_simulacion=OLD.id_simulacion) THEN
                    RAISE EXCEPTION 'Intervencion vinculada inmutable.' USING ERRCODE='23514';
                END IF;
                IF EXISTS (SELECT 1 FROM vinculaciones_reglas_practica WHERE id_simulacion=NEW.id_simulacion) THEN
                    SELECT * INTO r FROM registros_intervenciones_regladas WHERE id_intervencion=NEW.id_intervencion;
                    PERFORM jurissim_contexto_practica_bloqueado(NEW.id_simulacion,r.id_usuario_responsable);
                    SELECT * INTO s FROM simulaciones WHERE id_simulacion=NEW.id_simulacion;
                    meta := jsonb_build_object('id_registro_reglado',r.id_registro,'id_usuario_responsable',r.id_usuario_responsable,'id_propuesta_turno',r.id_propuesta_turno);
                    IF r.id_borrador IS NOT NULL THEN
                        SELECT * INTO b FROM borradores_agentes_reglados WHERE id_borrador=r.id_borrador;
                        meta := meta || jsonb_build_object('id_borrador',b.id_borrador,'proveedor',b.proveedor,'confirmacion_humana',true);
                    END IF;
                    IF TG_OP<>'INSERT' OR r.id_registro IS NULL OR s.estado<>'activa'
                        OR ROW(NEW.id_simulacion,NEW.id_participante_simulacion,NEW.id_etapa,NEW.orden,NEW.contenido,NEW.fecha_intervencion,NEW.fecha_creacion)
                            IS DISTINCT FROM ROW(r.id_simulacion,r.id_participante_simulacion,r.id_etapa,r.orden,r.contenido,r.fecha_creacion,r.fecha_creacion)
                        OR NEW.id_tipo_audiencia<>s.id_tipo_audiencia OR NEW.tipo_entrada<>'texto'
                        OR NEW.modelo_ia IS DISTINCT FROM b.modelo_ia OR NEW.fecha_actualizacion IS NOT NULL OR NEW.metadatos IS DISTINCT FROM meta
                        OR (r.id_borrador IS NOT NULL AND (NOT r.confirmacion_humana OR b.fecha_vencimiento<=clock_timestamp())) THEN
                        RAISE EXCEPTION 'Intervencion sin registro validado.' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_aplicar_confirmacion_agente() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE b borradores_agentes_reglados; tipo bigint; f jsonb;
            BEGIN
                SELECT * INTO b FROM borradores_agentes_reglados WHERE id_borrador=NEW.id_borrador;
                SELECT id_tipo_audiencia INTO tipo FROM simulaciones WHERE id_simulacion=NEW.id_simulacion;
                INSERT INTO intervenciones(id_intervencion,id_simulacion,id_participante_simulacion,id_tipo_audiencia,id_etapa,orden,
                    contenido,tipo_entrada,modelo_ia,metadatos,fecha_intervencion,fecha_creacion)
                VALUES (NEW.id_intervencion,NEW.id_simulacion,NEW.id_participante_simulacion,tipo,NEW.id_etapa,NEW.orden,NEW.contenido,'texto',b.modelo_ia,
                    jsonb_build_object('id_registro_reglado',NEW.id_registro,'id_usuario_responsable',NEW.id_usuario_responsable,
                        'id_propuesta_turno',NEW.id_propuesta_turno,'id_borrador',b.id_borrador,'proveedor',b.proveedor,'confirmacion_humana',true),NEW.fecha_creacion,NEW.fecha_creacion);
                FOR f IN SELECT e FROM jsonb_array_elements(NEW.fuentes) e LOOP
                    INSERT INTO fuentes_intervencion(id_intervencion,id_fragmento,fragmento_utilizado,metadatos,fecha_creacion)
                    VALUES (NEW.id_intervencion,(f->>'id_fragmento')::bigint,f->>'fragmento_utilizado',f->'metadatos',NEW.fecha_creacion);
                END LOOP;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER aplicar_confirmacion_agente AFTER INSERT ON registros_intervenciones_regladas
                FOR EACH ROW WHEN (NEW.id_borrador IS NOT NULL) EXECUTE FUNCTION jurissim_aplicar_confirmacion_agente();
            SQL);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE borradores_agentes_reglados, registros_intervenciones_regladas, intervenciones IN ACCESS EXCLUSIVE MODE');
            if (DB::table('borradores_agentes_reglados')->exists() || DB::table('registros_intervenciones_regladas')->whereNotNull('id_borrador')->exists()) {
                throw new RuntimeException('No se puede revertir: hay borradores o confirmaciones que deben preservarse.');
            }
            DB::unprepared(<<<'SQL'
                DROP TRIGGER validar_confirmacion_agente ON registros_intervenciones_regladas;
                DROP TRIGGER aplicar_confirmacion_agente ON registros_intervenciones_regladas;
                DROP TRIGGER validar_registro_defensa ON registros_intervenciones_regladas;
                DROP TRIGGER aplicar_registro_defensa ON registros_intervenciones_regladas;
                CREATE TRIGGER validar_registro_defensa BEFORE INSERT ON registros_intervenciones_regladas FOR EACH ROW EXECUTE FUNCTION jurissim_validar_registro_defensa();
                CREATE TRIGGER aplicar_registro_defensa AFTER INSERT ON registros_intervenciones_regladas FOR EACH ROW EXECUTE FUNCTION jurissim_aplicar_registro_defensa();
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

                SQL);
            Schema::table('registros_intervenciones_regladas', function (Blueprint $table): void {
                $table->dropForeign(['id_borrador']);
                $table->dropUnique(['id_borrador']);
                $table->dropColumn(['id_borrador', 'confirmacion_humana']);
            });
            Schema::drop('borradores_agentes_reglados');
            DB::unprepared('DROP FUNCTION jurissim_aplicar_confirmacion_agente(); DROP FUNCTION jurissim_validar_confirmacion_agente(); DROP FUNCTION jurissim_validar_borrador_agente(); DROP FUNCTION jurissim_preparar_agente_reglado(bigint,bigint,bigint,bigint,bigint,integer,jsonb);');
        });
    }
};
