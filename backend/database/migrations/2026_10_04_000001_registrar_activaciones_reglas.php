<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revisiones_reglas_audiencia', function (Blueprint $table): void {
            $table->string('huella_ejecucion_revisada', 64)->nullable();
        });
        DB::statement("ALTER TABLE revisiones_reglas_audiencia ADD CONSTRAINT revision_huella_ejecucion CHECK (huella_ejecucion_revisada IS NULL OR huella_ejecucion_revisada ~ '^[0-9a-f]{64}$')");
        Schema::create('activaciones_reglas_audiencia', function (Blueprint $table): void {
            $table->id('id_activacion');
            $table->foreignId('id_tipo_audiencia')->constrained('tipos_audiencia', 'id_tipo_audiencia')->restrictOnDelete();
            $table->foreignId('id_version_reglas')->constrained('versiones_reglas_audiencia', 'id_version_reglas')->restrictOnDelete();
            $table->foreignId('id_revision_reglas')->constrained('revisiones_reglas_audiencia', 'id_revision_reglas')->restrictOnDelete();
            $table->foreignId('id_habilitacion')->constrained('habilitaciones_revision_juridica', 'id_habilitacion')->restrictOnDelete();
            $table->foreignId('id_usuario_responsable')->constrained('users')->restrictOnDelete();
            $table->string('huella_version', 64);
            $table->string('perfil_validacion', 80);
            $table->text('motivo');
            $table->boolean('confirmacion_humana');
            $table->unsignedBigInteger('id_activacion_previa')->nullable();
            $table->foreign('id_activacion_previa')->references('id_activacion')->on('activaciones_reglas_audiencia')->restrictOnDelete();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->unique('id_revision_reglas');
            $table->index(['id_tipo_audiencia', 'id_activacion']);
        });
        Schema::create('revocaciones_activaciones_reglas', function (Blueprint $table): void {
            $table->id('id_revocacion_activacion');
            $table->foreignId('id_activacion')->unique()->constrained('activaciones_reglas_audiencia', 'id_activacion')->restrictOnDelete();
            $table->foreignId('id_usuario_responsable')->constrained('users')->restrictOnDelete();
            $table->text('motivo');
            $table->timestampTz('fecha_creacion')->useCurrent();
        });
        DB::statement('ALTER TABLE activaciones_reglas_audiencia ADD CHECK (id_activacion > 0)');
        DB::statement('ALTER TABLE revocaciones_activaciones_reglas ADD CHECK (id_revocacion_activacion > 0)');
        DB::statement("ALTER TABLE activaciones_reglas_audiencia ADD CHECK (confirmacion_humana AND perfil_validacion = 'mvp-defensa-aciclico-ejecucion-v1' AND huella_version ~ '^[0-9a-f]{64}$')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION jurissim_entero_contrato(v jsonb, minimo bigint DEFAULT 1) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
                SELECT COALESCE(jsonb_typeof(v) = 'number' AND v::text ~ '^(0|[1-9][0-9]*)$'
                    AND (CASE WHEN v::text ~ '^(0|[1-9][0-9]*)$' THEN v::text::numeric BETWEEN minimo AND 9223372036854775807 ELSE false END), false);
            $$;
            CREATE OR REPLACE FUNCTION jurissim_texto_contrato(v jsonb, maximo integer) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
                SELECT COALESCE(jsonb_typeof(v) = 'string' AND char_length(v #>> '{}') BETWEEN 1 AND maximo
                    AND translate(v #>> '{}', U&'\0009\000A\000B\000C\000D\0020\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000', '') <> '', false);
            $$;
            CREATE OR REPLACE FUNCTION jurissim_contrato_ejecucion_valido(c jsonb, tipo bigint) RETURNS boolean LANGUAGE plpgsql AS $$
            DECLARE etapa_fila jsonb; propuesta_fila jsonb; transicion_fila jsonb; instruccion_fila jsonb; plan jsonb; inicio text; cierre text; n integer;
            BEGIN
                IF NOT COALESCE(jurissim_entero_contrato(c->'formato') AND c->'formato' = '1'::jsonb
                    AND jurissim_entero_contrato(c#>'{tipo,id_tipo_audiencia}') AND c#>>'{tipo,id_tipo_audiencia}' = tipo::text
                    AND c#>'{tipo,activo}' = 'true'::jsonb, false) THEN RETURN false; END IF;
                FOREACH inicio IN ARRAY ARRAY['etapas','transiciones','propuestas','turnos_catalogo_referencia'] LOOP
                    IF jsonb_typeof(c->inicio) IS DISTINCT FROM 'array' THEN RETURN false; END IF;
                END LOOP;
                IF jsonb_array_length(c->'etapas') NOT BETWEEN 1 AND 100
                    OR jsonb_array_length(c->'transiciones') > 1000
                    OR jsonb_array_length(c->'propuestas') NOT BETWEEN 1 AND 10000
                    OR jsonb_array_length(c->'turnos_catalogo_referencia') > 10000
                    OR octet_length(c::text) > 2000000 THEN RETURN false; END IF;
                FOR etapa_fila IN SELECT value FROM jsonb_array_elements(c->'etapas') LOOP
                    IF NOT COALESCE(jsonb_typeof(etapa_fila) = 'object' AND jurissim_entero_contrato(etapa_fila->'id_etapa')
                        AND etapa_fila->>'id_etapa' ~ '^[1-9][0-9]*$' AND jurissim_entero_contrato(etapa_fila->'id_tipo_audiencia') AND etapa_fila->>'id_tipo_audiencia' = tipo::text
                        AND jurissim_entero_contrato(etapa_fila->'orden', 0) AND etapa_fila->>'orden' ~ '^[0-9]+$'
                        AND jurissim_texto_contrato(etapa_fila->'codigo', 80)
                        AND jurissim_texto_contrato(etapa_fila->'nombre', 255)
                        AND jsonb_typeof(etapa_fila->'activo') = 'boolean' AND jsonb_typeof(etapa_fila->'es_inicial') = 'boolean'
                        AND jsonb_typeof(etapa_fila->'es_final') = 'boolean', false) THEN RETURN false; END IF;
                END LOOP;
                IF EXISTS (SELECT 1 FROM jsonb_array_elements(c->'etapas') e GROUP BY e->>'id_etapa' HAVING count(*) > 1)
                    OR EXISTS (SELECT 1 FROM jsonb_array_elements(c->'etapas') e GROUP BY e->>'orden' HAVING count(*) > 1)
                    OR EXISTS (SELECT 1 FROM jsonb_array_elements(c->'etapas') e GROUP BY e->>'codigo' HAVING count(*) > 1) THEN RETURN false; END IF;
                SELECT count(*), min(e->>'id_etapa') INTO n, inicio FROM jsonb_array_elements(c->'etapas') e
                    WHERE e->'activo' = 'true'::jsonb AND e->'es_inicial' = 'true'::jsonb;
                IF n <> 1 THEN RETURN false; END IF;
                SELECT count(*), min(e->>'id_etapa') INTO n, cierre FROM jsonb_array_elements(c->'etapas') e
                    WHERE e->'activo' = 'true'::jsonb AND e->'es_final' = 'true'::jsonb;
                IF n <> 1 THEN RETURN false; END IF;
                FOR transicion_fila IN SELECT value FROM jsonb_array_elements(c->'transiciones') LOOP
                    IF NOT COALESCE(jsonb_typeof(transicion_fila) = 'object' AND jurissim_entero_contrato(transicion_fila->'id_transicion')
                        AND transicion_fila->>'id_transicion' ~ '^[1-9][0-9]*$' AND jurissim_entero_contrato(transicion_fila->'id_tipo_audiencia') AND transicion_fila->>'id_tipo_audiencia' = tipo::text
                        AND jsonb_typeof(transicion_fila->'activo') = 'boolean'
                        AND jurissim_entero_contrato(transicion_fila->'id_etapa_origen') AND jurissim_entero_contrato(transicion_fila->'id_etapa_destino')
                        AND transicion_fila->>'id_etapa_origen' <> transicion_fila->>'id_etapa_destino'
                        AND EXISTS (SELECT 1 FROM jsonb_array_elements(c->'etapas') e WHERE e->>'id_etapa' = transicion_fila->>'id_etapa_origen'
                            AND (transicion_fila->'activo' = 'false'::jsonb OR e->'activo' = 'true'::jsonb))
                        AND EXISTS (SELECT 1 FROM jsonb_array_elements(c->'etapas') e WHERE e->>'id_etapa' = transicion_fila->>'id_etapa_destino'
                            AND (transicion_fila->'activo' = 'false'::jsonb OR e->'activo' = 'true'::jsonb)), false) THEN RETURN false; END IF;
                    IF transicion_fila->'activo' = 'true'::jsonb AND (transicion_fila->>'id_etapa_destino' = inicio OR transicion_fila->>'id_etapa_origen' = cierre)
                        THEN RETURN false; END IF;
                END LOOP;
                IF EXISTS (SELECT 1 FROM jsonb_array_elements(c->'transiciones') t GROUP BY t->>'id_transicion' HAVING count(*) > 1)
                    OR EXISTS (SELECT 1 FROM jsonb_array_elements(c->'transiciones') t GROUP BY t->>'id_etapa_origen', t->>'id_etapa_destino' HAVING count(*) > 1)
                    THEN RETURN false; END IF;
                -- Cierre transitivo finito: UNION elimina pares repetidos, incluso en ciclos.
                IF EXISTS (
                    WITH RECURSIVE aristas AS (
                        SELECT t->>'id_etapa_origen' a, t->>'id_etapa_destino' b FROM jsonb_array_elements(c->'transiciones') t WHERE t->'activo' = 'true'::jsonb
                    ), alcance(a,b) AS (
                        SELECT a,b FROM aristas UNION SELECT r.a,t.b FROM alcance r JOIN aristas t ON t.a = r.b
                    )
                    SELECT 1 FROM alcance WHERE a=b
                    UNION ALL
                    SELECT 1 FROM jsonb_array_elements(c->'etapas') e WHERE e->'activo' = 'true'::jsonb AND (
                        (e->>'id_etapa' <> inicio AND NOT EXISTS (SELECT 1 FROM alcance WHERE a=inicio AND b=e->>'id_etapa'))
                        OR (e->>'id_etapa' <> cierre AND NOT EXISTS (SELECT 1 FROM alcance WHERE a=e->>'id_etapa' AND b=cierre))
                    )
                ) THEN RETURN false; END IF;
                FOR propuesta_fila IN SELECT value FROM jsonb_array_elements(c->'propuestas') LOOP
                    IF NOT COALESCE(jsonb_typeof(propuesta_fila) = 'object' AND jurissim_entero_contrato(propuesta_fila->'id_propuesta_turno')
                        AND propuesta_fila->>'id_propuesta_turno' ~ '^[1-9][0-9]*$' AND jurissim_entero_contrato(propuesta_fila->'id_tipo_audiencia') AND propuesta_fila->>'id_tipo_audiencia' = tipo::text
                        AND jurissim_entero_contrato(propuesta_fila->'id_etapa')
                        AND EXISTS (SELECT 1 FROM jsonb_array_elements(c->'etapas') e WHERE e->>'id_etapa' = propuesta_fila->>'id_etapa' AND e->'activo' = 'true'::jsonb)
                        AND jurissim_entero_contrato(propuesta_fila->'orden') AND propuesta_fila->>'orden' ~ '^[1-9][0-9]?$|^100$'
                        AND propuesta_fila->>'rol' IN ('juez','fiscal','abogado_defensor') AND propuesta_fila->>'estado' = 'borrador'
                        AND jurissim_entero_contrato(propuesta_fila->'id_usuario_creador') AND propuesta_fila->>'id_usuario_creador' ~ '^[1-9][0-9]*$'
                        AND jurissim_texto_contrato(propuesta_fila->'acto_propuesto', 160)
                        AND jurissim_texto_contrato(propuesta_fila->'descripcion_propuesta', 2000),
                        false) THEN RETURN false; END IF;
                END LOOP;
                IF EXISTS (SELECT 1 FROM jsonb_array_elements(c->'propuestas') p GROUP BY p->>'id_propuesta_turno' HAVING count(*) > 1)
                    OR EXISTS (SELECT 1 FROM jsonb_array_elements(c->'propuestas') p GROUP BY p->>'id_etapa',p->>'orden' HAVING count(*) > 1)
                    OR EXISTS (SELECT 1 FROM jsonb_array_elements(c->'etapas') e WHERE e->'activo' = 'true'::jsonb
                        AND (SELECT count(*) FROM jsonb_array_elements(c->'propuestas') p WHERE p->>'id_etapa' = e->>'id_etapa') NOT BETWEEN 1 AND 100)
                    THEN RETURN false; END IF;
                plan := c->'contrato_ejecucion';
                IF jsonb_typeof(plan) IS DISTINCT FROM 'object' THEN RETURN false; END IF;
                IF NOT COALESCE(jurissim_entero_contrato(plan->'formato') AND plan->'formato' = '1'::jsonb AND plan->>'perfil' = 'mvp-defensa-aciclico-ejecucion-v1'
                    AND plan->>'rol_usuario' = 'abogado_defensor' AND jsonb_typeof(plan->'instrucciones') = 'array', false)
                    OR (SELECT count(*) FROM jsonb_object_keys(plan)) <> 4 THEN RETURN false; END IF;
                IF jsonb_array_length(plan->'instrucciones') <> jsonb_array_length(c->'propuestas') THEN RETURN false; END IF;
                FOR instruccion_fila IN SELECT value FROM jsonb_array_elements(plan->'instrucciones') LOOP
                    IF jsonb_typeof(instruccion_fila) IS DISTINCT FROM 'object' THEN RETURN false; END IF;
                    IF NOT COALESCE(jurissim_entero_contrato(instruccion_fila->'id_propuesta_turno') AND instruccion_fila->>'id_propuesta_turno' ~ '^[1-9][0-9]*$'
                        AND EXISTS (SELECT 1 FROM jsonb_array_elements(c->'propuestas') p WHERE p->>'id_propuesta_turno' = instruccion_fila->>'id_propuesta_turno')
                        AND jurissim_texto_contrato(instruccion_fila->'texto', 5000), false)
                        OR (SELECT count(*) FROM jsonb_object_keys(instruccion_fila)) <> 2 THEN RETURN false; END IF;
                END LOOP;
                IF EXISTS (SELECT 1 FROM jsonb_array_elements(plan->'instrucciones') i GROUP BY i->>'id_propuesta_turno' HAVING count(*) > 1)
                    THEN RETURN false; END IF;
                IF EXISTS (SELECT 1 FROM jsonb_array_elements(c->'propuestas') WITH ORDINALITY p(value, posicion)
                    JOIN jsonb_array_elements(plan->'instrucciones') WITH ORDINALITY i(value, posicion) USING (posicion)
                    WHERE p.value->>'id_propuesta_turno' <> i.value->>'id_propuesta_turno') THEN RETURN false; END IF;
                RETURN true;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_validar_revision_ejecutable() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v versiones_reglas_audiencia;
            BEGIN
                SELECT * INTO v FROM versiones_reglas_audiencia WHERE id_version_reglas = NEW.id_version_reglas;
                IF v.contenido ? 'contrato_ejecucion' THEN
                    IF NEW.huella_ejecucion_revisada IS DISTINCT FROM v.huella_contenido
                        OR NOT jurissim_contrato_ejecucion_valido(v.contenido, v.id_tipo_audiencia) THEN
                        RAISE EXCEPTION 'La revision debe incluir el contrato ejecutable exacto.' USING ERRCODE='23514';
                    END IF;
                ELSIF NEW.huella_ejecucion_revisada IS NOT NULL THEN
                    RAISE EXCEPTION 'La copia no incluye contrato ejecutable.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER validar_plan_revision BEFORE INSERT ON revisiones_reglas_audiencia
                FOR EACH ROW EXECUTE FUNCTION jurissim_validar_revision_ejecutable();
            CREATE OR REPLACE FUNCTION jurissim_validar_activacion_reglas() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE r revisiones_reglas_audiencia; h habilitaciones_revision_juridica; v versiones_reglas_audiencia; anterior bigint; ultima bigint;
            BEGIN
                IF current_setting('transaction_isolation') <> 'read committed' THEN
                    RAISE EXCEPTION 'Activacion exige read committed.' USING ERRCODE='23514';
                END IF;
                -- NO KEY UPDATE evita bloquear los KEY SHARE de FK de una revision en curso.
                PERFORM 1 FROM tipos_audiencia WHERE id_tipo_audiencia=NEW.id_tipo_audiencia FOR NO KEY UPDATE;
                SELECT * INTO h FROM habilitaciones_revision_juridica WHERE id_habilitacion=NEW.id_habilitacion FOR UPDATE;
                SELECT * INTO v FROM versiones_reglas_audiencia WHERE id_version_reglas=NEW.id_version_reglas FOR UPDATE;
                SELECT * INTO r FROM revisiones_reglas_audiencia WHERE id_revision_reglas=NEW.id_revision_reglas;
                SELECT id_activacion INTO anterior FROM activaciones_reglas_audiencia WHERE id_tipo_audiencia=NEW.id_tipo_audiencia ORDER BY id_activacion DESC LIMIT 1;
                SELECT id_revision_reglas INTO ultima FROM revisiones_reglas_audiencia WHERE id_version_reglas=NEW.id_version_reglas ORDER BY fecha_creacion DESC,id_revision_reglas DESC LIMIT 1;
                NEW.fecha_creacion := clock_timestamp();
                IF NOT jurissim_es_admin_revision(NEW.id_usuario_responsable) THEN
                    RAISE EXCEPTION 'Activador no autorizado.' USING ERRCODE='42501';
                END IF;
                IF anterior IS DISTINCT FROM NEW.id_activacion_previa OR (anterior IS NOT NULL AND NEW.id_activacion <= anterior)
                    OR r.id_revision_reglas IS NULL
                    OR r.id_revision_reglas IS DISTINCT FROM ultima OR v.id_version_reglas IS NULL OR h.id_habilitacion IS NULL
                    OR r.id_version_reglas <> v.id_version_reglas OR r.id_tipo_audiencia <> NEW.id_tipo_audiencia
                    OR v.id_tipo_audiencia <> NEW.id_tipo_audiencia OR r.id_habilitacion <> h.id_habilitacion
                    OR r.huella_version <> v.huella_contenido OR r.huella_ejecucion_revisada IS DISTINCT FROM v.huella_contenido
                    OR NEW.huella_version <> v.huella_contenido
                    OR v.huella_contenido <> encode(sha256(convert_to(v.contenido::text,'UTF8')),'hex')
                    OR r.decision <> 'aprobado' OR r.fecha_vencimiento IS NULL OR r.fecha_vencimiento <= NEW.fecha_creacion OR h.fecha_vencimiento <= NEW.fecha_creacion
                    OR EXISTS (SELECT 1 FROM revocaciones_revision_reglas WHERE id_revision_reglas=r.id_revision_reglas)
                    OR EXISTS (SELECT 1 FROM revocaciones_habilitacion_juridica WHERE id_habilitacion=h.id_habilitacion)
                    OR NOT EXISTS (SELECT 1 FROM tipos_audiencia WHERE id_tipo_audiencia=NEW.id_tipo_audiencia AND activo)
                    OR NOT jurissim_contrato_ejecucion_valido(v.contenido, NEW.id_tipo_audiencia) THEN
                    RAISE EXCEPTION 'Activacion sin contexto vigente y ejecutable.' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_validar_retiro_activacion() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE a activaciones_reglas_audiencia;
            BEGIN
                IF current_setting('transaction_isolation') <> 'read committed' OR NOT jurissim_es_admin_revision(NEW.id_usuario_responsable) THEN
                    RAISE EXCEPTION 'Retiro no autorizado o aislamiento invalido.' USING ERRCODE='42501';
                END IF;
                SELECT * INTO a FROM activaciones_reglas_audiencia WHERE id_activacion=NEW.id_activacion;
                IF a.id_activacion IS NULL THEN RAISE EXCEPTION 'Activacion inexistente.' USING ERRCODE='23514'; END IF;
                PERFORM 1 FROM tipos_audiencia WHERE id_tipo_audiencia=a.id_tipo_audiencia FOR NO KEY UPDATE;
                NEW.fecha_creacion := clock_timestamp();
                RETURN NEW;
            END;
            $$;
            SQL);
        foreach (['activaciones_reglas_audiencia' => 'activacion_reglas', 'revocaciones_activaciones_reglas' => 'retiro_activacion'] as $tabla => $funcion) {
            DB::statement("ALTER TABLE {$tabla} ADD CHECK (jurissim_texto_contrato(to_jsonb(motivo), 2000))");
            DB::statement("CREATE TRIGGER validar_insercion BEFORE INSERT ON {$tabla} FOR EACH ROW EXECUTE FUNCTION jurissim_validar_{$funcion}()");
            DB::statement("CREATE TRIGGER historial_inmutable BEFORE UPDATE OR DELETE ON {$tabla} FOR EACH ROW EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica()");
            DB::statement("CREATE TRIGGER historial_no_truncar BEFORE TRUNCATE ON {$tabla} FOR EACH STATEMENT EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica()");
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE tipos_audiencia, habilitaciones_revision_juridica, versiones_reglas_audiencia, revisiones_reglas_audiencia, activaciones_reglas_audiencia, revocaciones_activaciones_reglas IN ACCESS EXCLUSIVE MODE');
            if (DB::table('activaciones_reglas_audiencia')->exists() || DB::table('revocaciones_activaciones_reglas')->exists()
                || DB::table('revisiones_reglas_audiencia')->whereNotNull('huella_ejecucion_revisada')->exists()
                || DB::table('versiones_reglas_audiencia')->whereRaw("jsonb_exists(contenido, 'contrato_ejecucion')")->exists()) {
                throw new RuntimeException('No se puede revertir: hay contratos o activaciones que deben preservarse.');
            }
            Schema::drop('revocaciones_activaciones_reglas');
            Schema::drop('activaciones_reglas_audiencia');
            DB::statement('DROP TRIGGER validar_plan_revision ON revisiones_reglas_audiencia');
            DB::statement('ALTER TABLE revisiones_reglas_audiencia DROP CONSTRAINT revision_huella_ejecucion');
            Schema::table('revisiones_reglas_audiencia', fn (Blueprint $table) => $table->dropColumn('huella_ejecucion_revisada'));
            foreach (['jurissim_validar_retiro_activacion()', 'jurissim_validar_activacion_reglas()', 'jurissim_validar_revision_ejecutable()', 'jurissim_contrato_ejecucion_valido(jsonb,bigint)', 'jurissim_texto_contrato(jsonb,integer)', 'jurissim_entero_contrato(jsonb,bigint)'] as $funcion) {
                DB::statement("DROP FUNCTION {$funcion}");
            }
        });
    }
};
