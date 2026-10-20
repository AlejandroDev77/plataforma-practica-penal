<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLAS = ['retiros_revision_rubricas', 'revisiones_rubricas_evaluacion',
        'retiros_habilitacion_rubricas', 'habilitaciones_revision_rubricas', 'autores_version_rubrica', 'versiones_rubricas_evaluacion'];

    public function up(): void
    {
        Schema::create('versiones_rubricas_evaluacion', function (Blueprint $t): void {
            $t->id('id_version_rubrica');
            $t->foreignId('id_rubrica')->constrained('rubricas', 'id_rubrica')->restrictOnDelete();
            $t->foreignId('id_tipo_audiencia')->constrained('tipos_audiencia', 'id_tipo_audiencia')->restrictOnDelete();
            $t->foreignId('id_usuario_preparador')->constrained('users')->restrictOnDelete();
            $t->integer('numero_version');
            $t->text('motivo');
            $t->jsonb('ids_autores_declarados');
            $t->boolean('confirmacion_autoria_declarada');
            $t->jsonb('contenido');
            $t->string('huella_contenido', 64);
            $t->timestampTz('fecha_creacion')->useCurrent();
            $t->unique(['id_rubrica', 'numero_version'], 'version_rubrica_numero_unico');
            $t->unique(['id_version_rubrica', 'id_rubrica'], 'version_rubrica_ambito_unico');
        });
        Schema::create('autores_version_rubrica', function (Blueprint $t): void {
            $t->id('id_autor_version_rubrica');
            $t->foreignId('id_version_rubrica')->constrained('versiones_rubricas_evaluacion', 'id_version_rubrica')->restrictOnDelete();
            $t->foreignId('id_usuario_autor')->constrained('users')->restrictOnDelete();
            $t->timestampTz('fecha_creacion')->useCurrent();
            $t->unique(['id_version_rubrica', 'id_usuario_autor'], 'autor_version_rubrica_unico');
        });
        Schema::create('habilitaciones_revision_rubricas', function (Blueprint $t): void {
            $t->id('id_habilitacion_rubrica');
            $t->foreignId('id_version_rubrica')->constrained('versiones_rubricas_evaluacion', 'id_version_rubrica')->restrictOnDelete();
            $t->foreignId('id_rubrica')->constrained('rubricas', 'id_rubrica')->restrictOnDelete();
            $t->foreignId('id_usuario_revisor')->constrained('users')->restrictOnDelete();
            $t->foreignId('id_usuario_habilitador')->constrained('users')->restrictOnDelete();
            $t->text('alcance');
            $t->string('referencia_acreditacion', 500);
            $t->string('huella_acreditacion', 64);
            $t->boolean('acreditacion_verificada');
            $t->timestampTz('fecha_vencimiento');
            $t->timestampTz('fecha_creacion')->useCurrent();
            $t->unique(['id_habilitacion_rubrica', 'id_rubrica', 'id_usuario_revisor'], 'habilitacion_rubrica_ambito_unico');
            $t->index(['id_rubrica', 'id_usuario_revisor'], 'habilitacion_rubrica_consulta');
        });
        Schema::create('retiros_habilitacion_rubricas', function (Blueprint $t): void {
            $t->id('id_retiro_habilitacion_rubrica');
            $t->foreignId('id_habilitacion_rubrica')->unique()->constrained('habilitaciones_revision_rubricas', 'id_habilitacion_rubrica')->restrictOnDelete();
            $t->foreignId('id_usuario_responsable')->constrained('users')->restrictOnDelete();
            $t->text('motivo');
            $t->timestampTz('fecha_creacion')->useCurrent();
        });
        Schema::create('revisiones_rubricas_evaluacion', function (Blueprint $t): void {
            $t->id('id_revision_rubrica');
            $t->unsignedBigInteger('id_version_rubrica');
            $t->unsignedBigInteger('id_rubrica');
            $t->unsignedBigInteger('id_habilitacion_rubrica');
            $t->unsignedBigInteger('id_usuario_revisor');
            $t->foreign('id_rubrica')->references('id_rubrica')->on('rubricas')->restrictOnDelete();
            $t->foreign(['id_version_rubrica', 'id_rubrica'], 'revision_rubrica_version_fk')->references(['id_version_rubrica', 'id_rubrica'])->on('versiones_rubricas_evaluacion')->restrictOnDelete();
            $t->foreign(['id_habilitacion_rubrica', 'id_rubrica', 'id_usuario_revisor'], 'revision_rubrica_habilitacion_fk')->references(['id_habilitacion_rubrica', 'id_rubrica', 'id_usuario_revisor'])->on('habilitaciones_revision_rubricas')->restrictOnDelete();
            $t->foreignId('id_revision_previa')->nullable()->constrained('revisiones_rubricas_evaluacion', 'id_revision_rubrica')->restrictOnDelete();
            $t->string('huella_version_revisada', 64);
            $t->string('decision', 30);
            $t->text('observacion');
            $t->jsonb('fuentes_verificadas');
            $t->date('vigencia_comprobada_en');
            $t->boolean('confirmacion_humana');
            $t->boolean('autoria_revisada');
            $t->timestampTz('fecha_vencimiento')->nullable();
            $t->timestampTz('fecha_creacion')->useCurrent();
            $t->index(['id_version_rubrica', 'id_revision_rubrica'], 'revision_rubrica_consulta');
        });
        Schema::create('retiros_revision_rubricas', function (Blueprint $t): void {
            $t->id('id_retiro_revision_rubrica');
            $t->foreignId('id_revision_rubrica')->unique()->constrained('revisiones_rubricas_evaluacion', 'id_revision_rubrica')->restrictOnDelete();
            $t->foreignId('id_usuario_responsable')->constrained('users')->restrictOnDelete();
            $t->text('motivo');
            $t->timestampTz('fecha_creacion')->useCurrent();
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION jurissim_texto_rubrica_valido(texto text, maximo integer) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
                SELECT COALESCE(char_length(texto) BETWEEN 1 AND maximo
                    AND regexp_replace(texto,'[[:space:]' || U&'\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000' || ']','','g')<>'',false);
            $$;
            SQL);
        foreach (array_diff(self::TABLAS, ['autores_version_rubrica']) as $tabla) {
            $campo = str_starts_with($tabla, 'habilitaciones') ? 'alcance' : (str_starts_with($tabla, 'revisiones') ? 'observacion' : 'motivo');
            $max = $campo === 'observacion' ? 5000 : 2000;
            DB::statement("ALTER TABLE {$tabla} ADD CHECK (jurissim_texto_rubrica_valido({$campo},{$max}))");
        }
        DB::statement("ALTER TABLE versiones_rubricas_evaluacion ADD CHECK (numero_version>0 AND confirmacion_autoria_declarada AND huella_contenido ~ '^[0-9a-f]{64}$' AND octet_length(contenido::text)<=100000)");
        DB::statement("ALTER TABLE habilitaciones_revision_rubricas ADD CHECK (id_usuario_revisor<>id_usuario_habilitador AND acreditacion_verificada AND fecha_vencimiento>fecha_creacion AND huella_acreditacion ~ '^[0-9a-f]{64}$' AND jurissim_texto_rubrica_valido(referencia_acreditacion,500))");
        DB::statement(<<<'SQL'
            ALTER TABLE revisiones_rubricas_evaluacion ADD CHECK (
                confirmacion_humana AND autoria_revisada AND huella_version_revisada ~ '^[0-9a-f]{64}$'
                AND vigencia_comprobada_en <= (fecha_creacion AT TIME ZONE 'America/La_Paz')::date
                AND ((decision='aprobado' AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento>fecha_creacion)
                    OR (decision='requiere_cambios' AND fecha_vencimiento IS NULL)))
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION jurissim_capturar_rubrica(identificador bigint) RETURNS jsonb LANGUAGE sql STABLE AS $$
                SELECT jsonb_build_object('formato',1,'rubrica',jsonb_build_object('id_rubrica',r.id_rubrica,
                    'nombre',r.nombre,'descripcion',r.descripcion,'version_catalogo',r.version,
                    'id_tipo_audiencia',r.id_tipo_audiencia,'rol_aplicable',r.rol_aplicable),
                    'criterios',COALESCE((SELECT jsonb_agg(jsonb_build_object('id_criterio',c.id_criterio,
                        'nombre',c.nombre,'descripcion',c.descripcion,'peso',c.peso,
                        'puntaje_maximo',c.puntaje_maximo,'orden',c.orden) ORDER BY c.orden,c.id_criterio)
                        FROM criterios_rubrica c WHERE c.id_rubrica=r.id_rubrica),'[]'::jsonb))
                FROM rubricas r WHERE r.id_rubrica=identificador;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_huella_rubrica(c jsonb, autores jsonb, preparador bigint) RETURNS text LANGUAGE sql IMMUTABLE AS $$
                SELECT encode(sha256(convert_to(jsonb_build_object('contenido',c,'ids_autores_declarados',autores,'id_usuario_preparador',preparador)::text,'UTF8')),'hex');
            $$;
            CREATE OR REPLACE FUNCTION jurissim_validar_copia_rubrica(c jsonb) RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $$
            DECLARE criterio jsonb; suma numeric := 0; caracteres integer := 0; orden_anterior integer := 0;
            BEGIN
                IF c IS NULL OR jsonb_typeof(c)<>'object' OR c->'formato' IS DISTINCT FROM '1'::jsonb
                    OR c-ARRAY['formato','rubrica','criterios']<> '{}'::jsonb
                    OR jsonb_typeof(c->'rubrica') IS DISTINCT FROM 'object'
                    OR (c->'rubrica')-ARRAY['id_rubrica','nombre','descripcion','version_catalogo','id_tipo_audiencia','rol_aplicable']<> '{}'::jsonb
                    OR NOT (c->'rubrica') ? 'descripcion'
                    OR jsonb_typeof(c->'criterios') IS DISTINCT FROM 'array'
                    OR c#>>'{rubrica,rol_aplicable}' IS DISTINCT FROM 'abogado_defensor'
                    OR c#>>'{rubrica,id_tipo_audiencia}' IS NULL
                    OR COALESCE((c#>>'{rubrica,version_catalogo}')::integer,0)<1
                    OR COALESCE((c#>>'{rubrica,id_rubrica}')::bigint,0)<1
                    OR COALESCE((c#>>'{rubrica,id_tipo_audiencia}')::bigint,0)<1
                    OR jsonb_typeof(c#>'{rubrica,id_rubrica}') IS DISTINCT FROM 'number'
                    OR jsonb_typeof(c#>'{rubrica,id_tipo_audiencia}') IS DISTINCT FROM 'number'
                    OR jsonb_typeof(c#>'{rubrica,version_catalogo}') IS DISTINCT FROM 'number'
                    OR jsonb_typeof(c#>'{rubrica,nombre}') IS DISTINCT FROM 'string'
                    OR NOT jurissim_texto_rubrica_valido(c#>>'{rubrica,nombre}',255)
                    OR octet_length(c::text)>100000 THEN RETURN false; END IF;
                IF jsonb_array_length(c->'criterios') NOT BETWEEN 1 AND 20 THEN RETURN false; END IF;
                IF (SELECT count(DISTINCT e->'id_criterio') FROM jsonb_array_elements(c->'criterios') e)<>jsonb_array_length(c->'criterios') THEN RETURN false; END IF;
                FOR criterio IN SELECT e FROM jsonb_array_elements(c->'criterios') e LOOP
                    IF jsonb_typeof(criterio) IS DISTINCT FROM 'object'
                        OR criterio-ARRAY['id_criterio','nombre','descripcion','peso','puntaje_maximo','orden']<> '{}'::jsonb
                        OR COALESCE((criterio->>'id_criterio')::bigint,0)<1
                        OR COALESCE((criterio->>'orden')::integer,0)<=orden_anterior
                        OR jsonb_typeof(criterio->'id_criterio') IS DISTINCT FROM 'number'
                        OR jsonb_typeof(criterio->'orden') IS DISTINCT FROM 'number'
                        OR jsonb_typeof(criterio->'nombre') IS DISTINCT FROM 'string'
                        OR jsonb_typeof(criterio->'descripcion') IS DISTINCT FROM 'string'
                        OR jsonb_typeof(criterio->'peso') IS DISTINCT FROM 'number'
                        OR jsonb_typeof(criterio->'puntaje_maximo') IS DISTINCT FROM 'number'
                        OR NOT jurissim_texto_rubrica_valido(criterio->>'nombre',255)
                        OR NOT jurissim_texto_rubrica_valido(criterio->>'descripcion',2000)
                        OR (criterio->>'peso')::numeric<=0 OR (criterio->>'puntaje_maximo')::numeric<=0
                        OR criterio->>'peso' IN ('NaN','Infinity','-Infinity')
                        OR criterio->>'puntaje_maximo' IN ('NaN','Infinity','-Infinity') THEN RETURN false; END IF;
                    orden_anterior := (criterio->>'orden')::integer;
                    suma := suma+(criterio->>'peso')::numeric;
                    caracteres := caracteres+char_length(criterio->>'nombre')+char_length(criterio->>'descripcion');
                END LOOP;
                RETURN COALESCE(suma>0 AND caracteres<=20000,false);
            EXCEPTION WHEN OTHERS THEN RETURN false;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_gobernar_rubrica() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v versiones_rubricas_evaluacion; h habilitaciones_revision_rubricas;
                r revisiones_rubricas_evaluacion; ultimo bigint; identificador bigint; autor jsonb;
            BEGIN
                IF current_setting('transaction_isolation')<>'read committed' THEN
                    RAISE EXCEPTION 'El gobierno de rubricas exige read committed.' USING ERRCODE='23514';
                END IF;
                IF TG_TABLE_NAME IN ('versiones_rubricas_evaluacion','habilitaciones_revision_rubricas','revisiones_rubricas_evaluacion') THEN
                    identificador := NEW.id_rubrica;
                ELSIF TG_TABLE_NAME='retiros_habilitacion_rubricas' THEN
                    SELECT * INTO h FROM habilitaciones_revision_rubricas WHERE id_habilitacion_rubrica=NEW.id_habilitacion_rubrica;
                    identificador := h.id_rubrica;
                ELSE
                    SELECT * INTO r FROM revisiones_rubricas_evaluacion WHERE id_revision_rubrica=NEW.id_revision_rubrica;
                    identificador := r.id_rubrica;
                END IF;
                PERFORM 1 FROM rubricas WHERE id_rubrica=identificador FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'Rubrica inexistente.' USING ERRCODE='23514'; END IF;
                NEW.fecha_creacion := clock_timestamp();
                IF TG_TABLE_NAME='versiones_rubricas_evaluacion' THEN
                    IF NOT jurissim_es_admin_revision(NEW.id_usuario_preparador) THEN
                        RAISE EXCEPTION 'Preparador no autorizado.' USING ERRCODE='42501';
                    END IF;
                    IF jsonb_typeof(NEW.ids_autores_declarados) IS DISTINCT FROM 'array' THEN
                        RAISE EXCEPTION 'Declare autoria completa.' USING ERRCODE='23514';
                    END IF;
                    IF jsonb_array_length(NEW.ids_autores_declarados) NOT BETWEEN 1 AND 10
                        OR (SELECT count(DISTINCT e) FROM jsonb_array_elements(NEW.ids_autores_declarados) e)<>jsonb_array_length(NEW.ids_autores_declarados) THEN
                        RAISE EXCEPTION 'Autoria incompatible.' USING ERRCODE='23514';
                    END IF;
                    FOR autor IN SELECT e FROM jsonb_array_elements(NEW.ids_autores_declarados) e LOOP
                        IF jsonb_typeof(autor)<>'number' OR autor::text !~ '^[1-9][0-9]{0,17}$'
                            OR NOT EXISTS (SELECT 1 FROM users WHERE id=autor::text::bigint) THEN
                            RAISE EXCEPTION 'Autoria incompatible.' USING ERRCODE='23514';
                        END IF;
                    END LOOP;
                    NEW.contenido := jurissim_capturar_rubrica(NEW.id_rubrica);
                    IF NOT jurissim_validar_copia_rubrica(NEW.contenido) THEN
                        RAISE EXCEPTION 'Rubrica fuera del contrato de defensa.' USING ERRCODE='23514';
                    END IF;
                    NEW.id_tipo_audiencia := (NEW.contenido#>>'{rubrica,id_tipo_audiencia}')::bigint;
                    NEW.numero_version := COALESCE((SELECT max(numero_version) FROM versiones_rubricas_evaluacion WHERE id_rubrica=NEW.id_rubrica),0)+1;
                    NEW.huella_contenido := jurissim_huella_rubrica(NEW.contenido,NEW.ids_autores_declarados,NEW.id_usuario_preparador);
                ELSIF TG_TABLE_NAME='habilitaciones_revision_rubricas' THEN
                    SELECT * INTO v FROM versiones_rubricas_evaluacion WHERE id_version_rubrica=NEW.id_version_rubrica;
                    IF v.id_rubrica IS DISTINCT FROM NEW.id_rubrica THEN
                        RAISE EXCEPTION 'Habilitacion fuera de la copia declarada.' USING ERRCODE='23514';
                    END IF;
                    IF NOT jurissim_es_admin_revision(NEW.id_usuario_habilitador) THEN
                        RAISE EXCEPTION 'Habilitador no autorizado.' USING ERRCODE='42501';
                    END IF;
                    IF EXISTS (SELECT 1 FROM habilitaciones_revision_rubricas a WHERE a.id_version_rubrica=NEW.id_version_rubrica
                        AND a.id_usuario_revisor=NEW.id_usuario_revisor AND a.fecha_vencimiento>clock_timestamp()
                        AND NOT EXISTS (SELECT 1 FROM retiros_habilitacion_rubricas x WHERE x.id_habilitacion_rubrica=a.id_habilitacion_rubrica)) THEN
                        RAISE EXCEPTION 'Ya existe una habilitacion vigente.' USING ERRCODE='23514';
                    END IF;
                ELSIF TG_TABLE_NAME='revisiones_rubricas_evaluacion' THEN
                    SELECT * INTO v FROM versiones_rubricas_evaluacion WHERE id_version_rubrica=NEW.id_version_rubrica;
                    SELECT * INTO h FROM habilitaciones_revision_rubricas WHERE id_habilitacion_rubrica=NEW.id_habilitacion_rubrica;
                    IF v.id_rubrica IS DISTINCT FROM NEW.id_rubrica OR h.id_rubrica IS DISTINCT FROM NEW.id_rubrica
                        OR h.id_version_rubrica IS DISTINCT FROM NEW.id_version_rubrica
                        OR h.id_usuario_revisor IS DISTINCT FROM NEW.id_usuario_revisor OR h.fecha_vencimiento<=clock_timestamp()
                        OR EXISTS (SELECT 1 FROM retiros_habilitacion_rubricas WHERE id_habilitacion_rubrica=h.id_habilitacion_rubrica)
                        OR NEW.id_usuario_revisor=v.id_usuario_preparador
                        OR v.ids_autores_declarados @> jsonb_build_array(NEW.id_usuario_revisor)
                        OR NEW.huella_version_revisada IS DISTINCT FROM v.huella_contenido
                        OR v.huella_contenido IS DISTINCT FROM jurissim_huella_rubrica(v.contenido,v.ids_autores_declarados,v.id_usuario_preparador)
                        OR (v.contenido#>>'{rubrica,id_rubrica}')::bigint IS DISTINCT FROM v.id_rubrica
                        OR (v.contenido#>>'{rubrica,id_tipo_audiencia}')::bigint IS DISTINCT FROM v.id_tipo_audiencia
                        OR NOT jurissim_validar_copia_rubrica(v.contenido) THEN
                        RAISE EXCEPTION 'Revision sin ambito, independencia o vigencia.' USING ERRCODE='23514';
                    END IF;
                    SELECT id_revision_rubrica INTO ultimo FROM revisiones_rubricas_evaluacion WHERE id_version_rubrica=NEW.id_version_rubrica ORDER BY id_revision_rubrica DESC LIMIT 1;
                    IF NEW.id_revision_previa IS DISTINCT FROM ultimo THEN
                        RAISE EXCEPTION 'Revision previa obsoleta.' USING ERRCODE='23514';
                    END IF;
                    IF NEW.fecha_vencimiento>h.fecha_vencimiento OR NEW.vigencia_comprobada_en>timezone('America/La_Paz',clock_timestamp())::date
                        OR jsonb_typeof(NEW.fuentes_verificadas) IS DISTINCT FROM 'array' THEN
                        RAISE EXCEPTION 'Evidencia o vigencia incompatible.' USING ERRCODE='23514';
                    END IF;
                    IF jsonb_array_length(NEW.fuentes_verificadas) NOT BETWEEN 1 AND 20 THEN
                        RAISE EXCEPTION 'Declare fuentes verificadas.' USING ERRCODE='23514';
                    END IF;
                    FOR autor IN SELECT e FROM jsonb_array_elements(NEW.fuentes_verificadas) e LOOP
                        IF jsonb_typeof(autor) IS DISTINCT FROM 'object' OR (SELECT count(*) FROM jsonb_object_keys(autor))<>2
                            OR jsonb_typeof(autor->'referencia') IS DISTINCT FROM 'string'
                            OR NOT jurissim_texto_rubrica_valido(autor->>'referencia',500)
                            OR jsonb_typeof(autor->'huella_contenido') IS DISTINCT FROM 'string'
                            OR (autor->>'huella_contenido') !~ '^[0-9a-f]{64}$' THEN
                            RAISE EXCEPTION 'Fuente incompatible.' USING ERRCODE='23514';
                        END IF;
                    END LOOP;
                ELSE
                    IF TG_TABLE_NAME='retiros_habilitacion_rubricas' THEN
                        identificador := h.id_usuario_revisor;
                    ELSE
                        identificador := r.id_usuario_revisor;
                    END IF;
                    IF NEW.id_usuario_responsable IS DISTINCT FROM identificador AND NOT jurissim_es_admin_revision(NEW.id_usuario_responsable) THEN
                        RAISE EXCEPTION 'Retiro no autorizado.' USING ERRCODE='42501';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_estado_revision_rubrica(identificador bigint) RETURNS text LANGUAGE sql VOLATILE AS $$
                SELECT CASE WHEN v.id_version_rubrica IS NULL THEN 'sin_version'
                    WHEN NOT jurissim_validar_copia_rubrica(v.contenido)
                        OR (v.contenido#>>'{rubrica,id_rubrica}')::bigint IS DISTINCT FROM v.id_rubrica
                        OR (v.contenido#>>'{rubrica,id_tipo_audiencia}')::bigint IS DISTINCT FROM v.id_tipo_audiencia
                        OR v.huella_contenido IS DISTINCT FROM jurissim_huella_rubrica(v.contenido,v.ids_autores_declarados,v.id_usuario_preparador) THEN 'integridad_incompatible'
                    WHEN r.id_revision_rubrica IS NULL THEN 'no_acreditada'
                    WHEN r.huella_version_revisada IS DISTINCT FROM v.huella_contenido
                        OR h.id_version_rubrica IS DISTINCT FROM v.id_version_rubrica
                        OR r.id_usuario_revisor=v.id_usuario_preparador
                        OR v.ids_autores_declarados @> jsonb_build_array(r.id_usuario_revisor) THEN 'integridad_incompatible'
                    WHEN EXISTS (SELECT 1 FROM retiros_revision_rubricas x WHERE x.id_revision_rubrica=r.id_revision_rubrica) THEN 'revision_retirada'
                    WHEN EXISTS (SELECT 1 FROM retiros_habilitacion_rubricas x WHERE x.id_habilitacion_rubrica=r.id_habilitacion_rubrica) THEN 'habilitacion_retirada'
                    WHEN h.fecha_vencimiento<=clock_timestamp() THEN 'habilitacion_vencida'
                    WHEN r.decision='requiere_cambios' THEN 'requiere_cambios'
                    WHEN r.fecha_vencimiento<=clock_timestamp() THEN 'revision_vencida'
                    ELSE 'aprobado' END
                FROM (SELECT identificador id) entrada
                LEFT JOIN versiones_rubricas_evaluacion v ON v.id_version_rubrica=entrada.id
                LEFT JOIN LATERAL (SELECT * FROM revisiones_rubricas_evaluacion WHERE id_version_rubrica=v.id_version_rubrica ORDER BY id_revision_rubrica DESC LIMIT 1) r ON true
                LEFT JOIN habilitaciones_revision_rubricas h ON h.id_habilitacion_rubrica=r.id_habilitacion_rubrica;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_vincular_autores_rubrica() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO autores_version_rubrica (id_version_rubrica,id_usuario_autor,fecha_creacion)
                    SELECT NEW.id_version_rubrica,e::text::bigint,NEW.fecha_creacion FROM jsonb_array_elements(NEW.ids_autores_declarados) e;
                RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION jurissim_validar_autor_rubrica() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v versiones_rubricas_evaluacion;
            BEGIN
                SELECT * INTO v FROM versiones_rubricas_evaluacion WHERE id_version_rubrica=NEW.id_version_rubrica;
                IF v.id_version_rubrica IS NULL OR NOT v.ids_autores_declarados @> jsonb_build_array(NEW.id_usuario_autor) THEN
                    RAISE EXCEPTION 'Autor no declarado en la copia.' USING ERRCODE='23514';
                END IF;
                NEW.fecha_creacion := v.fecha_creacion;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER vincular_autores_rubrica AFTER INSERT ON versiones_rubricas_evaluacion
                FOR EACH ROW EXECUTE FUNCTION jurissim_vincular_autores_rubrica();
            CREATE TRIGGER validar_autor_rubrica BEFORE INSERT ON autores_version_rubrica
                FOR EACH ROW EXECUTE FUNCTION jurissim_validar_autor_rubrica();
            SQL);
        foreach (self::TABLAS as $tabla) {
            if ($tabla !== 'autores_version_rubrica') {
                DB::statement("CREATE TRIGGER gobernar_rubrica BEFORE INSERT ON {$tabla} FOR EACH ROW EXECUTE FUNCTION jurissim_gobernar_rubrica()");
            }
            DB::statement("CREATE TRIGGER rubrica_inmutable BEFORE UPDATE OR DELETE ON {$tabla} FOR EACH ROW EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica()");
            DB::statement("CREATE TRIGGER rubrica_no_truncar BEFORE TRUNCATE ON {$tabla} FOR EACH STATEMENT EXECUTE FUNCTION jurissim_impedir_cambio_revision_juridica()");
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (array_reverse(self::TABLAS) as $tabla) {
                DB::statement("LOCK TABLE {$tabla} IN ACCESS EXCLUSIVE MODE");
            }
            foreach (self::TABLAS as $tabla) {
                if (DB::table($tabla)->exists()) {
                    throw new RuntimeException('Hay registros de rubricas que deben preservarse; no se revierte esta migracion.');
                }
            }
            foreach (self::TABLAS as $tabla) {
                Schema::drop($tabla);
            }
            DB::statement('DROP FUNCTION jurissim_validar_autor_rubrica()');
            DB::statement('DROP FUNCTION jurissim_vincular_autores_rubrica()');
            DB::statement('DROP FUNCTION jurissim_estado_revision_rubrica(bigint)');
            DB::statement('DROP FUNCTION jurissim_gobernar_rubrica()');
            DB::statement('DROP FUNCTION jurissim_validar_copia_rubrica(jsonb)');
            DB::statement('DROP FUNCTION jurissim_huella_rubrica(jsonb,jsonb,bigint)');
            DB::statement('DROP FUNCTION jurissim_capturar_rubrica(bigint)');
            DB::statement('DROP FUNCTION jurissim_texto_rubrica_valido(text,integer)');
        });
    }
};
