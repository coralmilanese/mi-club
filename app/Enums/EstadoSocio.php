<?php

namespace App\Enums;

enum EstadoSocio: string
{
    case Alta = 'alta';
    case Activo = 'activo';
    case Baja = 'baja';
    case Suspendido = 'suspendido';

    public function label(): string
    {
        return match ($this) {
            self::Alta => 'Alta',
            self::Activo => 'Activo',
            self::Baja => 'Baja',
            self::Suspendido => 'Suspendido',
        };
    }

    public function esVigente(): bool
    {
        return $this !== self::Baja;
    }

    /** @return list<array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
