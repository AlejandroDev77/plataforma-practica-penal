<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turnos_etapa_audiencia', function (Blueprint $table): void {
            $table->string('instruccion', 1500)->nullable();
        });

        DB::statement("ALTER TABLE turnos_etapa_audiencia ADD CONSTRAINT turnos_etapa_instruccion_no_vacia CHECK (instruccion IS NULL OR btrim(instruccion) <> '')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE turnos_etapa_audiencia DROP CONSTRAINT IF EXISTS turnos_etapa_instruccion_no_vacia');

        Schema::table('turnos_etapa_audiencia', function (Blueprint $table): void {
            $table->dropColumn('instruccion');
        });
    }
};
