<?php

namespace App\Enums;

enum EstadoCuota: string
{
    case Pendiente = 'pendiente';
    case Parcial = 'parcial';
    case Pagada = 'pagada';
    case Exenta = 'exenta';
    case Anulada = 'anulada';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Parcial => 'Parcial',
            self::Pagada => 'Pagada',
            self::Exenta => 'Exenta',
            self::Anulada => 'Anulada',
        };
    }

    /** Estados en los que la cuota todavía puede generar deuda. */
    public function esAdeudable(): bool
    {
        return in_array($this, [self::Pendiente, self::Parcial], true);
    }
}
