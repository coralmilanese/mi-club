<?php

namespace App\Enums;

enum OrigenPago: string
{
    case Web = 'web';
    case TelegramComprobante = 'telegram_comprobante';
    case TelegramTexto = 'telegram_texto';
    case Importacion = 'importacion';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::TelegramComprobante => 'Telegram (comprobante)',
            self::TelegramTexto => 'Telegram (texto)',
            self::Importacion => 'Importación',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
