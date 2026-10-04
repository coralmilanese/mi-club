<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // pg_trgm: matching difuso de nombres (bot de Telegram, importaciones). unaccent: "Martín" = "martin".
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(): void
    {
        // Las extensiones se dejan instaladas: pueden ser usadas por otras bases.
    }
};
