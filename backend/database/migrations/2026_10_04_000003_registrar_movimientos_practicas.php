<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_practicas_regladas', function (Blueprint $table): void {
            $table->id('id_movimiento');
            $table->foreignId('id_simulacion')->constrained('simulaciones', 'id_simulacion')->restrictOnDelete();
            $table->foreignId('id_vinculacion')->constrained('vinculaciones_reglas_practica', 'id_vinculacion')->restrictOnDelete();
            $table->foreignId('id_usuario_responsable')->constrained('users')->restrictOnDelete();
            $table->integer('orden');
            $table->string('accion', 20);
            $table->string('estado_origen', 40);
            $table->string('estado_destino', 40);
            $table->unsignedBigInteger('id_etapa_origen')->nullable();
            $table->unsignedBigInteger('id_etapa_destino');
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->unique(['id_simulacion', 'orden']);
        });
        DB::statement("ALTER TABLE movimientos_practicas_regladas ADD CHECK (id_movimiento > 0 AND orden > 0 AND accion IN ('iniciar','avanzar','finalizar') AND estado_origen IN ('preparando','activa') AND estado_destino IN ('activa','finalizada') AND id_etapa_destino > 0 AND (id_etapa_origen IS NULL OR id_etapa_origen > 0))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION jurissim_contexto_practica_bloqueado(identificador bigint, responsable bigint)
                RETURNS jsonb LANGUAGE plpgsql AS $$
            DECLARE s simulaciones; l vinculaciones_reglas_practica; a activaciones_reglas_audiencia;
                v versiones_reglas_audiencia; decision_actual text;
            BEGIN
                IF current_setting('transaction_isolation') <> 'read committed' THEN
                    RAISE EXCEPTION 'Movimiento exige read committed.' USING ERRCODE='23514';
                END IF;
                SELECT * INTO s FROM simulaciones WHERE id_simulacion=identificador AND id_usuario=responsable;
                SELECT * INTO l FROM vinculaciones_reglas_practica WHERE id_simulacion=identificador;
                IF s.id_simulacion IS NULL OR l.id_vinculacion IS NULL OR l.id_usuario_responsable IS DISTINCT FROM responsable THEN
                    RAISE EXCEPTION 'Practica vinculada no disponible.' USING ERRCODE='23514';
                END IF;
                -- Orden global compatible con creacion y revision del analisis.
                PERFORM 1 FROM expedientes WHERE id_expediente=s.id_expediente AND id_usuario=responsable FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'Propietario incompatible.' USING ERRCODE='23514'; END IF;
                PERFORM 1 FROM analisis_expediente WHERE id_analisis=s.id_analisis FOR UPDATE;
                PERFORM 1 FROM tipos_audiencia WHERE id_tipo_audiencia=s.id_tipo_audiencia FOR NO KEY UPDATE;
                SELECT * INTO a FROM activaciones_reglas_audiencia WHERE id_activacion=l.id_activacion;
                PERFORM 1 FROM habilitaciones_revision_juridica WHERE id_habilitacion=a.id_habilitacion FOR UPDATE;
                SELECT * INTO v FROM versiones_reglas_audiencia WHERE id_version_reglas=l.id_version_reglas FOR UPDATE;
                SELECT * INTO s FROM simulaciones WHERE id_simulacion=identificador FOR UPDATE;
                SELECT decision INTO decision_actual FROM revisiones_analisis WHERE id_analisis=s.id_analisis
                    ORDER BY fecha_creacion DESC,id_revision DESC LIMIT 1;
                IF s.id_usuario IS DISTINCT FROM responsable OR s.id_tipo_audiencia IS DISTINCT FROM l.id_tipo_audiencia
                    OR s.rol_usuario <> 'abogado_defensor' OR a.id_version_reglas IS DISTINCT FROM l.id_version_reglas
                    OR l.huella_version IS DISTINCT FROM v.huella_contenido OR l.perfil_validacion IS DISTINCT FROM a.perfil_validacion
                    OR decision_actual IS DISTINCT FROM 'aprobado'
                    OR NOT EXISTS (SELECT 1 FROM analisis_expediente WHERE id_analisis=s.id_analisis AND id_expediente=s.id_expediente AND estado='procesado')
                    OR jurissim_estado_activacion_practica(l.id_activacion) <> 'vigente' THEN
                    RAISE EXCEPTION 'Dependencias de la practica no vigentes.' USING ERRCODE='23514';
                END IF;
                IF s.configuracion IS DISTINCT FROM jurissim_configuracion_practica(v.contenido) THEN
                    RAISE EXCEPTION 'Configuracion incompatible con la version.' USING ERRCODE='23514';
                END IF;
                RETURN v.contenido;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_validar_movimiento_practica() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE c jsonb; s simulaciones; l vinculaciones_reglas_practica; anterior movimientos_practicas_regladas;
                etapa jsonb; turnos jsonb; n integer;
            BEGIN
                c := jurissim_contexto_practica_bloqueado(NEW.id_simulacion,NEW.id_usuario_responsable);
                SELECT * INTO s FROM simulaciones WHERE id_simulacion=NEW.id_simulacion;
                SELECT * INTO l FROM vinculaciones_reglas_practica WHERE id_simulacion=NEW.id_simulacion;
                SELECT * INTO anterior FROM movimientos_practicas_regladas WHERE id_simulacion=NEW.id_simulacion ORDER BY orden DESC LIMIT 1;
                IF NEW.id_vinculacion IS DISTINCT FROM l.id_vinculacion OR NEW.orden <> COALESCE(anterior.orden,0)+1
                    OR NEW.orden <> (SELECT count(*)+1 FROM movimientos_practicas_regladas WHERE id_simulacion=s.id_simulacion)
                    OR NEW.estado_origen IS DISTINCT FROM s.estado OR NEW.id_etapa_origen IS DISTINCT FROM s.id_etapa_actual
                    OR (anterior.id_movimiento IS NOT NULL AND (NEW.id_movimiento <= anterior.id_movimiento
                        OR anterior.estado_destino IS DISTINCT FROM s.estado OR anterior.id_etapa_destino IS DISTINCT FROM s.id_etapa_actual)) THEN
                    RAISE EXCEPTION 'Movimiento con contexto obsoleto.' USING ERRCODE='23514';
                END IF;
                IF NEW.accion='iniciar' THEN
                    IF anterior.id_movimiento IS NOT NULL OR s.estado <> 'preparando' OR s.id_etapa_actual IS NOT NULL
                        OR s.fecha_inicio IS NOT NULL OR s.fecha_fin IS NOT NULL OR NEW.estado_destino <> 'activa'
                        OR EXISTS (SELECT 1 FROM intervenciones WHERE id_simulacion=s.id_simulacion)
                        OR NOT EXISTS (SELECT 1 FROM jsonb_array_elements(c->'etapas') e
                            WHERE (e->>'id_etapa')::bigint=NEW.id_etapa_destino AND e->>'activo'='true' AND e->>'es_inicial'='true') THEN
                        RAISE EXCEPTION 'Inicio incompatible con la copia.' USING ERRCODE='23514';
                    END IF;
                ELSIF NEW.accion IN ('avanzar','finalizar') THEN
                    IF anterior.id_movimiento IS NULL OR s.estado <> 'activa' OR s.id_etapa_actual IS NULL
                        OR s.fecha_inicio IS NULL OR s.fecha_fin IS NOT NULL THEN
                        RAISE EXCEPTION 'Solo se mueve una practica activa con historial.' USING ERRCODE='23514';
                    END IF;
                    SELECT e INTO etapa FROM jsonb_array_elements(c->'etapas') e
                        WHERE (e->>'id_etapa')::bigint=s.id_etapa_actual AND e->>'activo'='true';
                    turnos := s.configuracion#>ARRAY['turnos_por_etapa',s.id_etapa_actual::text];
                    n := jsonb_array_length(turnos);
                    IF etapa IS NULL OR n IS NULL OR n=0
                        OR n <> (SELECT count(*) FROM intervenciones WHERE id_simulacion=s.id_simulacion AND id_etapa=s.id_etapa_actual) THEN
                        RAISE EXCEPTION 'Faltan turnos de la etapa exacta.' USING ERRCODE='23514';
                    END IF;
                    -- Verificar toda la transcripcion, no autorizar por conteo solamente.
                    IF EXISTS (
                        WITH recorrido AS (
                            SELECT id_etapa_destino id_etapa,orden paso FROM movimientos_practicas_regladas
                            WHERE id_simulacion=s.id_simulacion AND accion IN ('iniciar','avanzar')
                        ), transcripcion AS (
                            SELECT i.*,p.rol,p.controlado_por,p.id_analisis,p.id_expediente,
                                row_number() OVER (ORDER BY i.orden) posicion,
                                row_number() OVER (PARTITION BY i.id_etapa ORDER BY i.orden) posicion_etapa
                            FROM intervenciones i JOIN participantes_simulacion p USING (id_participante_simulacion,id_simulacion)
                            WHERE i.id_simulacion=s.id_simulacion
                        ), verificadas AS (
                            SELECT i.*,r.paso,lag(r.paso) OVER (ORDER BY i.orden) paso_anterior,
                                s.configuracion#>ARRAY['turnos_por_etapa',i.id_etapa::text,(i.posicion_etapa-1)::text] esperado
                            FROM transcripcion i LEFT JOIN recorrido r USING (id_etapa)
                        ) SELECT 1 FROM verificadas WHERE paso IS NULL OR paso < paso_anterior
                            OR orden <> posicion OR esperado IS NULL OR rol IS DISTINCT FROM esperado->>'rol'
                            OR controlado_por IS DISTINCT FROM CASE WHEN rol='abogado_defensor' THEN 'usuario' ELSE 'ia' END
                            OR id_tipo_audiencia <> s.id_tipo_audiencia OR id_analisis <> s.id_analisis OR id_expediente <> s.id_expediente
                            OR btrim(contenido)=''
                    ) OR EXISTS (
                        SELECT 1 FROM movimientos_practicas_regladas m WHERE m.id_simulacion=s.id_simulacion
                            AND m.accion IN ('iniciar','avanzar') AND m.id_etapa_destino <> s.id_etapa_actual
                            AND jsonb_array_length(s.configuracion#>ARRAY['turnos_por_etapa',m.id_etapa_destino::text])
                                <> (SELECT count(*) FROM intervenciones i WHERE i.id_simulacion=s.id_simulacion AND i.id_etapa=m.id_etapa_destino)
                    ) THEN RAISE EXCEPTION 'Historial de turnos incompatible.' USING ERRCODE='23514'; END IF;
                    IF NEW.accion='avanzar' THEN
                        IF etapa->>'es_final'='true' OR NEW.estado_destino <> 'activa'
                            OR NOT EXISTS (SELECT 1 FROM jsonb_array_elements(c->'transiciones') t
                                WHERE t->>'activo'='true' AND (t->>'id_etapa_origen')::bigint=s.id_etapa_actual
                                    AND (t->>'id_etapa_destino')::bigint=NEW.id_etapa_destino) THEN
                            RAISE EXCEPTION 'Transicion ajena a la copia.' USING ERRCODE='23514';
                        END IF;
                    ELSE
                        IF etapa->>'es_final' <> 'true' OR NEW.estado_destino <> 'finalizada'
                            OR NEW.id_etapa_destino IS DISTINCT FROM s.id_etapa_actual THEN
                            RAISE EXCEPTION 'Cierre incompatible con la copia.' USING ERRCODE='23514';
                        END IF;
                    END IF;
                ELSE RAISE EXCEPTION 'Accion incompatible.' USING ERRCODE='23514';
                END IF;
                NEW.fecha_creacion := clock_timestamp();
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER validar_movimiento_practica BEFORE INSERT ON movimientos_practicas_regladas
                FOR EACH ROW EXECUTE FUNCTION jurissim_validar_movimiento_practica();
            CREATE TRIGGER movimiento_practica_inmutable BEFORE UPDATE OR DELETE ON movimientos_practicas_regladas
                FOR EACH ROW EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica();
            CREATE TRIGGER movimiento_practica_no_truncar BEFORE TRUNCATE ON movimientos_practicas_regladas
                FOR EACH STATEMENT EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica();
            CREATE OR REPLACE FUNCTION jurissim_proteger_practica_vinculada() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE m movimientos_practicas_regladas;
            BEGIN
                IF EXISTS (SELECT 1 FROM vinculaciones_reglas_practica WHERE id_simulacion=OLD.id_simulacion) THEN
                    IF ROW(NEW.id_simulacion,NEW.id_usuario,NEW.id_expediente,NEW.id_analisis,NEW.id_tipo_audiencia,
                        NEW.rol_usuario,NEW.configuracion,NEW.fecha_creacion)
                        IS DISTINCT FROM ROW(OLD.id_simulacion,OLD.id_usuario,OLD.id_expediente,OLD.id_analisis,OLD.id_tipo_audiencia,
                        OLD.rol_usuario,OLD.configuracion,OLD.fecha_creacion) THEN
                        RAISE EXCEPTION 'El contexto vinculado no se reasigna.' USING ERRCODE='23514';
                    END IF;
                    IF ROW(NEW.estado,NEW.id_etapa_actual,NEW.fecha_inicio,NEW.fecha_fin)
                        IS DISTINCT FROM ROW(OLD.estado,OLD.id_etapa_actual,OLD.fecha_inicio,OLD.fecha_fin) THEN
                        PERFORM jurissim_contexto_practica_bloqueado(OLD.id_simulacion,OLD.id_usuario);
                        SELECT * INTO m FROM movimientos_practicas_regladas WHERE id_simulacion=OLD.id_simulacion ORDER BY orden DESC LIMIT 1;
                        IF m.id_movimiento IS NULL OR m.estado_origen IS DISTINCT FROM OLD.estado
                            OR m.id_etapa_origen IS DISTINCT FROM OLD.id_etapa_actual
                            OR m.estado_destino IS DISTINCT FROM NEW.estado OR m.id_etapa_destino IS DISTINCT FROM NEW.id_etapa_actual
                            OR NEW.fecha_inicio IS DISTINCT FROM (CASE WHEN m.accion='iniciar' THEN m.fecha_creacion ELSE OLD.fecha_inicio END)
                            OR NEW.fecha_fin IS DISTINCT FROM (CASE WHEN m.accion='finalizar' THEN m.fecha_creacion ELSE OLD.fecha_fin END) THEN
                            RAISE EXCEPTION 'Cambio sin movimiento validado.' USING ERRCODE='23514';
                        END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_aplicar_movimiento_practica() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE simulaciones SET estado=NEW.estado_destino,id_etapa_actual=NEW.id_etapa_destino,
                    fecha_inicio=CASE WHEN NEW.accion='iniciar' THEN NEW.fecha_creacion ELSE fecha_inicio END,
                    fecha_fin=CASE WHEN NEW.accion='finalizar' THEN NEW.fecha_creacion ELSE fecha_fin END,
                    fecha_actualizacion=NEW.fecha_creacion WHERE id_simulacion=NEW.id_simulacion;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER aplicar_movimiento_practica AFTER INSERT ON movimientos_practicas_regladas
                FOR EACH ROW EXECUTE FUNCTION jurissim_aplicar_movimiento_practica();
            SQL);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE movimientos_practicas_regladas, simulaciones IN ACCESS EXCLUSIVE MODE');
            if (DB::table('movimientos_practicas_regladas')->exists()) {
                throw new RuntimeException('No se puede revertir: hay movimientos que deben preservarse.');
            }
            // Restablecer el cerrojo de la fase anterior sin tocar su migracion aplicada.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION jurissim_proteger_practica_vinculada() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM vinculaciones_reglas_practica WHERE id_simulacion=OLD.id_simulacion) THEN
                        IF ROW(NEW.id_simulacion,NEW.id_usuario,NEW.id_expediente,NEW.id_analisis,NEW.id_tipo_audiencia,
                            NEW.rol_usuario,NEW.configuracion,NEW.fecha_creacion)
                            IS DISTINCT FROM ROW(OLD.id_simulacion,OLD.id_usuario,OLD.id_expediente,OLD.id_analisis,OLD.id_tipo_audiencia,
                            OLD.rol_usuario,OLD.configuracion,OLD.fecha_creacion) THEN
                            RAISE EXCEPTION 'El contexto vinculado no se reasigna.' USING ERRCODE='23514';
                        END IF;
                        IF ROW(NEW.estado,NEW.id_etapa_actual,NEW.fecha_inicio,NEW.fecha_fin)
                            IS DISTINCT FROM ROW(OLD.estado,OLD.id_etapa_actual,OLD.fecha_inicio,OLD.fecha_fin) THEN
                            RAISE EXCEPTION 'Los comandos de reglas aun no estan integrados.' USING ERRCODE='23514';
                        END IF;
                    END IF;
                    RETURN NEW;
                END;
                $$;
                SQL);
            Schema::drop('movimientos_practicas_regladas');
            DB::statement('DROP FUNCTION jurissim_aplicar_movimiento_practica()');
            DB::statement('DROP FUNCTION jurissim_validar_movimiento_practica()');
            DB::statement('DROP FUNCTION jurissim_contexto_practica_bloqueado(bigint,bigint)');
        });
    }
};
