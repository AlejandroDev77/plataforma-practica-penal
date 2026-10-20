<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habilitaciones_revision_juridica', function (Blueprint $table): void {
            $table->id('id_habilitacion');
            $table->unsignedBigInteger('id_usuario_revisor');
            $table->unsignedBigInteger('id_usuario_habilitador');
            $table->unsignedBigInteger('id_tipo_audiencia');
            $table->text('alcance');
            $table->string('referencia_acreditacion', 500);
            $table->string('huella_acreditacion', 64);
            $table->boolean('acreditacion_verificada');
            $table->timestampTz('fecha_vencimiento');
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->foreign('id_usuario_revisor', 'hab_juridica_revisor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('id_usuario_habilitador', 'hab_juridica_autor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('id_tipo_audiencia', 'hab_juridica_tipo_fk')->references('id_tipo_audiencia')->on('tipos_audiencia')->restrictOnDelete();
            $table->unique(['id_habilitacion', 'id_tipo_audiencia', 'id_usuario_revisor'], 'hab_juridica_contexto_unico');
            $table->index(['id_usuario_revisor', 'id_tipo_audiencia'], 'hab_juridica_consulta_idx');
        });
        Schema::create('revocaciones_habilitacion_juridica', function (Blueprint $table): void {
            $table->id('id_revocacion_habilitacion');
            $table->unsignedBigInteger('id_habilitacion')->unique();
            $table->unsignedBigInteger('id_usuario_responsable');
            $table->text('motivo');
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->foreign('id_habilitacion', 'rev_hab_origen_fk')->references('id_habilitacion')->on('habilitaciones_revision_juridica')->restrictOnDelete();
            $table->foreign('id_usuario_responsable', 'rev_hab_autor_fk')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('revisiones_reglas_audiencia', function (Blueprint $table): void {
            $table->id('id_revision_reglas');
            $table->unsignedBigInteger('id_version_reglas');
            $table->unsignedBigInteger('id_tipo_audiencia');
            $table->unsignedBigInteger('id_habilitacion');
            $table->unsignedBigInteger('id_usuario_revisor');
            $table->string('huella_version', 64);
            $table->string('decision', 30);
            $table->text('alcance_revisado');
            $table->text('observacion');
            $table->jsonb('fuentes_verificadas');
            $table->date('vigencia_comprobada_en');
            $table->boolean('confirmacion_humana');
            $table->timestampTz('fecha_vencimiento')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->foreign(['id_version_reglas', 'id_tipo_audiencia'], 'rev_reglas_version_fk')->references(['id_version_reglas', 'id_tipo_audiencia'])->on('versiones_reglas_audiencia')->restrictOnDelete();
            $table->foreign(['id_habilitacion', 'id_tipo_audiencia', 'id_usuario_revisor'], 'rev_reglas_habilitacion_fk')->references(['id_habilitacion', 'id_tipo_audiencia', 'id_usuario_revisor'])->on('habilitaciones_revision_juridica')->restrictOnDelete();
            $table->index(['id_version_reglas', 'id_revision_reglas'], 'rev_reglas_consulta_idx');
        });
        Schema::create('revocaciones_revision_reglas', function (Blueprint $table): void {
            $table->id('id_revocacion_revision');
            $table->unsignedBigInteger('id_revision_reglas')->unique();
            $table->unsignedBigInteger('id_usuario_responsable');
            $table->text('motivo');
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->foreign('id_revision_reglas', 'rev_decision_origen_fk')->references('id_revision_reglas')->on('revisiones_reglas_audiencia')->restrictOnDelete();
            $table->foreign('id_usuario_responsable', 'rev_decision_autor_fk')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE habilitaciones_revision_juridica ADD CONSTRAINT hab_juridica_requisitos CHECK (id_usuario_revisor <> id_usuario_habilitador AND acreditacion_verificada AND fecha_vencimiento > fecha_creacion AND huella_acreditacion ~ '^[0-9a-f]{64}$')");
        foreach (['habilitaciones_revision_juridica' => ['alcance' => 2000, 'referencia_acreditacion' => 500],
            'revisiones_reglas_audiencia' => ['alcance_revisado' => 2000, 'observacion' => 5000],
            'revocaciones_habilitacion_juridica' => ['motivo' => 2000],
            'revocaciones_revision_reglas' => ['motivo' => 2000]] as $tabla => $campos) {
            foreach ($campos as $campo => $maximo) {
                DB::statement("ALTER TABLE {$tabla} ADD CHECK (char_length({$campo}) BETWEEN 1 AND {$maximo} AND {$campo} ~ '[^[:space:]]')");
            }
        }
        DB::statement(<<<'SQL'
            ALTER TABLE revisiones_reglas_audiencia ADD CONSTRAINT rev_reglas_requisitos CHECK (
                confirmacion_humana AND huella_version ~ '^[0-9a-f]{64}$'
                AND vigencia_comprobada_en <= (fecha_creacion AT TIME ZONE 'America/La_Paz')::date
                AND ((decision = 'aprobado' AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento > fecha_creacion)
                    OR (decision = 'requiere_cambios' AND fecha_vencimiento IS NULL))
                AND COALESCE(CASE WHEN jsonb_typeof(fuentes_verificadas) = 'array'
                    THEN jsonb_array_length(fuentes_verificadas) BETWEEN 1 AND 20 ELSE false END, false))
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION jurissim_es_admin_revision(id_actor bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (SELECT 1 FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id
                    WHERE mr.model_id = id_actor AND mr.model_type = 'App\Models\User'
                        AND r.name = 'administrador_plataforma' AND r.guard_name = 'web');
            $$;
            CREATE OR REPLACE FUNCTION jurissim_validar_habilitacion_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF current_setting('transaction_isolation') <> 'read committed' THEN
                    RAISE EXCEPTION 'La revision exige aislamiento read committed.' USING ERRCODE = '23514';
                END IF;
                -- El bloqueo de usuario serializa habilitaciones simultaneas del mismo revisor.
                PERFORM 1 FROM users WHERE id = NEW.id_usuario_revisor FOR UPDATE;
                IF NOT jurissim_es_admin_revision(NEW.id_usuario_habilitador) THEN
                    RAISE EXCEPTION 'Habilitador no autorizado.' USING ERRCODE = '42501';
                END IF;
                NEW.fecha_creacion := clock_timestamp();
                IF EXISTS (SELECT 1 FROM habilitaciones_revision_juridica h WHERE h.id_usuario_revisor = NEW.id_usuario_revisor
                    AND h.id_tipo_audiencia = NEW.id_tipo_audiencia AND h.fecha_vencimiento > NEW.fecha_creacion
                    AND NOT EXISTS (SELECT 1 FROM revocaciones_habilitacion_juridica rh WHERE rh.id_habilitacion = h.id_habilitacion)) THEN
                    RAISE EXCEPTION 'Ya existe una habilitacion vigente para este ambito.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_validar_revision_reglas() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE h habilitaciones_revision_juridica; v versiones_reglas_audiencia;
            BEGIN
                IF current_setting('transaction_isolation') <> 'read committed' THEN
                    RAISE EXCEPTION 'La revision exige aislamiento read committed.' USING ERRCODE = '23514';
                END IF;
                -- Orden comun: habilitacion, version; luego verificar revocacion tras obtener bloqueo.
                SELECT * INTO h FROM habilitaciones_revision_juridica WHERE id_habilitacion = NEW.id_habilitacion FOR UPDATE;
                SELECT * INTO v FROM versiones_reglas_audiencia WHERE id_version_reglas = NEW.id_version_reglas FOR UPDATE;
                NEW.fecha_creacion := clock_timestamp();
                IF h.id_habilitacion IS NULL OR v.id_version_reglas IS NULL
                    OR h.id_usuario_revisor <> NEW.id_usuario_revisor OR h.id_tipo_audiencia <> NEW.id_tipo_audiencia
                    OR v.id_tipo_audiencia <> NEW.id_tipo_audiencia OR v.huella_contenido <> NEW.huella_version
                    OR NEW.alcance_revisado <> h.alcance
                    OR h.fecha_vencimiento <= NEW.fecha_creacion
                    OR EXISTS (SELECT 1 FROM revocaciones_habilitacion_juridica WHERE id_habilitacion = h.id_habilitacion)
                    OR (NEW.fecha_vencimiento IS NOT NULL AND NEW.fecha_vencimiento > h.fecha_vencimiento) THEN
                    RAISE EXCEPTION 'Revision sin habilitacion vigente o contexto verificable.' USING ERRCODE = '23514';
                END IF;
                IF v.id_usuario_autor = NEW.id_usuario_revisor
                    OR jsonb_array_length(v.contenido->'propuestas') = 0
                    OR jsonb_array_length(v.contenido->'etapas') = 0 OR EXISTS (
                    SELECT 1 FROM jsonb_array_elements(v.contenido->'propuestas') p
                    WHERE (p->>'id_usuario_creador') IS NULL
                        OR (p->>'id_usuario_creador') !~ '^[1-9][0-9]*$'
                        OR p->>'id_usuario_creador' = NEW.id_usuario_revisor::text) THEN
                    RAISE EXCEPTION 'La revision requiere autoria acreditada e independencia.' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.fuentes_verificadas) f
                    WHERE NOT COALESCE(jsonb_typeof(f) = 'object'
                        AND jsonb_typeof(f->'referencia') = 'string'
                        AND jsonb_typeof(f->'huella_contenido') = 'string'
                        AND char_length(f->>'referencia') BETWEEN 1 AND 500
                        AND f->>'referencia' ~ '[^[:space:]]'
                        AND f->>'huella_contenido' ~ '^[0-9a-f]{64}$', false)) THEN
                    RAISE EXCEPTION 'Las fuentes requieren referencia y huella.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_validar_revocacion_habilitacion() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE h habilitaciones_revision_juridica;
            BEGIN
                IF current_setting('transaction_isolation') <> 'read committed' THEN
                    RAISE EXCEPTION 'La revision exige aislamiento read committed.' USING ERRCODE = '23514';
                END IF;
                SELECT * INTO h FROM habilitaciones_revision_juridica WHERE id_habilitacion = NEW.id_habilitacion FOR UPDATE;
                IF h.id_habilitacion IS NULL OR NOT (NEW.id_usuario_responsable = h.id_usuario_revisor
                    OR jurissim_es_admin_revision(NEW.id_usuario_responsable)) THEN
                    RAISE EXCEPTION 'Revocacion de habilitacion no autorizada.' USING ERRCODE = '42501';
                END IF;
                NEW.fecha_creacion := clock_timestamp();
                RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_validar_revocacion_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE r revisiones_reglas_audiencia;
            BEGIN
                IF current_setting('transaction_isolation') <> 'read committed' THEN
                    RAISE EXCEPTION 'La revision exige aislamiento read committed.' USING ERRCODE = '23514';
                END IF;
                SELECT * INTO r FROM revisiones_reglas_audiencia WHERE id_revision_reglas = NEW.id_revision_reglas;
                PERFORM 1 FROM habilitaciones_revision_juridica WHERE id_habilitacion = r.id_habilitacion FOR UPDATE;
                PERFORM 1 FROM versiones_reglas_audiencia WHERE id_version_reglas = r.id_version_reglas FOR UPDATE;
                IF r.id_revision_reglas IS NULL OR NOT (NEW.id_usuario_responsable = r.id_usuario_revisor
                    OR jurissim_es_admin_revision(NEW.id_usuario_responsable)) THEN
                    RAISE EXCEPTION 'Revocacion de revision no autorizada.' USING ERRCODE = '42501';
                END IF;
                NEW.fecha_creacion := clock_timestamp();
                RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_impedir_cambio_revision_juridica() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'El historial de revision es inmutable; registre una revocacion.' USING ERRCODE = '23514';
            END;
            $$;
            SQL);
        foreach (['habilitaciones_revision_juridica' => 'habilitacion_revision',
            'revisiones_reglas_audiencia' => 'revision_reglas',
            'revocaciones_habilitacion_juridica' => 'revocacion_habilitacion',
            'revocaciones_revision_reglas' => 'revocacion_revision'] as $tabla => $validacion) {
            DB::statement("CREATE TRIGGER validar_insercion BEFORE INSERT ON {$tabla} FOR EACH ROW EXECUTE FUNCTION jurissim_validar_{$validacion}()");
            DB::statement("CREATE TRIGGER historial_inmutable BEFORE UPDATE OR DELETE ON {$tabla} FOR EACH ROW EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica()");
            DB::statement("CREATE TRIGGER historial_no_truncar BEFORE TRUNCATE ON {$tabla} FOR EACH STATEMENT EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica()");
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $tablas = ['revocaciones_revision_reglas', 'revisiones_reglas_audiencia', 'revocaciones_habilitacion_juridica', 'habilitaciones_revision_juridica'];
            // Mismo orden que las operaciones: habilitacion y version antes de decisiones/revocaciones.
            DB::statement('LOCK TABLE habilitaciones_revision_juridica, versiones_reglas_audiencia, revisiones_reglas_audiencia, revocaciones_habilitacion_juridica, revocaciones_revision_reglas IN ACCESS EXCLUSIVE MODE');
            foreach ($tablas as $tabla) {
                if (DB::table($tabla)->exists()) {
                    throw new RuntimeException('No se puede revertir: hay registros de revision juridica que deben preservarse.');
                }
            }
            foreach ($tablas as $tabla) {
                Schema::drop($tabla);
            }
            foreach (['habilitacion_revision', 'revision_reglas', 'revocacion_habilitacion', 'revocacion_revision'] as $funcion) {
                DB::statement("DROP FUNCTION jurissim_validar_{$funcion}()");
            }
            DB::statement('DROP FUNCTION jurissim_es_admin_revision(bigint)');
            DB::statement('DROP FUNCTION jurissim_impedir_cambio_revision_juridica()');
        });
    }
};
