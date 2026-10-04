<?php

namespace App\Enums;

enum EstadoPago: string
{
    case Borrador = 'borrador';
    case Confirmado = 'confirmado';
    case Anulado = 'anulado';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Confirmado => 'Confirmado',
            self::Anulado => 'Anulado',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
