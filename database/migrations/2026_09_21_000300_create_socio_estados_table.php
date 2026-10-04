<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('socio_estados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('socio_id')->constrained('socios')->cascadeOnDelete();
            $table->string('estado', 20);
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();
            $table->text('motivo')->nullable();
            $table->timestamps();

            $table->index(['socio_id', 'desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('socio_estados');
    }
};
