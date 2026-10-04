<?php

namespace App\Enums;

enum SedeSocio: string
{
    case Aasr = 'aasr';
    case Planeadores = 'planeadores';

    public function label(): string
    {
        return match ($this) {
            self::Aasr => 'AASR',
            self::Planeadores => 'Planeadores',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
