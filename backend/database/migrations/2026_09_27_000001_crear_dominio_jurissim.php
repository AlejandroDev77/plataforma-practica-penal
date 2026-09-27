<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('El dominio JURISSIM requiere PostgreSQL. Configure DB_CONNECTION=pgsql.');
        }

        Schema::create('expedientes', function (Blueprint $table) {
            $table->id('id_expediente');
            $table->foreignId('id_usuario')->constrained('users', 'id')->cascadeOnDelete();
            $table->index('id_usuario');
            $table->string('titulo', 255);
            $table->text('descripcion')->nullable();
            $table->string('numero_caso', 100)->nullable();
            $table->string('estado', 40)->default('borrador');
            $table->string('estado_procesamiento', 40)->default('pendiente');
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->unique(['id_expediente', 'id_usuario'], 'expedientes_propietario_unico');
            $table->index(['id_usuario', 'estado']);
            $table->index('estado_procesamiento');
        });

        Schema::create('archivos_expediente', function (Blueprint $table) {
            $table->id('id_archivo');
            $table->foreignId('id_expediente')->constrained('expedientes', 'id_expediente')->cascadeOnDelete();
            $table->index('id_expediente');
            $table->string('nombre_original', 255);
            $table->string('nombre_almacenado', 255);
            $table->string('tipo_mime', 150);
            $table->string('extension', 20);
            $table->string('disco', 50)->default('local');
            $table->string('ruta_almacenamiento', 1024);
            $table->bigInteger('tamano_bytes');
            $table->integer('cantidad_paginas')->nullable();
            $table->boolean('requiere_ocr')->default(false);
            $table->string('estado_procesamiento', 40)->default('pendiente');
            $table->text('mensaje_error')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->unique(['disco', 'ruta_almacenamiento'], 'archivos_objeto_unico');
            $table->unique(['id_archivo', 'id_expediente'], 'archivos_expediente_unico');
            $table->index('estado_procesamiento');
        });

        Schema::create('paginas_expediente', function (Blueprint $table) {
            $table->id('id_pagina');
            $table->foreignId('id_archivo')->constrained('archivos_expediente', 'id_archivo')->cascadeOnDelete();
            $table->index('id_archivo');
            $table->integer('numero_pagina');
            $table->text('texto_extraido')->nullable();
            $table->boolean('uso_ocr')->default(false);
            $table->decimal('nivel_confianza', 5, 4)->nullable();
            $table->boolean('es_legible')->default(true);
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->unique(['id_archivo', 'numero_pagina'], 'paginas_numero_unico');
            $table->unique(['id_pagina', 'id_archivo'], 'paginas_archivo_unico');
        });

        Schema::create('analisis_expediente', function (Blueprint $table) {
            $table->id('id_analisis');
            $table->foreignId('id_expediente')->constrained('expedientes', 'id_expediente')->cascadeOnDelete();
            $table->index('id_expediente');
            $table->text('resumen')->nullable();
            $table->string('etapa_procesal', 100)->nullable();
            $table->string('estado', 40)->default('pendiente');
            $table->integer('version')->default(1);
            $table->jsonb('datos_estructurados')->nullable();
            $table->string('modelo_ia', 150)->nullable();
            $table->timestampTz('fecha_analisis')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->unique(['id_expediente', 'version'], 'analisis_version_unica');
            $table->unique(['id_analisis', 'id_expediente'], 'analisis_expediente_unico');
            $table->index('estado');
        });

        Schema::create('participantes_expediente', function (Blueprint $table) {
            $table->id('id_participante');
            $table->foreignId('id_expediente')->constrained('expedientes', 'id_expediente')->cascadeOnDelete();
            $table->index('id_expediente');
            $table->unsignedBigInteger('id_analisis');
            $table->string('nombre', 255);
            $table->string('tipo_participante', 80);
            $table->text('descripcion')->nullable();
            $table->boolean('confirmado')->default(false);
            $table->decimal('nivel_confianza', 5, 4)->nullable();
            $table->jsonb('datos_adicionales')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->foreign(['id_analisis', 'id_expediente'], 'participantes_expediente_analisis_fk')->references(['id_analisis', 'id_expediente'])->on('analisis_expediente')->cascadeOnDelete();
            $table->index('id_analisis');
            $table->unique(['id_participante', 'id_analisis', 'id_expediente'], 'participantes_expediente_contexto_unico');
        });

        Schema::create('delitos_expediente', function (Blueprint $table) {
            $table->id('id_delito');
            $table->foreignId('id_expediente')->constrained('expedientes', 'id_expediente')->cascadeOnDelete();
            $table->index('id_expediente');
            $table->unsignedBigInteger('id_analisis');
            $table->string('nombre_delito', 255);
            $table->string('articulo_referido', 150)->nullable();
            $table->text('descripcion')->nullable();
            $table->boolean('confirmado')->default(false);
            $table->decimal('nivel_confianza', 5, 4)->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->foreign(['id_analisis', 'id_expediente'], 'delitos_expediente_analisis_fk')->references(['id_analisis', 'id_expediente'])->on('analisis_expediente')->cascadeOnDelete();
            $table->index('id_analisis');
            $table->unique(['id_delito', 'id_analisis', 'id_expediente'], 'delitos_expediente_contexto_unico');
        });

        Schema::create('hechos_expediente', function (Blueprint $table) {
            $table->id('id_hecho');
            $table->foreignId('id_expediente')->constrained('expedientes', 'id_expediente')->cascadeOnDelete();
            $table->index('id_expediente');
            $table->unsignedBigInteger('id_analisis');
            $table->text('descripcion');
            $table->string('tipo_hecho', 80)->nullable();
            $table->date('fecha_hecho')->nullable();
            $table->integer('orden_cronologico')->nullable();
            $table->boolean('confirmado')->default(false);
            $table->decimal('nivel_confianza', 5, 4)->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->foreign(['id_analisis', 'id_expediente'], 'hechos_expediente_analisis_fk')->references(['id_analisis', 'id_expediente'])->on('analisis_expediente')->cascadeOnDelete();
            $table->index('id_analisis');
            $table->unique(['id_hecho', 'id_analisis', 'id_expediente'], 'hechos_expediente_contexto_unico');
        });

        Schema::create('pruebas_expediente', function (Blueprint $table) {
            $table->id('id_prueba');
            $table->foreignId('id_expediente')->constrained('expedientes', 'id_expediente')->cascadeOnDelete();
            $table->index('id_expediente');
            $table->unsignedBigInteger('id_analisis');
            $table->string('nombre', 255);
            $table->string('tipo_prueba', 80);
            $table->text('descripcion')->nullable();
            $table->string('estado', 40)->nullable();
            $table->boolean('confirmado')->default(false);
            $table->decimal('nivel_confianza', 5, 4)->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->foreign(['id_analisis', 'id_expediente'], 'pruebas_expediente_analisis_fk')->references(['id_analisis', 'id_expediente'])->on('analisis_expediente')->cascadeOnDelete();
            $table->index('id_analisis');
            $table->unique(['id_prueba', 'id_analisis', 'id_expediente'], 'pruebas_expediente_contexto_unico');
        });

        Schema::create('cronologia_expediente', function (Blueprint $table) {
            $table->id('id_evento');
            $table->foreignId('id_expediente')->constrained('expedientes', 'id_expediente')->cascadeOnDelete();
            $table->index('id_expediente');
            $table->unsignedBigInteger('id_analisis');
            $table->date('fecha_evento')->nullable();
            $table->string('titulo', 255);
            $table->text('descripcion');
            $table->integer('orden');
            $table->decimal('nivel_confianza', 5, 4)->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->foreign(['id_analisis', 'id_expediente'], 'cronologia_expediente_analisis_fk')->references(['id_analisis', 'id_expediente'])->on('analisis_expediente')->cascadeOnDelete();
            $table->index('id_analisis');
            $table->unique(['id_evento', 'id_analisis', 'id_expediente'], 'cronologia_expediente_contexto_unico');
        });

        Schema::create('incidencias_analisis', function (Blueprint $table) {
            $table->id('id_incidencia');
            $table->foreignId('id_expediente')->constrained('expedientes', 'id_expediente')->cascadeOnDelete();
            $table->index('id_expediente');
            $table->unsignedBigInteger('id_analisis');
            $table->string('tipo', 80);
            $table->text('descripcion');
            $table->string('gravedad', 40)->default('media');
            $table->boolean('resuelta')->default(false);
            $table->jsonb('datos_adicionales')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->foreign(['id_analisis', 'id_expediente'], 'incidencias_analisis_analisis_fk')->references(['id_analisis', 'id_expediente'])->on('analisis_expediente')->cascadeOnDelete();
            $table->index('id_analisis');
            $table->unique(['id_incidencia', 'id_analisis', 'id_expediente'], 'incidencias_analisis_contexto_unico');
        });

        Schema::create('referencias_expediente', function (Blueprint $table) {
            $table->id('id_referencia');
            $table->foreignId('id_expediente')->constrained('expedientes', 'id_expediente')->cascadeOnDelete();
            $table->index('id_expediente');
            $table->unsignedBigInteger('id_analisis');
            $table->unsignedBigInteger('id_archivo');
            $table->unsignedBigInteger('id_pagina')->nullable();
            $table->unsignedBigInteger('id_participante')->nullable();
            $table->unsignedBigInteger('id_delito')->nullable();
            $table->unsignedBigInteger('id_hecho')->nullable();
            $table->unsignedBigInteger('id_prueba')->nullable();
            $table->unsignedBigInteger('id_evento')->nullable();
            $table->unsignedBigInteger('id_incidencia')->nullable();
            $table->text('texto_fuente')->nullable();
            $table->decimal('nivel_confianza', 5, 4)->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->foreign(['id_archivo', 'id_expediente'], 'referencias_archivo_fk')->references(['id_archivo', 'id_expediente'])->on('archivos_expediente')->cascadeOnDelete();
            $table->foreign(['id_pagina', 'id_archivo'], 'referencias_pagina_fk')->references(['id_pagina', 'id_archivo'])->on('paginas_expediente')->cascadeOnDelete();
            $table->foreign(['id_analisis', 'id_expediente'], 'referencias_analisis_fk')->references(['id_analisis', 'id_expediente'])->on('analisis_expediente')->cascadeOnDelete();
            $table->index('id_archivo');
            $table->index('id_pagina');
            $table->index('id_analisis');
            $table->foreign(['id_participante', 'id_analisis', 'id_expediente'], 'referencias_id_participante_fk')->references(['id_participante', 'id_analisis', 'id_expediente'])->on('participantes_expediente')->cascadeOnDelete();
            $table->index('id_participante');
            $table->foreign(['id_delito', 'id_analisis', 'id_expediente'], 'referencias_id_delito_fk')->references(['id_delito', 'id_analisis', 'id_expediente'])->on('delitos_expediente')->cascadeOnDelete();
            $table->index('id_delito');
            $table->foreign(['id_hecho', 'id_analisis', 'id_expediente'], 'referencias_id_hecho_fk')->references(['id_hecho', 'id_analisis', 'id_expediente'])->on('hechos_expediente')->cascadeOnDelete();
            $table->index('id_hecho');
            $table->foreign(['id_prueba', 'id_analisis', 'id_expediente'], 'referencias_id_prueba_fk')->references(['id_prueba', 'id_analisis', 'id_expediente'])->on('pruebas_expediente')->cascadeOnDelete();
            $table->index('id_prueba');
            $table->foreign(['id_evento', 'id_analisis', 'id_expediente'], 'referencias_id_evento_fk')->references(['id_evento', 'id_analisis', 'id_expediente'])->on('cronologia_expediente')->cascadeOnDelete();
            $table->index('id_evento');
            $table->foreign(['id_incidencia', 'id_analisis', 'id_expediente'], 'referencias_id_incidencia_fk')->references(['id_incidencia', 'id_analisis', 'id_expediente'])->on('incidencias_analisis')->cascadeOnDelete();
            $table->index('id_incidencia');
        });

        Schema::create('historial_procesamiento', function (Blueprint $table) {
            $table->id('id_proceso');
            $table->foreignId('id_expediente')->constrained('expedientes', 'id_expediente')->cascadeOnDelete();
            $table->index('id_expediente');
            $table->unsignedBigInteger('id_archivo')->nullable();
            $table->unsignedBigInteger('id_analisis')->nullable();
            $table->string('tipo', 80);
            $table->string('estado', 40)->default('pendiente');
            $table->integer('intento')->default(1);
            $table->text('mensaje_error')->nullable();
            $table->jsonb('metadatos')->nullable();
            $table->timestampTz('fecha_inicio')->nullable();
            $table->timestampTz('fecha_fin')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->foreign(['id_archivo', 'id_expediente'], 'historial_archivo_fk')->references(['id_archivo', 'id_expediente'])->on('archivos_expediente')->cascadeOnDelete();
            $table->foreign(['id_analisis', 'id_expediente'], 'historial_analisis_fk')->references(['id_analisis', 'id_expediente'])->on('analisis_expediente')->cascadeOnDelete();
            $table->index('id_archivo');
            $table->index('id_analisis');
            $table->index(['id_expediente', 'estado']);
        });

        Schema::create('fuentes_juridicas', function (Blueprint $table) {
            $table->id('id_fuente');
            $table->string('titulo', 255);
            $table->string('tipo_fuente', 80);
            $table->string('numero_norma', 100)->nullable();
            $table->string('version', 100)->nullable();
            $table->date('fecha_vigencia')->nullable();
            $table->date('fecha_fin_vigencia')->nullable();
            $table->text('contenido_original')->nullable();
            $table->string('disco', 50)->nullable();
            $table->string('ruta_archivo', 1024)->nullable();
            $table->string('estado', 40)->default('borrador');
            $table->boolean('validada')->default(false);
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->index(['tipo_fuente', 'estado']);
        });

        Schema::create('fragmentos_documento', function (Blueprint $table) {
            $table->id('id_fragmento');
            $table->foreignId('id_archivo')->nullable()->constrained('archivos_expediente', 'id_archivo')->cascadeOnDelete();
            $table->index('id_archivo');
            $table->foreignId('id_fuente_juridica')->nullable()->constrained('fuentes_juridicas', 'id_fuente')->cascadeOnDelete();
            $table->index('id_fuente_juridica');
            $table->unsignedBigInteger('id_pagina')->nullable();
            $table->text('contenido');
            $table->integer('numero_pagina')->nullable();
            $table->integer('indice_fragmento');
            $table->integer('cantidad_tokens')->nullable();
            $table->jsonb('metadatos')->nullable();
            $table->string('modelo_embedding', 150)->nullable();
            $table->integer('dimensiones_embedding')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->foreign(['id_pagina', 'id_archivo'], 'fragmentos_pagina_fk')->references(['id_pagina', 'id_archivo'])->on('paginas_expediente')->cascadeOnDelete();
            $table->index('id_pagina');
            $table->unique(['id_archivo', 'indice_fragmento'], 'fragmentos_archivo_indice_unico');
            $table->unique(['id_fuente_juridica', 'indice_fragmento'], 'fragmentos_fuente_indice_unico');
        });

        Schema::create('tipos_audiencia', function (Blueprint $table) {
            $table->id('id_tipo_audiencia');
            $table->string('nombre', 255);
            $table->string('codigo', 80);
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(false);
            $table->integer('orden')->default(0);
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->unique('codigo');
        });

        Schema::create('etapas_audiencia', function (Blueprint $table) {
            $table->id('id_etapa');
            $table->foreignId('id_tipo_audiencia')->constrained('tipos_audiencia', 'id_tipo_audiencia')->restrictOnDelete();
            $table->index('id_tipo_audiencia');
            $table->string('codigo', 80);
            $table->string('nombre', 255);
            $table->text('descripcion')->nullable();
            $table->integer('orden');
            $table->boolean('es_inicial')->default(false);
            $table->boolean('es_final')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->unique(['id_tipo_audiencia', 'codigo'], 'etapas_codigo_unico');
            $table->unique(['id_tipo_audiencia', 'orden'], 'etapas_orden_unico');
            $table->unique(['id_etapa', 'id_tipo_audiencia'], 'etapas_tipo_unico');
        });

        Schema::create('transiciones_audiencia', function (Blueprint $table) {
            $table->id('id_transicion');
            $table->foreignId('id_tipo_audiencia')->constrained('tipos_audiencia', 'id_tipo_audiencia')->restrictOnDelete();
            $table->index('id_tipo_audiencia');
            $table->unsignedBigInteger('id_etapa_origen');
            $table->unsignedBigInteger('id_etapa_destino');
            $table->boolean('activo')->default(true);
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->foreign(['id_etapa_origen', 'id_tipo_audiencia'], 'transiciones_origen_fk')->references(['id_etapa', 'id_tipo_audiencia'])->on('etapas_audiencia')->cascadeOnDelete();
            $table->foreign(['id_etapa_destino', 'id_tipo_audiencia'], 'transiciones_destino_fk')->references(['id_etapa', 'id_tipo_audiencia'])->on('etapas_audiencia')->cascadeOnDelete();
            $table->unique(['id_etapa_origen', 'id_etapa_destino'], 'transiciones_par_unico');
            $table->index('id_etapa_destino');
        });

        Schema::create('simulaciones', function (Blueprint $table) {
            $table->id('id_simulacion');
            $table->foreignId('id_usuario')->constrained('users', 'id')->cascadeOnDelete();
            $table->index('id_usuario');
            $table->foreignId('id_expediente')->constrained('expedientes', 'id_expediente')->cascadeOnDelete();
            $table->index('id_expediente');
            $table->unsignedBigInteger('id_analisis');
            $table->foreignId('id_tipo_audiencia')->constrained('tipos_audiencia', 'id_tipo_audiencia')->restrictOnDelete();
            $table->index('id_tipo_audiencia');
            $table->unsignedBigInteger('id_etapa_actual')->nullable();
            $table->string('rol_usuario', 80)->default('abogado_defensor');
            $table->string('estado', 40)->default('preparando');
            $table->timestampTz('fecha_inicio')->nullable();
            $table->timestampTz('fecha_fin')->nullable();
            $table->jsonb('configuracion')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->foreign(['id_expediente', 'id_usuario'], 'simulaciones_propietario_fk')->references(['id_expediente', 'id_usuario'])->on('expedientes')->cascadeOnDelete();
            $table->foreign(['id_analisis', 'id_expediente'], 'simulaciones_analisis_fk')->references(['id_analisis', 'id_expediente'])->on('analisis_expediente')->noActionOnDelete()->deferrable()->initiallyImmediate(false);
            $table->foreign(['id_etapa_actual', 'id_tipo_audiencia'], 'simulaciones_etapa_fk')->references(['id_etapa', 'id_tipo_audiencia'])->on('etapas_audiencia')->restrictOnDelete();
            $table->index('id_analisis');
            $table->index('id_etapa_actual');
            $table->index(['id_usuario', 'estado']);
            $table->unique(['id_simulacion', 'id_analisis', 'id_expediente'], 'simulaciones_contexto_unico');
            $table->unique(['id_simulacion', 'id_tipo_audiencia'], 'simulaciones_tipo_unico');
        });

        Schema::create('participantes_simulacion', function (Blueprint $table) {
            $table->id('id_participante_simulacion');
            $table->foreignId('id_simulacion')->constrained('simulaciones', 'id_simulacion')->cascadeOnDelete();
            $table->index('id_simulacion');
            $table->unsignedBigInteger('id_analisis');
            $table->unsignedBigInteger('id_expediente');
            $table->unsignedBigInteger('id_participante_expediente')->nullable();
            $table->string('rol', 80);
            $table->string('nombre_mostrado', 255);
            $table->string('controlado_por', 20);
            $table->string('estado', 40)->default('activo');
            $table->jsonb('configuracion')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->foreign(['id_simulacion', 'id_analisis', 'id_expediente'], 'participantes_simulacion_contexto_fk')->references(['id_simulacion', 'id_analisis', 'id_expediente'])->on('simulaciones')->cascadeOnDelete();
            // La verificación al commit permite completar todas las rutas de cascada.
            $table->foreign(['id_participante_expediente', 'id_analisis', 'id_expediente'], 'participantes_simulacion_detectado_fk')->references(['id_participante', 'id_analisis', 'id_expediente'])->on('participantes_expediente')->noActionOnDelete()->deferrable()->initiallyImmediate(false);
            $table->index('id_participante_expediente');
            $table->unique(['id_participante_simulacion', 'id_simulacion'], 'participantes_simulacion_unico');
        });

        Schema::create('intervenciones', function (Blueprint $table) {
            $table->id('id_intervencion');
            $table->foreignId('id_simulacion')->constrained('simulaciones', 'id_simulacion')->cascadeOnDelete();
            $table->index('id_simulacion');
            $table->unsignedBigInteger('id_participante_simulacion');
            $table->unsignedBigInteger('id_tipo_audiencia');
            $table->unsignedBigInteger('id_etapa');
            $table->integer('orden');
            $table->text('contenido');
            $table->string('tipo_entrada', 20)->default('texto');
            $table->string('modelo_ia', 150)->nullable();
            $table->jsonb('metadatos')->nullable();
            $table->timestampTz('fecha_intervencion')->useCurrent();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->foreign(['id_participante_simulacion', 'id_simulacion'], 'intervenciones_participante_fk')->references(['id_participante_simulacion', 'id_simulacion'])->on('participantes_simulacion')->cascadeOnDelete();
            $table->foreign(['id_simulacion', 'id_tipo_audiencia'], 'intervenciones_simulacion_tipo_fk')->references(['id_simulacion', 'id_tipo_audiencia'])->on('simulaciones')->cascadeOnDelete();
            $table->foreign(['id_etapa', 'id_tipo_audiencia'], 'intervenciones_etapa_fk')->references(['id_etapa', 'id_tipo_audiencia'])->on('etapas_audiencia')->restrictOnDelete();
            $table->index('id_participante_simulacion');
            $table->index('id_etapa');
            $table->unique(['id_simulacion', 'orden'], 'intervenciones_orden_unico');
        });

        Schema::create('fuentes_intervencion', function (Blueprint $table) {
            $table->id('id_fuente_intervencion');
            $table->foreignId('id_intervencion')->constrained('intervenciones', 'id_intervencion')->cascadeOnDelete();
            $table->index('id_intervencion');
            $table->foreignId('id_fragmento')->constrained('fragmentos_documento', 'id_fragmento')->noActionOnDelete()->deferrable()->initiallyImmediate(false);
            $table->index('id_fragmento');
            $table->text('fragmento_utilizado');
            $table->jsonb('metadatos')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->unique(['id_intervencion', 'id_fragmento'], 'fuentes_intervencion_unico');
        });

        Schema::create('rubricas', function (Blueprint $table) {
            $table->id('id_rubrica');
            $table->string('nombre', 255);
            $table->text('descripcion')->nullable();
            $table->string('rol_aplicable', 80)->nullable();
            $table->foreignId('id_tipo_audiencia')->nullable()->constrained('tipos_audiencia', 'id_tipo_audiencia')->restrictOnDelete();
            $table->index('id_tipo_audiencia');
            $table->integer('version')->default(1);
            $table->boolean('activo')->default(true);
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->unique(['nombre', 'version'], 'rubricas_version_unica');
        });

        Schema::create('criterios_rubrica', function (Blueprint $table) {
            $table->id('id_criterio');
            $table->foreignId('id_rubrica')->constrained('rubricas', 'id_rubrica')->cascadeOnDelete();
            $table->index('id_rubrica');
            $table->string('nombre', 255);
            $table->text('descripcion');
            $table->decimal('peso', 10, 4);
            $table->decimal('puntaje_maximo', 10, 4);
            $table->integer('orden');
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->unique(['id_rubrica', 'orden'], 'criterios_orden_unico');
            $table->unique(['id_criterio', 'id_rubrica'], 'criterios_rubrica_unico');
        });

        Schema::create('evaluaciones', function (Blueprint $table) {
            $table->id('id_evaluacion');
            $table->foreignId('id_simulacion')->constrained('simulaciones', 'id_simulacion')->cascadeOnDelete();
            $table->index('id_simulacion');
            $table->foreignId('id_rubrica')->constrained('rubricas', 'id_rubrica')->restrictOnDelete();
            $table->index('id_rubrica');
            $table->string('estado', 40)->default('pendiente');
            $table->decimal('puntaje_total', 10, 4)->nullable();
            $table->text('fortalezas')->nullable();
            $table->text('debilidades')->nullable();
            $table->text('recomendaciones')->nullable();
            $table->jsonb('datos_evaluacion')->nullable();
            $table->string('modelo_ia', 150)->nullable();
            $table->timestampTz('fecha_evaluacion')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->unique(['id_evaluacion', 'id_rubrica'], 'evaluaciones_rubrica_unico');
            $table->index(['id_simulacion', 'estado']);
        });

        Schema::create('resultados_evaluacion', function (Blueprint $table) {
            $table->id('id_resultado');
            $table->foreignId('id_evaluacion')->constrained('evaluaciones', 'id_evaluacion')->cascadeOnDelete();
            $table->index('id_evaluacion');
            $table->unsignedBigInteger('id_rubrica');
            $table->unsignedBigInteger('id_criterio');
            $table->decimal('puntaje', 10, 4);
            $table->text('comentario')->nullable();
            $table->text('evidencia')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->foreign(['id_evaluacion', 'id_rubrica'], 'resultados_evaluacion_rubrica_fk')->references(['id_evaluacion', 'id_rubrica'])->on('evaluaciones')->cascadeOnDelete();
            $table->foreign(['id_criterio', 'id_rubrica'], 'resultados_criterio_rubrica_fk')->references(['id_criterio', 'id_rubrica'])->on('criterios_rubrica')->restrictOnDelete();
            $table->index('id_criterio');
            $table->unique(['id_evaluacion', 'id_criterio'], 'resultados_criterio_unico');
        });

        Schema::create('objetos_pendientes_eliminacion', function (Blueprint $table) {
            $table->id('id_objeto_pendiente');
            $table->string('disco', 50);
            $table->string('ruta_almacenamiento', 1024);
            $table->string('estado', 40)->default('pendiente');
            $table->integer('intentos')->default(0);
            $table->text('ultimo_error')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();
            $table->unique(['disco', 'ruta_almacenamiento'], 'objetos_pendientes_unico');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objetos_pendientes_eliminacion');
        Schema::dropIfExists('resultados_evaluacion');
        Schema::dropIfExists('evaluaciones');
        Schema::dropIfExists('criterios_rubrica');
        Schema::dropIfExists('rubricas');
        Schema::dropIfExists('fuentes_intervencion');
        Schema::dropIfExists('intervenciones');
        Schema::dropIfExists('participantes_simulacion');
        Schema::dropIfExists('simulaciones');
        Schema::dropIfExists('transiciones_audiencia');
        Schema::dropIfExists('etapas_audiencia');
        Schema::dropIfExists('tipos_audiencia');
        Schema::dropIfExists('fragmentos_documento');
        Schema::dropIfExists('fuentes_juridicas');
        Schema::dropIfExists('historial_procesamiento');
        Schema::dropIfExists('referencias_expediente');
        Schema::dropIfExists('incidencias_analisis');
        Schema::dropIfExists('cronologia_expediente');
        Schema::dropIfExists('pruebas_expediente');
        Schema::dropIfExists('hechos_expediente');
        Schema::dropIfExists('delitos_expediente');
        Schema::dropIfExists('participantes_expediente');
        Schema::dropIfExists('analisis_expediente');
        Schema::dropIfExists('paginas_expediente');
        Schema::dropIfExists('archivos_expediente');
        Schema::dropIfExists('expedientes');
    }
};
