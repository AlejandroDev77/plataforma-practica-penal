<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos_etapa_audiencia', function (Blueprint $table): void {
            $table->id('id_turno_etapa');
            $table->unsignedBigInteger('id_tipo_audiencia');
            $table->unsignedBigInteger('id_etapa');
            $table->integer('orden');
            $table->string('rol', 80);
            $table->boolean('activo')->default(true);
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_actualizacion')->nullable();

            $table->foreign(['id_etapa', 'id_tipo_audiencia'], 'turnos_etapa_catalogo_fk')
                ->references(['id_etapa', 'id_tipo_audiencia'])
                ->on('etapas_audiencia')
                ->cascadeOnDelete();
            $table->unique(['id_etapa', 'orden'], 'turnos_etapa_orden_unico');
            $table->index(['id_tipo_audiencia', 'id_etapa', 'activo'], 'turnos_etapa_consulta_idx');
        });

        DB::statement('ALTER TABLE turnos_etapa_audiencia ADD CONSTRAINT turnos_etapa_orden CHECK (orden > 0)');
        DB::statement("ALTER TABLE turnos_etapa_audiencia ADD CONSTRAINT turnos_etapa_rol_no_vacio CHECK (btrim(rol) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('turnos_etapa_audiencia');
    }
};
