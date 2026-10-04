<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id')->unique();
            $table->string('username')->nullable()->index();
            $table->string('nombre')->nullable();
            $table->boolean('autorizado')->default(false);
            $table->string('rol', 20)->default('tesorero');
            $table->timestamp('autorizado_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ingestas_telegram', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_chat_id')->constrained('telegram_chats')->cascadeOnDelete();
            $table->string('message_id');
            $table->string('tipo', 20); // foto | documento | texto
            $table->text('texto_original')->nullable();
            $table->foreignId('comprobante_id')->nullable()->constrained('comprobantes')->nullOnDelete();
            $table->string('estado', 30);
            $table->json('extraccion_json')->nullable();
            $table->json('candidatos_json')->nullable();
            $table->foreignId('socio_sugerido_id')->nullable()->constrained('socios')->nullOnDelete();
            $table->decimal('confianza', 4, 3)->nullable();
            $table->foreignId('pago_id')->nullable()->constrained('pagos')->nullOnDelete();
            $table->text('error_mensaje')->nullable();
            $table->string('modelo_ia')->nullable();
            $table->unsignedInteger('tokens_entrada')->nullable();
            $table->unsignedInteger('tokens_salida')->nullable();
            $table->decimal('costo_usd', 10, 4)->nullable();
            $table->timestamps();

            $table->unique(['telegram_chat_id', 'message_id']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestas_telegram');
        Schema::dropIfExists('telegram_chats');
    }
};
