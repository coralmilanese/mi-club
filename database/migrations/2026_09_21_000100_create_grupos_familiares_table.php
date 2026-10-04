<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupos_familiares', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            // La FK a socios.id se agrega después de crear socios (dependencia circular).
            $table->unsignedBigInteger('titular_socio_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupos_familiares');
    }
};
