<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente mínimo de la Bot API de Telegram (HTTP puro, sin SDK): enviar mensajes con teclado inline,
 * responder callbacks, y descargar los archivos que manda el usuario (foto o PDF del comprobante).
 */
class TelegramClient
{
    public function __construct(private ?string $token = null)
    {
        $this->token ??= config('aasr.telegram.bot_token');
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     *
     * No tira excepción si Telegram rechaza el mensaje (por ejemplo, HTML mal formado en un texto dinámico):
     * el dato ya está guardado en la base, perder sólo la notificación no puede voltear el webhook entero.
     */
    public function enviarMensaje(string $chatId, string $texto, ?array $replyMarkup = null, ?string $replaceMessageId = null): Response
    {
        $payload = ['chat_id' => $chatId, 'text' => $texto, 'parse_mode' => 'HTML'];
        if ($replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }

        if ($replaceMessageId) {
            return $this->post('editMessageText', [...$payload, 'message_id' => $replaceMessageId], throw: false);
        }

        return $this->post('sendMessage', $payload, throw: false);
    }

    public function responderCallback(string $callbackQueryId, ?string $texto = null): Response
    {
        return $this->post('answerCallbackQuery', array_filter(['callback_query_id' => $callbackQueryId, 'text' => $texto]), throw: false);
    }

    public function quitarTeclado(string $chatId, string $messageId): Response
    {
        return $this->post('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => ['inline_keyboard' => []]], throw: false);
    }

    /** Descarga un archivo (foto o documento) por su file_id y devuelve su contenido binario. */
    public function descargarArchivo(string $fileId): string
    {
        $info = $this->post('getFile', ['file_id' => $fileId])->json('result');
        $path = $info['file_path'] ?? throw new \RuntimeException('Telegram no devolvió la ruta del archivo.');

        return Http::get("https://api.telegram.org/file/bot{$this->token}/{$path}")->throw()->body();
    }

    public function setWebhook(string $url): Response
    {
        return $this->post('setWebhook', ['url' => $url, 'secret_token' => config('aasr.telegram.webhook_secret'), 'allowed_updates' => ['message', 'callback_query']]);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $metodo, array $payload, bool $throw = true): Response
    {
        $respuesta = Http::asJson()->post("https://api.telegram.org/bot{$this->token}/{$metodo}", $payload);

        if (! $throw) {
            if ($respuesta->failed()) {
                Log::warning("Telegram: {$metodo} falló", ['payload' => $payload, 'respuesta' => $respuesta->json()]);
            }

            return $respuesta;
        }

        return $respuesta->throw();
    }
}
