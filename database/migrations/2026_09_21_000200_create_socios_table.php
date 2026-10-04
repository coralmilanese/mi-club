<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('socios', function (Blueprint $table) {
            $table->id();
            // El Nro del Excel se reutiliza entre activos y bajas: es un dato, NO una clave.
            $table->unsignedInteger('nro_socio')->nullable()->index();
            $table->string('apellido');
            $table->string('nombre');
            $table->string('genero', 1)->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('nacionalidad')->nullable();
            $table->string('dni', 20)->nullable()->unique();
            $table->string('direccion')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('provincia')->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('profesion')->nullable();
            $table->string('categoria', 20)->index();
            $table->string('sede', 20)->default('aasr');
            // Denormalizado desde socio_estados (lo mantiene CambiarEstadoSocio) para filtrar rápido.
            $table->string('estado', 20)->index();
            $table->date('fecha_asociacion')->nullable();
            $table->date('fecha_inicio_actividad')->nullable();
            $table->date('fecha_baja')->nullable();
            $table->text('motivo_baja')->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('grupo_familiar_id')->nullable()->constrained('grupos_familiares')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('grupos_familiares', function (Blueprint $table) {
            $table->foreign('titular_socio_id')->references('id')->on('socios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('grupos_familiares', fn (Blueprint $t) => $t->dropForeign(['titular_socio_id']));
        Schema::dropIfExists('socios');
    }
};
