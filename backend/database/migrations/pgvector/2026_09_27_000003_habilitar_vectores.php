<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('pgvector requiere PostgreSQL.');
        }
        if (! DB::selectOne("SELECT name FROM pg_available_extensions WHERE name = 'vector'")) {
            throw new RuntimeException('Instale pgvector en el servidor PostgreSQL y vuelva a ejecutar esta migración opcional.');
        }

        // No fija una dimensión hasta conocer el proveedor/modelo de embeddings.
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('ALTER TABLE fragmentos_documento ADD COLUMN embedding vector');
        DB::statement('ALTER TABLE fragmentos_documento ADD CONSTRAINT fragmentos_vector_dimension CHECK (embedding IS NULL OR (modelo_embedding IS NOT NULL AND dimensiones_embedding IS NOT NULL AND vector_dims(embedding) = dimensiones_embedding))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fragmentos_documento DROP CONSTRAINT fragmentos_vector_dimension');
        DB::statement('ALTER TABLE fragmentos_documento DROP COLUMN embedding');
        // La extensión puede ser compartida con otros módulos; no se elimina.
    }
};
