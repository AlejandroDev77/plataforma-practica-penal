<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contextos_evaluacion_reglada', function (Blueprint $table): void {
            $table->id('id_contexto_evaluacion');
            $table->foreignId('id_evaluacion')->unique()->constrained('evaluaciones', 'id_evaluacion')->restrictOnDelete();
            $table->foreignId('id_version_rubrica')->constrained('versiones_rubricas_evaluacion', 'id_version_rubrica')->restrictOnDelete();
            $table->foreignId('id_revision_rubrica')->constrained('revisiones_rubricas_evaluacion', 'id_revision_rubrica')->restrictOnDelete();
            $table->foreignId('id_activacion_rubrica')->constrained('activaciones_rubricas', 'id_activacion_rubrica')->restrictOnDelete();
            $table->foreignId('id_usuario_consentimiento')->constrained('users')->restrictOnDelete();
            $table->string('proveedor', 20);
            $table->string('huella_contexto', 64);
            $table->jsonb('contenido');
            $table->boolean('consentimiento_externo');
            $table->timestampTz('fecha_consentimiento')->useCurrent();
            $table->unique(['id_evaluacion', 'huella_contexto'], 'contexto_evaluacion_huella_unica');
        });

        DB::statement("ALTER TABLE contextos_evaluacion_reglada ADD CONSTRAINT contexto_evaluacion_proveedor CHECK (proveedor='openai' AND consentimiento_externo IS TRUE)");
        DB::statement("ALTER TABLE contextos_evaluacion_reglada ADD CONSTRAINT contexto_evaluacion_huella CHECK (huella_contexto ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE contextos_evaluacion_reglada ADD CONSTRAINT contexto_evaluacion_contenido CHECK (jsonb_typeof(contenido)='object' AND octet_length(contenido::text) <= 250000)");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION jurissim_validar_contexto_evaluacion_reglada() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE e evaluaciones; s simulaciones; a activaciones_rubricas; v versiones_rubricas_evaluacion;
            BEGIN
                SELECT * INTO e FROM evaluaciones WHERE id_evaluacion=NEW.id_evaluacion;
                SELECT * INTO s FROM simulaciones WHERE id_simulacion=e.id_simulacion;
                SELECT * INTO a FROM activaciones_rubricas WHERE id_activacion_rubrica=NEW.id_activacion_rubrica;
                SELECT * INTO v FROM versiones_rubricas_evaluacion WHERE id_version_rubrica=NEW.id_version_rubrica;
                IF e.id_rubrica IS DISTINCT FROM v.id_rubrica OR a.id_version_rubrica IS DISTINCT FROM v.id_version_rubrica
                    OR a.id_revision_rubrica IS DISTINCT FROM NEW.id_revision_rubrica
                    OR a.id_rubrica IS DISTINCT FROM v.id_rubrica OR a.id_tipo_audiencia IS DISTINCT FROM s.id_tipo_audiencia
                    OR s.id_usuario IS DISTINCT FROM NEW.id_usuario_consentimiento OR s.estado IS DISTINCT FROM 'finalizada'
                    OR s.rol_usuario IS DISTINCT FROM 'abogado_defensor'
                    OR NOT EXISTS (SELECT 1 FROM vinculaciones_reglas_practica l
                        WHERE l.id_simulacion=s.id_simulacion AND l.id_tipo_audiencia=s.id_tipo_audiencia)
                    OR jurissim_estado_activacion_rubrica(a.id_activacion_rubrica) IS DISTINCT FROM 'vigente'
                    OR jurissim_estado_revision_rubrica(v.id_version_rubrica) IS DISTINCT FROM 'aprobado'
                    OR NEW.proveedor IS DISTINCT FROM 'openai' OR NEW.consentimiento_externo IS DISTINCT FROM true THEN
                    RAISE EXCEPTION 'Contexto de evaluación sin cierre, activación o consentimiento vigentes.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER validar_contexto_evaluacion_reglada BEFORE INSERT ON contextos_evaluacion_reglada
                FOR EACH ROW EXECUTE FUNCTION jurissim_validar_contexto_evaluacion_reglada();
            CREATE TRIGGER contexto_evaluacion_reglada_inmutable BEFORE UPDATE OR DELETE ON contextos_evaluacion_reglada
                FOR EACH ROW EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica();
            CREATE TRIGGER contexto_evaluacion_reglada_no_truncar BEFORE TRUNCATE ON contextos_evaluacion_reglada
                FOR EACH STATEMENT EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica();
            SQL);
    }

    public function down(): void
    {
        if (DB::table('contextos_evaluacion_reglada')->exists()) {
            throw new RuntimeException('No se revierten contextos de evaluación ya consentidos.');
        }
        Schema::dropIfExists('contextos_evaluacion_reglada');
        DB::unprepared('DROP FUNCTION jurissim_validar_contexto_evaluacion_reglada()');
    }
};
