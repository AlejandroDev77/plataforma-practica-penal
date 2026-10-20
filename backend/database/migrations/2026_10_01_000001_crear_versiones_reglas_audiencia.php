<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versiones_reglas_audiencia', function (Blueprint $table): void {
            $table->id('id_version_reglas');
            $table->foreignId('id_tipo_audiencia')->constrained('tipos_audiencia', 'id_tipo_audiencia')->restrictOnDelete();
            $table->foreignId('id_usuario_autor')->constrained('users')->restrictOnDelete();
            $table->integer('numero_version');
            $table->text('motivo');
            $table->jsonb('contenido');
            $table->string('huella_contenido', 64);
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->unique(['id_tipo_audiencia', 'numero_version'], 'versiones_reglas_numero_unico');
            $table->unique(['id_version_reglas', 'id_tipo_audiencia'], 'versiones_reglas_tipo_unico');
            $table->index('id_usuario_autor');
        });

        DB::statement('ALTER TABLE versiones_reglas_audiencia ADD CONSTRAINT versiones_reglas_numero CHECK (numero_version > 0)');
        DB::statement("ALTER TABLE versiones_reglas_audiencia ADD CONSTRAINT versiones_reglas_motivo CHECK (char_length(motivo) BETWEEN 1 AND 2000 AND motivo ~ '[^[:space:]]')");
        DB::statement("ALTER TABLE versiones_reglas_audiencia ADD CONSTRAINT versiones_reglas_huella CHECK (huella_contenido ~ '^[0-9a-f]{64}$')");
        // COALESCE evita que un campo ausente pase un CHECK como NULL.
        DB::statement(<<<'SQL'
            ALTER TABLE versiones_reglas_audiencia ADD CONSTRAINT versiones_reglas_formato CHECK (COALESCE(
                jsonb_typeof(contenido) = 'object'
                AND contenido->'formato' = '1'::jsonb
                AND jsonb_typeof(contenido->'tipo') = 'object'
                AND jsonb_typeof(contenido #> '{tipo,id_tipo_audiencia}') = 'number'
                AND contenido #>> '{tipo,id_tipo_audiencia}' = id_tipo_audiencia::text
                AND jsonb_typeof(contenido->'etapas') = 'array'
                AND jsonb_typeof(contenido->'transiciones') = 'array'
                AND jsonb_typeof(contenido->'propuestas') = 'array'
                AND jsonb_typeof(contenido->'turnos_catalogo_referencia') = 'array', false))
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION jurissim_sellar_version_reglas() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- La base calcula la huella; no confia en un hash proporcionado por el cliente.
                NEW.huella_contenido := encode(sha256(convert_to(NEW.contenido::text, 'UTF8')), 'hex');
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER versiones_reglas_sellar BEFORE INSERT ON versiones_reglas_audiencia
                FOR EACH ROW EXECUTE FUNCTION jurissim_sellar_version_reglas();

            CREATE OR REPLACE FUNCTION jurissim_impedir_cambio_version_reglas() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Las versiones de reglas son inmutables; conserve esta version y cree otra.'
                    USING ERRCODE = '23514';
            END;
            $$;
            CREATE TRIGGER versiones_reglas_inmutables BEFORE UPDATE OR DELETE ON versiones_reglas_audiencia
                FOR EACH ROW EXECUTE FUNCTION jurissim_impedir_cambio_version_reglas();
            CREATE TRIGGER versiones_reglas_no_truncar BEFORE TRUNCATE ON versiones_reglas_audiencia
                FOR EACH STATEMENT EXECUTE FUNCTION jurissim_impedir_cambio_version_reglas();
            SQL);
    }

    public function down(): void
    {
        // No permitir que un rollback normal destruya versiones ya selladas.
        DB::transaction(function (): void {
            if (! Schema::hasTable('versiones_reglas_audiencia')) {
                return;
            }
            DB::statement('LOCK TABLE versiones_reglas_audiencia IN ACCESS EXCLUSIVE MODE');
            if (DB::table('versiones_reglas_audiencia')->exists()) {
                throw new RuntimeException('No se puede revertir: hay versiones selladas que deben preservarse.');
            }
            Schema::drop('versiones_reglas_audiencia');
            DB::statement('DROP FUNCTION jurissim_sellar_version_reglas()');
            DB::statement('DROP FUNCTION jurissim_impedir_cambio_version_reglas()');
        });
    }
};
