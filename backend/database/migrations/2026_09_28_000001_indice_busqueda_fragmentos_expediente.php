<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('La búsqueda de fragmentos requiere PostgreSQL.');
        }

        DB::statement("CREATE INDEX fragmentos_archivo_busqueda_es_idx ON fragmentos_documento USING GIN (to_tsvector('spanish', contenido)) WHERE id_archivo IS NOT NULL");
        DB::statement("CREATE INDEX fragmentos_fuente_busqueda_es_idx ON fragmentos_documento USING GIN (to_tsvector('spanish', contenido)) WHERE id_fuente_juridica IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fragmentos_archivo_busqueda_es_idx');
        DB::statement('DROP INDEX IF EXISTS fragmentos_fuente_busqueda_es_idx');
    }
};
