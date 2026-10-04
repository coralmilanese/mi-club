<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('tipo', 20);
            $table->string('titular')->nullable();
            // Sólo la cuenta bancaria liquida impuestos; las cajas de efectivo no.
            $table->boolean('aplica_tributos')->default(false);
            $table->decimal('saldo_inicial', 14, 2)->default(0);
            $table->date('fecha_saldo_inicial')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::create('medios_pago', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo', 30)->unique();
            $table->foreignId('cuenta_default_id')->nullable()->constrained('cuentas')->nullOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medios_pago');
        Schema::dropIfExists('cuentas');
    }
};
