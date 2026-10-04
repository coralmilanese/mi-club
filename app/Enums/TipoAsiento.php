<?php

namespace App\Enums;

enum TipoAsiento: string
{
    case CobroCuota = 'cobro_cuota';
    case PagoGasto = 'pago_gasto';
    case LiquidacionDiaria = 'liquidacion_diaria';
    case Ajuste = 'ajuste';
    case Apertura = 'apertura';

    public function label(): string
    {
        return match ($this) {
            self::CobroCuota => 'Cobro de cuota',
            self::PagoGasto => 'Pago de gasto',
            self::LiquidacionDiaria => 'Liquidación diaria',
            self::Ajuste => 'Ajuste',
            self::Apertura => 'Apertura',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
