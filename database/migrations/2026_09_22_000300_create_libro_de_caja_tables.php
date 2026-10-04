<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asientos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->string('descripcion');
            $table->string('tipo', 30);
            $table->timestamps();

            $table->index(['tipo', 'fecha']);
        });

        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->string('concepto');
            $table->string('tipo', 10);
            $table->decimal('importe', 14, 2);
            $table->foreignId('cuenta_id')->constrained('cuentas');
            $table->foreignId('medio_pago_id')->nullable()->constrained('medios_pago')->nullOnDelete();
            $table->string('categoria_libro');
            // Los generados por la liquidación diaria: se excluyen de las bases (si no, el impuesto se gravaría a sí mismo).
            $table->boolean('es_tributo')->default(false);
            $table->foreignId('asiento_id')->nullable()->constrained('asientos')->nullOnDelete();
            $table->nullableMorphs('origenable');
            // Nada se borra: una anulación marca el original y su contramovimiento, y ambos quedan fuera de las bases.
            $table->timestamp('anulado_at')->nullable();
            $table->unsignedBigInteger('anulado_por_movimiento_id')->nullable();
            $table->foreignId('creado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['fecha', 'id']);
            $table->index(['cuenta_id', 'fecha']);
            $table->index('categoria_libro');
            $table->index(['cuenta_id', 'fecha', 'es_tributo']);
        });

        Schema::table('movimientos', function (Blueprint $table) {
            $table->foreign('anulado_por_movimiento_id')->references('id')->on('movimientos')->nullOnDelete();
        });

        Schema::create('liquidaciones_diarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_id')->constrained('cuentas');
            $table->date('fecha');
            $table->decimal('base_credito', 14, 2)->default(0);
            $table->decimal('base_debito_operativo', 14, 2)->default(0);
            $table->boolean('ajustada_manualmente')->default(false);
            $table->foreignId('ajustada_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_ajuste')->nullable();
            $table->timestamp('recalculada_at')->nullable();
            $table->timestamps();

            $table->unique(['cuenta_id', 'fecha']);
        });

        Schema::create('liquidacion_diaria_tributos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_diaria_id')->constrained('liquidaciones_diarias')->cascadeOnDelete();
            $table->foreignId('tributo_id')->constrained('tributos');
            $table->decimal('alicuota_aplicada', 8, 5)->nullable();
            $table->decimal('base_imponible', 14, 2)->nullable();
            $table->decimal('importe', 14, 2);
            $table->foreignId('movimiento_id')->nullable()->constrained('movimientos')->nullOnDelete();
            $table->timestamps();

            $table->unique(['liquidacion_diaria_id', 'tributo_id']);
        });

        Schema::create('arqueos_cuenta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_id')->constrained('cuentas');
            $table->date('fecha');
            $table->decimal('saldo_declarado', 14, 2);
            $table->decimal('saldo_calculado', 14, 2);
            $table->decimal('diferencia', 14, 2);
            $table->text('observaciones')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cuenta_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arqueos_cuenta');
        Schema::dropIfExists('liquidacion_diaria_tributos');
        Schema::dropIfExists('liquidaciones_diarias');
        Schema::table('movimientos', fn (Blueprint $t) => $t->dropForeign(['anulado_por_movimiento_id']));
        Schema::dropIfExists('movimientos');
        Schema::dropIfExists('asientos');
    }
};
