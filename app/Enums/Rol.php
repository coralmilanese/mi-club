<?php

namespace App\Enums;

enum Rol: string
{
    case Tesorero = 'tesorero';
    case Lectura = 'lectura';

    public function label(): string
    {
        return match ($this) {
            self::Tesorero => 'Tesorero',
            self::Lectura => 'Solo lectura',
        };
    }
}
