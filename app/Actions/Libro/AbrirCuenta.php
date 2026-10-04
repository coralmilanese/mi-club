<?php

namespace App\Actions\Libro;

use App\Enums\TipoAsiento;
use App\Enums\TipoMovimiento;
use App\Models\Cuenta;
use App\Models\Movimiento;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Asiento de apertura: el saldo inicial de una cuenta. Se carga una sola vez y no entra en las bases de impuestos. */
class AbrirCuenta
{
    public function __construct(private RegistrarMovimiento $registrar) {}

    public function __invoke(Cuenta $cuenta, string $saldo, CarbonInterface $fecha, ?int $userId = null): Movimiento
    {
        if ($cuenta->movimientos()->whereHas('asiento', fn ($q) => $q->where('tipo', TipoAsiento::Apertura->value))->exists()) {
            throw ValidationException::withMessages(['saldo' => "{$cuenta->nombre} ya tiene su asiento de apertura."]);
        }

        return DB::transaction(function () use ($cuenta, $saldo, $fecha, $userId) {
            $cuenta->update(['saldo_inicial' => $saldo, 'fecha_saldo_inicial' => $fecha->toDateString()]);

            return ($this->registrar)(
                cuenta: $cuenta,
                tipo: TipoMovimiento::Ingreso,
                fecha: $fecha,
                concepto: "Saldo de apertura {$fecha->format('d/m/Y')}",
                importe: $saldo,
                categoria: 'Saldo al Comienzo',
                tipoAsiento: TipoAsiento::Apertura,
                userId: $userId,
            );
        });
    }
}
