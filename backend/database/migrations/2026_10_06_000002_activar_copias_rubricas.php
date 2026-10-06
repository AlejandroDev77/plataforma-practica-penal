<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activaciones_rubricas', function (Blueprint $t): void {
            $t->id('id_activacion_rubrica');
            $t->foreignId('id_tipo_audiencia')->constrained('tipos_audiencia', 'id_tipo_audiencia')->restrictOnDelete();
            $t->foreignId('id_rubrica')->constrained('rubricas', 'id_rubrica')->restrictOnDelete();
            $t->foreignId('id_version_rubrica')->constrained('versiones_rubricas_evaluacion', 'id_version_rubrica')->restrictOnDelete();
            $t->foreignId('id_revision_rubrica')->unique()->constrained('revisiones_rubricas_evaluacion', 'id_revision_rubrica')->restrictOnDelete();
            $t->foreignId('id_habilitacion_rubrica')->constrained('habilitaciones_revision_rubricas', 'id_habilitacion_rubrica')->restrictOnDelete();
            $t->foreignId('id_usuario_responsable')->constrained('users')->restrictOnDelete();
            $t->string('huella_version', 64);
            $t->string('perfil_validacion', 80);
            $t->text('motivo');
            $t->boolean('confirmacion_humana');
            $t->foreignId('id_activacion_previa')->nullable()->constrained('activaciones_rubricas', 'id_activacion_rubrica')->restrictOnDelete();
            $t->timestampTz('fecha_creacion')->useCurrent();
            $t->index(['id_tipo_audiencia', 'id_activacion_rubrica'], 'activacion_rubrica_consulta');
        });
        Schema::create('retiros_activaciones_rubricas', function (Blueprint $t): void {
            $t->id('id_retiro_activacion_rubrica');
            $t->foreignId('id_activacion_rubrica')->unique()->constrained('activaciones_rubricas', 'id_activacion_rubrica')->restrictOnDelete();
            $t->foreignId('id_usuario_responsable')->constrained('users')->restrictOnDelete();
            $t->text('motivo');
            $t->timestampTz('fecha_creacion')->useCurrent();
        });
        DB::statement("ALTER TABLE activaciones_rubricas ADD CHECK (id_activacion_rubrica>0 AND confirmacion_humana AND perfil_validacion='mvp-evaluacion-defensa-v1' AND huella_version ~ '^[0-9a-f]{64}$')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION jurissim_gobernar_activacion_rubrica() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE a activaciones_rubricas; v versiones_rubricas_evaluacion; r revisiones_rubricas_evaluacion;
                anterior bigint; ultima bigint; tipo bigint; rubrica bigint;
            BEGIN
                IF current_setting('transaction_isolation')<>'read committed' THEN
                    RAISE EXCEPTION 'Activacion exige read committed.' USING ERRCODE='23514';
                END IF;
                IF TG_TABLE_NAME='activaciones_rubricas' THEN
                    SELECT * INTO v FROM versiones_rubricas_evaluacion WHERE id_version_rubrica=NEW.id_version_rubrica;
                    tipo := v.id_tipo_audiencia; rubrica := v.id_rubrica;
                ELSE
                    SELECT * INTO a FROM activaciones_rubricas WHERE id_activacion_rubrica=NEW.id_activacion_rubrica;
                    tipo := a.id_tipo_audiencia; rubrica := a.id_rubrica;
                END IF;
                -- Serializa la elección entre rúbricas del mismo tipo sin bloquear KEY SHARE de las FK.
                PERFORM 1 FROM tipos_audiencia WHERE id_tipo_audiencia=tipo FOR NO KEY UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'Tipo inexistente.' USING ERRCODE='23514'; END IF;
                -- Compatible con el bloqueo parental de sellos, revisiones y retiros de fase 30.
                PERFORM 1 FROM rubricas WHERE id_rubrica=rubrica FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'Rubrica inexistente.' USING ERRCODE='23514'; END IF;
                NEW.fecha_creacion := clock_timestamp();
                IF NOT jurissim_es_admin_revision(NEW.id_usuario_responsable) THEN
                    RAISE EXCEPTION 'Administrador no autorizado.' USING ERRCODE='42501';
                END IF;
                IF TG_TABLE_NAME='activaciones_rubricas' THEN
                    SELECT * INTO r FROM revisiones_rubricas_evaluacion WHERE id_revision_rubrica=NEW.id_revision_rubrica;
                    SELECT id_revision_rubrica INTO ultima FROM revisiones_rubricas_evaluacion
                        WHERE id_version_rubrica=v.id_version_rubrica ORDER BY id_revision_rubrica DESC LIMIT 1;
                    SELECT id_activacion_rubrica INTO anterior FROM activaciones_rubricas
                        WHERE id_tipo_audiencia=tipo ORDER BY id_activacion_rubrica DESC LIMIT 1;
                    IF NEW.id_tipo_audiencia IS DISTINCT FROM tipo OR NEW.id_rubrica IS DISTINCT FROM rubrica
                        OR r.id_revision_rubrica IS NULL OR r.id_revision_rubrica IS DISTINCT FROM ultima
                        OR r.id_version_rubrica IS DISTINCT FROM v.id_version_rubrica
                        OR r.id_rubrica IS DISTINCT FROM rubrica
                        OR r.id_habilitacion_rubrica IS DISTINCT FROM NEW.id_habilitacion_rubrica
                        OR NEW.huella_version IS DISTINCT FROM v.huella_contenido
                        OR NEW.id_activacion_previa IS DISTINCT FROM anterior
                        OR (anterior IS NOT NULL AND NEW.id_activacion_rubrica<=anterior)
                        OR jurissim_estado_revision_rubrica(v.id_version_rubrica) IS DISTINCT FROM 'aprobado'
                        OR NOT EXISTS (SELECT 1 FROM tipos_audiencia WHERE id_tipo_audiencia=tipo AND activo) THEN
                        RAISE EXCEPTION 'Copia, revision o lectura previa no vigente.' USING ERRCODE='23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_estado_activacion_rubrica(identificador bigint) RETURNS text LANGUAGE plpgsql AS $$
            DECLARE a activaciones_rubricas; v versiones_rubricas_evaluacion; r revisiones_rubricas_evaluacion;
                ultima bigint; anterior bigint;
            BEGIN
                SELECT * INTO a FROM activaciones_rubricas WHERE id_activacion_rubrica=identificador;
                IF a.id_activacion_rubrica IS NULL THEN RETURN 'sin_activacion'; END IF;
                SELECT id_activacion_rubrica INTO anterior FROM activaciones_rubricas
                    WHERE id_tipo_audiencia=a.id_tipo_audiencia ORDER BY id_activacion_rubrica DESC LIMIT 1;
                IF a.id_activacion_rubrica IS DISTINCT FROM anterior THEN RETURN 'sustituida'; END IF;
                IF EXISTS (SELECT 1 FROM retiros_activaciones_rubricas WHERE id_activacion_rubrica=identificador) THEN RETURN 'retirada'; END IF;
                IF NOT EXISTS (SELECT 1 FROM tipos_audiencia WHERE id_tipo_audiencia=a.id_tipo_audiencia AND activo) THEN RETURN 'tipo_inactivo'; END IF;
                SELECT * INTO v FROM versiones_rubricas_evaluacion WHERE id_version_rubrica=a.id_version_rubrica;
                SELECT * INTO r FROM revisiones_rubricas_evaluacion WHERE id_revision_rubrica=a.id_revision_rubrica;
                IF v.id_tipo_audiencia IS DISTINCT FROM a.id_tipo_audiencia OR v.id_rubrica IS DISTINCT FROM a.id_rubrica
                    OR r.id_version_rubrica IS DISTINCT FROM a.id_version_rubrica
                    OR r.id_rubrica IS DISTINCT FROM a.id_rubrica
                    OR r.id_habilitacion_rubrica IS DISTINCT FROM a.id_habilitacion_rubrica
                    OR a.huella_version IS DISTINCT FROM v.huella_contenido THEN RETURN 'integridad_incompatible'; END IF;
                SELECT id_revision_rubrica INTO ultima FROM revisiones_rubricas_evaluacion
                    WHERE id_version_rubrica=a.id_version_rubrica ORDER BY id_revision_rubrica DESC LIMIT 1;
                IF a.id_revision_rubrica IS DISTINCT FROM ultima THEN RETURN 'revision_sustituida'; END IF;
                IF jurissim_estado_revision_rubrica(a.id_version_rubrica) IS DISTINCT FROM 'aprobado' THEN RETURN 'revision_no_vigente'; END IF;
                RETURN 'vigente';
            END;
            $$;
            SQL);
        foreach (['activaciones_rubricas', 'retiros_activaciones_rubricas'] as $tabla) {
            DB::statement("ALTER TABLE {$tabla} ADD CHECK (jurissim_texto_rubrica_valido(motivo,2000))");
            DB::statement("CREATE TRIGGER gobernar_activacion_rubrica BEFORE INSERT ON {$tabla} FOR EACH ROW EXECUTE FUNCTION jurissim_gobernar_activacion_rubrica()");
            DB::statement("CREATE TRIGGER activacion_rubrica_inmutable BEFORE UPDATE OR DELETE ON {$tabla} FOR EACH ROW EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica()");
            DB::statement("CREATE TRIGGER activacion_rubrica_no_truncar BEFORE TRUNCATE ON {$tabla} FOR EACH STATEMENT EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica()");
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE tipos_audiencia, rubricas, activaciones_rubricas, retiros_activaciones_rubricas IN ACCESS EXCLUSIVE MODE');
            if (DB::table('activaciones_rubricas')->exists() || DB::table('retiros_activaciones_rubricas')->exists()) {
                throw new RuntimeException('Las activaciones deben conservarse; no se revierte esta migracion.');
            }
            Schema::drop('retiros_activaciones_rubricas');
            Schema::drop('activaciones_rubricas');
            DB::statement('DROP FUNCTION jurissim_estado_activacion_rubrica(bigint)');
            DB::statement('DROP FUNCTION jurissim_gobernar_activacion_rubrica()');
        });
    }
};
