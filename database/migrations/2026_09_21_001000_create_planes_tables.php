<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes', function (Blueprint $table) {
            $table->id();
            // Identificador configurable desde el ABM: nada de planes hardcodeados en el código.
            $table->string('tipo_plan', 50)->unique();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->boolean('genera_cuota')->default(true);
            // Los adherentes de un grupo familiar tienen un plan de este tipo; la cuota se le cobra al titular.
            $table->boolean('es_adicional_familiar')->default(false);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('tarifas_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('planes')->cascadeOnDelete();
            $table->decimal('importe', 14, 2);
            $table->date('vigencia_desde');
            $table->date('vigencia_hasta')->nullable();
            $table->foreignId('creado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'vigencia_desde']);
        });

        Schema::create('socio_planes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('socio_id')->constrained('socios')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('planes');
            $table->date('desde');
            $table->date('hasta')->nullable();
            $table->text('motivo')->nullable();
            $table->timestamps();

            $table->index(['socio_id', 'desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('socio_planes');
        Schema::dropIfExists('tarifas_plan');
        Schema::dropIfExists('planes');
    }
};
