<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook {url : URL pública (ej. la que da ngrok), sin la ruta}';

    protected $description = 'Registra el webhook de Telegram contra una URL pública (ngrok en desarrollo)';

    public function handle(TelegramClient $telegram): int
    {
        $url = rtrim($this->argument('url'), '/').'/telegram/webhook';
        $r = $telegram->setWebhook($url);

        if ($r->json('ok')) {
            $this->info("Webhook registrado: {$url}");

            return self::SUCCESS;
        }

        $this->error('Telegram rechazó el webhook: '.$r->json('description'));

        return self::FAILURE;
    }
}
