<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_documento', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo', 50)->unique();
            $table->boolean('obligatorio')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('socio_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('socio_id')->constrained('socios')->cascadeOnDelete();
            $table->foreignId('tipo_documento_id')->constrained('tipos_documento');
            // Nulos cuando el documento se importó del Excel como "entregado" sin archivo digital.
            $table->string('archivo_path')->nullable();
            $table->string('disco', 30)->nullable();
            $table->string('nombre_original')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('tamano')->nullable();
            $table->foreignId('subido_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('subido_at')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['socio_id', 'tipo_documento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('socio_documentos');
        Schema::dropIfExists('tipos_documento');
    }
};
