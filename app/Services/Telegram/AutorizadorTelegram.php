<?php

namespace App\Services\Telegram;

use App\Models\TelegramChat;

/**
 * Whitelist inicial por username (D7): la primera vez que escribe un usuario de Telegram autorizado
 * en la configuración, su chat queda habilitado para siempre (hasta que se le revoque desde el panel).
 */
class AutorizadorTelegram
{
    /** @param array<string, mixed> $from payload "from" del update de Telegram */
    public function resolver(string $chatId, array $from): TelegramChat
    {
        $username = $from['username'] ?? null;
        $nombre = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));

        $chat = TelegramChat::firstOrCreate(['chat_id' => $chatId], ['username' => $username, 'nombre' => $nombre ?: null]);

        if (! $chat->autorizado && $username && $this->enLaWhitelist($username)) {
            $chat->update(['autorizado' => true, 'autorizado_at' => now(), 'username' => $username, 'nombre' => $nombre ?: $chat->nombre]);
        } elseif ($username && $chat->username !== $username) {
            $chat->update(['username' => $username]); // la gente cambia de @usuario
        }

        return $chat;
    }

    private function enLaWhitelist(string $username): bool
    {
        $permitidos = array_map('mb_strtolower', config('aasr.telegram.usuarios_autorizados', []));

        return in_array(mb_strtolower($username), $permitidos, true);
    }
}
