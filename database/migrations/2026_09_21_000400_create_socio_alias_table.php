<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('socio_alias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('socio_id')->constrained('socios')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->string('valor');
            $table->timestamp('verificado_at')->nullable();
            $table->timestamps();

            $table->unique(['tipo', 'valor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('socio_alias');
    }
};
