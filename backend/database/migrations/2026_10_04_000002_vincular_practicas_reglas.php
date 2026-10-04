<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vinculaciones_reglas_practica', function (Blueprint $table): void {
            $table->id('id_vinculacion');
            $table->foreignId('id_simulacion')->unique()->constrained('simulaciones', 'id_simulacion')->restrictOnDelete();
            $table->foreignId('id_activacion')->constrained('activaciones_reglas_audiencia', 'id_activacion')->restrictOnDelete();
            $table->foreignId('id_version_reglas')->constrained('versiones_reglas_audiencia', 'id_version_reglas')->restrictOnDelete();
            $table->foreignId('id_tipo_audiencia')->constrained('tipos_audiencia', 'id_tipo_audiencia')->restrictOnDelete();
            $table->foreignId('id_usuario_responsable')->constrained('users')->restrictOnDelete();
            $table->string('huella_version', 64);
            $table->string('perfil_validacion', 80);
            $table->timestampTz('fecha_creacion')->useCurrent();
        });
        DB::statement("ALTER TABLE vinculaciones_reglas_practica ADD CHECK (id_vinculacion > 0 AND huella_version ~ '^[0-9a-f]{64}$' AND perfil_validacion = 'mvp-defensa-aciclico-ejecucion-v1')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION jurissim_estado_activacion_practica(identificador bigint) RETURNS text LANGUAGE sql VOLATILE AS $$
                SELECT COALESCE((
                    SELECT CASE
                        WHEN a.id_activacion IS DISTINCT FROM ultima_a.id_activacion THEN 'activacion_sustituida'
                        WHEN ra.id_revocacion_activacion IS NOT NULL THEN 'retirada'
                        WHEN r.id_revision_reglas IS DISTINCT FROM ultima_r.id_revision_reglas THEN 'revision_sustituida'
                        WHEN rr.id_revocacion_revision IS NOT NULL THEN 'revision_retirada'
                        WHEN r.decision <> 'aprobado' THEN 'revision_no_aprobada'
                        WHEN rh.id_revocacion_habilitacion IS NOT NULL THEN 'habilitacion_retirada'
                        WHEN h.fecha_vencimiento <= clock_timestamp() THEN 'habilitacion_vencida'
                        WHEN r.fecha_vencimiento IS NULL OR r.fecha_vencimiento <= clock_timestamp() THEN 'revision_vencida'
                        WHEN NOT t.activo THEN 'tipo_inactivo'
                        WHEN a.id_tipo_audiencia <> v.id_tipo_audiencia OR r.id_version_reglas <> v.id_version_reglas
                            OR r.id_habilitacion <> h.id_habilitacion OR r.id_tipo_audiencia <> a.id_tipo_audiencia
                            OR a.huella_version <> v.huella_contenido OR r.huella_version <> v.huella_contenido
                            OR r.huella_ejecucion_revisada IS DISTINCT FROM v.huella_contenido
                            OR v.huella_contenido <> encode(sha256(convert_to(v.contenido::text,'UTF8')),'hex')
                            OR a.perfil_validacion <> 'mvp-defensa-aciclico-ejecucion-v1'
                            OR NOT jurissim_contrato_ejecucion_valido(v.contenido, a.id_tipo_audiencia)
                            THEN 'integridad_incompatible'
                        ELSE 'vigente'
                    END
                    FROM activaciones_reglas_audiencia a
                    JOIN versiones_reglas_audiencia v USING (id_version_reglas)
                    JOIN revisiones_reglas_audiencia r USING (id_revision_reglas)
                    JOIN habilitaciones_revision_juridica h ON h.id_habilitacion=a.id_habilitacion
                    JOIN tipos_audiencia t ON t.id_tipo_audiencia=a.id_tipo_audiencia
                    LEFT JOIN LATERAL (SELECT id_activacion FROM activaciones_reglas_audiencia
                        WHERE id_tipo_audiencia=a.id_tipo_audiencia ORDER BY id_activacion DESC LIMIT 1) ultima_a ON true
                    LEFT JOIN LATERAL (SELECT id_revision_reglas FROM revisiones_reglas_audiencia
                        WHERE id_version_reglas=v.id_version_reglas ORDER BY fecha_creacion DESC,id_revision_reglas DESC LIMIT 1) ultima_r ON true
                    LEFT JOIN revocaciones_activaciones_reglas ra ON ra.id_activacion=a.id_activacion
                    LEFT JOIN revocaciones_revision_reglas rr ON rr.id_revision_reglas=r.id_revision_reglas
                    LEFT JOIN revocaciones_habilitacion_juridica rh ON rh.id_habilitacion=h.id_habilitacion
                    WHERE a.id_activacion=identificador
                ), 'sin_activacion');
            $$;
            -- Proyeccion de instrucciones explicitas por ID. Nunca consulta el catalogo.
            CREATE OR REPLACE FUNCTION jurissim_configuracion_practica(c jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE AS $$
                SELECT jsonb_build_object('turnos_por_etapa', COALESCE(jsonb_object_agg(etapa, turnos), '{}'::jsonb))
                FROM (
                    SELECT p->>'id_etapa' etapa,
                        jsonb_agg(jsonb_build_object('id_propuesta_turno', p->'id_propuesta_turno',
                            'orden', p->'orden', 'rol', p->'rol', 'instruccion', i->'texto')
                            ORDER BY (p->>'orden')::integer) turnos
                    FROM jsonb_array_elements(c->'propuestas') p
                    JOIN jsonb_array_elements(c#>'{contrato_ejecucion,instrucciones}') i
                        ON i->>'id_propuesta_turno'=p->>'id_propuesta_turno'
                    GROUP BY p->>'id_etapa'
                ) agrupados;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_validar_vinculacion_practica() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE a activaciones_reglas_audiencia; s simulaciones; v versiones_reglas_audiencia; ultima_decision text;
            BEGIN
                IF current_setting('transaction_isolation') <> 'read committed' THEN
                    RAISE EXCEPTION 'Vinculacion exige read committed.' USING ERRCODE='23514';
                END IF;
                PERFORM 1 FROM tipos_audiencia WHERE id_tipo_audiencia=NEW.id_tipo_audiencia FOR NO KEY UPDATE;
                SELECT * INTO a FROM activaciones_reglas_audiencia WHERE id_activacion=NEW.id_activacion;
                PERFORM 1 FROM habilitaciones_revision_juridica WHERE id_habilitacion=a.id_habilitacion FOR UPDATE;
                SELECT * INTO v FROM versiones_reglas_audiencia WHERE id_version_reglas=NEW.id_version_reglas FOR UPDATE;
                SELECT * INTO s FROM simulaciones WHERE id_simulacion=NEW.id_simulacion FOR UPDATE;
                PERFORM 1 FROM analisis_expediente WHERE id_analisis=s.id_analisis FOR UPDATE;
                SELECT decision INTO ultima_decision FROM revisiones_analisis WHERE id_analisis=s.id_analisis
                    ORDER BY fecha_creacion DESC,id_revision DESC LIMIT 1;
                IF a.id_activacion IS NULL OR s.id_simulacion IS NULL OR v.id_version_reglas IS NULL
                    OR s.id_tipo_audiencia <> NEW.id_tipo_audiencia OR a.id_tipo_audiencia <> NEW.id_tipo_audiencia
                    OR a.id_version_reglas <> NEW.id_version_reglas OR a.huella_version <> NEW.huella_version
                    OR v.huella_contenido <> NEW.huella_version OR a.perfil_validacion <> NEW.perfil_validacion
                    OR s.id_usuario <> NEW.id_usuario_responsable OR s.rol_usuario <> 'abogado_defensor'
                    OR s.estado <> 'preparando' OR s.id_etapa_actual IS NOT NULL OR s.fecha_inicio IS NOT NULL OR s.fecha_fin IS NOT NULL
                    OR NOT EXISTS (SELECT 1 FROM expedientes WHERE id_expediente=s.id_expediente AND id_usuario=NEW.id_usuario_responsable)
                    OR NOT EXISTS (SELECT 1 FROM analisis_expediente WHERE id_analisis=s.id_analisis AND id_expediente=s.id_expediente AND estado='procesado')
                    OR ultima_decision IS DISTINCT FROM 'aprobado'
                    OR EXISTS (SELECT 1 FROM intervenciones WHERE id_simulacion=s.id_simulacion)
                    OR jurissim_estado_activacion_practica(a.id_activacion) <> 'vigente' THEN
                    RAISE EXCEPTION 'Vinculacion sin contexto vigente.' USING ERRCODE='23514';
                END IF;
                IF s.configuracion IS DISTINCT FROM jurissim_configuracion_practica(v.contenido) THEN
                    RAISE EXCEPTION 'Configuracion distinta de la copia revisada.' USING ERRCODE='23514';
                END IF;
                NEW.fecha_creacion := clock_timestamp();
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER validar_vinculacion BEFORE INSERT ON vinculaciones_reglas_practica
                FOR EACH ROW EXECUTE FUNCTION jurissim_validar_vinculacion_practica();
            CREATE TRIGGER vinculacion_inmutable BEFORE UPDATE OR DELETE ON vinculaciones_reglas_practica
                FOR EACH ROW EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica();
            CREATE TRIGGER vinculacion_no_truncar BEFORE TRUNCATE ON vinculaciones_reglas_practica
                FOR EACH STATEMENT EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica();
            -- Hito interno: impedir que comandos antiguos ejecuten la nueva representacion.
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
            CREATE TRIGGER proteger_practica_vinculada BEFORE UPDATE ON simulaciones
                FOR EACH ROW EXECUTE FUNCTION jurissim_proteger_practica_vinculada();
            CREATE OR REPLACE FUNCTION jurissim_impedir_intervencion_vinculada() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM vinculaciones_reglas_practica WHERE id_simulacion=NEW.id_simulacion) THEN
                    RAISE EXCEPTION 'Los comandos de reglas aun no estan integrados.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER impedir_intervencion_vinculada BEFORE INSERT OR UPDATE ON intervenciones
                FOR EACH ROW EXECUTE FUNCTION jurissim_impedir_intervencion_vinculada();
            SQL);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE vinculaciones_reglas_practica, simulaciones, intervenciones IN ACCESS EXCLUSIVE MODE');
            if (DB::table('vinculaciones_reglas_practica')->exists()) {
                throw new RuntimeException('No se puede revertir: hay vinculaciones que deben preservarse.');
            }
            DB::statement('DROP TRIGGER impedir_intervencion_vinculada ON intervenciones');
            DB::statement('DROP TRIGGER proteger_practica_vinculada ON simulaciones');
            Schema::drop('vinculaciones_reglas_practica');
            foreach (['jurissim_impedir_intervencion_vinculada()', 'jurissim_proteger_practica_vinculada()',
                'jurissim_validar_vinculacion_practica()', 'jurissim_configuracion_practica(jsonb)', 'jurissim_estado_activacion_practica(bigint)'] as $funcion) {
                DB::statement("DROP FUNCTION {$funcion}");
            }
        });
    }
};
