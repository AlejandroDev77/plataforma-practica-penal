<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paginas_expediente', function (Blueprint $table): void {
            $table->string('localizador', 200)->nullable();
        });

        DB::table('paginas_expediente')->update([
            'localizador' => DB::raw("'Página ' || numero_pagina"),
        ]);
    }

    public function down(): void
    {
        Schema::table('paginas_expediente', function (Blueprint $table): void {
            $table->dropColumn('localizador');
        });
    }
};
