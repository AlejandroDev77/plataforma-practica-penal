<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisiones_analisis', function (Blueprint $table): void {
            $table->id('id_revision');
            $table->unsignedBigInteger('id_expediente');
            $table->unsignedBigInteger('id_analisis');
            $table->foreignId('id_usuario')->nullable()->constrained('users', 'id')->nullOnDelete();
            $table->string('decision', 32);
            $table->text('observacion')->nullable();
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->foreign(['id_analisis', 'id_expediente'], 'revisiones_analisis_contexto_fk')
                ->references(['id_analisis', 'id_expediente'])
                ->on('analisis_expediente')
                ->cascadeOnDelete();
            $table->index(['id_analisis', 'fecha_creacion'], 'revisiones_analisis_fecha_idx');
        });

        DB::statement("ALTER TABLE revisiones_analisis ADD CONSTRAINT revisiones_analisis_decision CHECK (decision IN ('aprobado', 'requiere_cambios'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('revisiones_analisis');
    }
};
