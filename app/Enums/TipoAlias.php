<?php

namespace App\Enums;

enum TipoAlias: string
{
    case Apodo = 'apodo';
    case Cuit = 'cuit';
    case Cbu = 'cbu';
    case AliasBancario = 'alias_bancario';
    case TitularCuenta = 'titular_cuenta';

    public function label(): string
    {
        return match ($this) {
            self::Apodo => 'Apodo',
            self::Cuit => 'CUIT',
            self::Cbu => 'CBU',
            self::AliasBancario => 'Alias bancario',
            self::TitularCuenta => 'Titular de cuenta',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
