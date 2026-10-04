<?php

namespace App\Enums;

enum BaseTributo: string
{
    case Credito = 'credito';
    case Debito = 'debito';

    public function label(): string
    {
        return match ($this) {
            self::Credito => 'Crédito',
            self::Debito => 'Débito',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
