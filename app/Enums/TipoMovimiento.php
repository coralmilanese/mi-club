<?php

namespace App\Enums;

enum TipoMovimiento: string
{
    case Ingreso = 'ingreso';
    case Egreso = 'egreso';

    public function label(): string
    {
        return match ($this) {
            self::Ingreso => 'Ingreso',
            self::Egreso => 'Egreso',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
