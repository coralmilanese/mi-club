<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tributos', function (Blueprint $table) {
            // Texto del concepto tal como lo muestra el resumen bancario (ej. "IMP. I.B SIRCREB").
            $table->string('concepto_libro')->nullable()->after('categoria_libro');
        });
    }

    public function down(): void
    {
        Schema::table('tributos', fn (Blueprint $t) => $t->dropColumn('concepto_libro'));
    }
};
