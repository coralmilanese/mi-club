<?php

namespace App\Enums;

enum CategoriaSocio: string
{
    case Activo = 'activo';
    case Vitalicio = 'vitalicio';
    case Honorario = 'honorario';

    public function label(): string
    {
        return match ($this) {
            self::Activo => 'Activo',
            self::Vitalicio => 'Vitalicio',
            self::Honorario => 'Honorario',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
