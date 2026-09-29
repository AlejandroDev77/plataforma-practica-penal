<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('propuestas_turnos_audiencia', function (Blueprint $table): void {
            $table->id('id_propuesta_turno');
            $table->unsignedBigInteger('id_tipo_audiencia');
            $table->unsignedBigInteger('id_etapa');
            $table->unsignedBigInteger('id_usuario_creador')->nullable();
            $table->unsignedInteger('orden');
            $table->string('rol', 80);
            $table->string('acto_propuesto', 160);
            $table->text('descripcion_propuesta');
            $table->string('referencia_normativa_propuesta', 500)->nullable();
            $table->text('observaciones')->nullable();
            $table->string('estado', 20)->default('borrador');
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();

            $table->foreign(['id_etapa', 'id_tipo_audiencia'], 'propuestas_turnos_etapa_fk')
                ->references(['id_etapa', 'id_tipo_audiencia'])
                ->on('etapas_audiencia')
                ->cascadeOnDelete();
            $table->foreign('id_usuario_creador', 'propuestas_turnos_creador_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->unique(['id_etapa', 'orden'], 'propuestas_turnos_orden_unico');
            $table->index(['id_tipo_audiencia', 'id_etapa', 'fecha_actualizacion'], 'propuestas_turnos_consulta_idx');
        });

        DB::statement('ALTER TABLE propuestas_turnos_audiencia ADD CONSTRAINT propuestas_turnos_orden CHECK (orden > 0)');
        DB::statement("ALTER TABLE propuestas_turnos_audiencia ADD CONSTRAINT propuestas_turnos_solo_borrador CHECK (estado = 'borrador')");
    }

    public function down(): void
    {
        Schema::dropIfExists('propuestas_turnos_audiencia');
    }
};
