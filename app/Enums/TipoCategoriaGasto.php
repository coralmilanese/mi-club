<?php

namespace App\Enums;

enum TipoCategoriaGasto: string
{
    case Recurrente = 'recurrente';
    case Eventual = 'eventual';

    public function label(): string
    {
        return match ($this) {
            self::Recurrente => 'Recurrente',
            self::Eventual => 'Eventual',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
