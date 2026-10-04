<?php

namespace App\Enums;

enum TipoCuenta: string
{
    case Banco = 'banco';
    case Efectivo = 'efectivo';
    case Inversion = 'inversion';

    public function label(): string
    {
        return match ($this) {
            self::Banco => 'Banco',
            self::Efectivo => 'Efectivo',
            self::Inversion => 'Inversión',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
