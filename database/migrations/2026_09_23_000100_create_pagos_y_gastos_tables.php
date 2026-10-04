<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes', function (Blueprint $table) {
            $table->id();
            $table->string('archivo_path');
            $table->string('disco', 30);
            $table->string('mime', 100)->nullable();
            $table->char('hash_sha256', 64)->unique();
            $table->string('origen', 30)->default('web');
            $table->string('telegram_file_id')->nullable();
            $table->string('telegram_message_id')->nullable();
            $table->json('extraccion_json')->nullable();
            $table->string('modelo_ia')->nullable();
            $table->decimal('confianza', 4, 3)->nullable();
            $table->timestamp('procesado_at')->nullable();
            $table->nullableMorphs('comprobantable');
            $table->timestamps();
        });

        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            // Quién paga (el titular, si paga la cuota de un grupo familiar).
            $table->foreignId('socio_id')->constrained('socios');
            // FECHA DEL COMPROBANTE: es la que manda para el libro de caja. `fecha_registro` es sólo auditoría.
            $table->date('fecha');
            $table->timestamp('fecha_registro')->useCurrent();
            // Importe BRUTO: el socio transfirió esto; los impuestos son egresos aparte.
            $table->decimal('importe_bruto', 14, 2);
            $table->foreignId('medio_pago_id')->nullable()->constrained('medios_pago')->nullOnDelete();
            $table->foreignId('cuenta_id')->constrained('cuentas');
            $table->string('estado', 20)->default('confirmado');
            $table->string('origen', 30)->default('web');
            $table->foreignId('comprobante_id')->nullable()->constrained('comprobantes')->nullOnDelete();
            $table->string('referencia_externa')->nullable();
            $table->string('concepto')->nullable();
            $table->foreignId('registrado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmado_at')->nullable();
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->index(['socio_id', 'fecha']);
            $table->index(['estado', 'fecha']);
            $table->index('referencia_externa');
        });

        Schema::create('pago_cuotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pago_id')->constrained('pagos')->cascadeOnDelete();
            $table->foreignId('cuota_id')->constrained('cuotas');
            $table->decimal('importe_imputado', 14, 2);
            $table->timestamps();

            $table->index('cuota_id');
        });

        Schema::create('categorias_gasto', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('codigo', 50)->unique();
            $table->string('tipo', 20)->default('eventual');
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::create('gastos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_gasto_id')->constrained('categorias_gasto');
            $table->date('fecha');
            $table->decimal('importe', 14, 2);
            $table->string('descripcion');
            $table->string('proveedor')->nullable();
            $table->foreignId('medio_pago_id')->nullable()->constrained('medios_pago')->nullOnDelete();
            $table->foreignId('cuenta_id')->constrained('cuentas');
            $table->date('periodo_cubierto_desde')->nullable();
            $table->date('periodo_cubierto_hasta')->nullable();
            $table->foreignId('comprobante_id')->nullable()->constrained('comprobantes')->nullOnDelete();
            $table->foreignId('registrado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->index(['categoria_gasto_id', 'fecha']);
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos');
        Schema::dropIfExists('categorias_gasto');
        Schema::dropIfExists('pago_cuotas');
        Schema::dropIfExists('pagos');
        Schema::dropIfExists('comprobantes');
    }
};
