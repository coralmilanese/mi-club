<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('socio_id')->constrained('socios');
            $table->date('periodo'); // siempre día 1 del mes
            $table->foreignId('plan_id')->constrained('planes');

            // Snapshot al devengar: sólo para reportes tipo "cuánto se esperaba recaudar en marzo".
            $table->decimal('importe_devengado', 14, 2);
            $table->foreignId('tarifa_plan_id_devengada')->nullable()->constrained('tarifas_plan')->nullOnDelete();

            // Se congelan al terminar de cobrarse: una cuota cobrada no se revalúa nunca más.
            $table->decimal('importe_cobrado', 14, 2)->nullable();
            $table->foreignId('tarifa_plan_id_cobrada')->nullable()->constrained('tarifas_plan')->nullOnDelete();

            $table->decimal('importe_imputado', 14, 2)->default(0);
            $table->date('fecha_cancelacion')->nullable();
            $table->string('estado', 20)->default('pendiente');
            // Titular del grupo familiar que paga esta cuota (adicional familiar). Null = paga el propio socio.
            $table->foreignId('socio_pagador_id')->nullable()->constrained('socios')->nullOnDelete();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            // Sin columna de vencimiento: no hay vencimiento ni mora.
            $table->unique(['socio_id', 'periodo']);
            $table->index(['periodo', 'estado']);
            $table->index('socio_pagador_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuotas');
    }
};
