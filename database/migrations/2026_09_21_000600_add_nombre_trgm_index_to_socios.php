<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // unaccent() no es IMMUTABLE, así que el índice usa lower() y el matcher compara contra la misma expresión normalizada.
        DB::statement("CREATE INDEX socios_nombre_trgm_idx ON socios USING gin ((lower(apellido || ' ' || nombre)) gin_trgm_ops)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS socios_nombre_trgm_idx');
    }
};
