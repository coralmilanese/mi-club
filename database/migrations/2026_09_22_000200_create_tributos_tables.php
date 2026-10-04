<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tributos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo', 30)->unique();
            $table->string('base', 10);
            // Retención (SIRCREB): la base del impuesto al débito la suma, la de los otros impuestos no.
            $table->boolean('es_retencion')->default(false);
            $table->boolean('incluye_retenciones_en_base')->default(false);
            // Etiqueta que va al concepto y a la columna PAGO del libro.
            $table->string('categoria_libro');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('alicuotas_tributo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tributo_id')->constrained('tributos')->cascadeOnDelete();
            // En porcentaje: 4.00000 = 4%.
            $table->decimal('alicuota', 8, 5);
            $table->date('vigencia_desde');
            $table->date('vigencia_hasta')->nullable();
            $table->timestamps();

            $table->unique(['tributo_id', 'vigencia_desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alicuotas_tributo');
        Schema::dropIfExists('tributos');
    }
};
