<?php

namespace App\Actions\Pagos;

use App\Actions\Libro\AnularMovimiento;
use App\Enums\EstadoPago;
use App\Models\Pago;
use App\Services\Pagos\ImputadorDePagos;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Anular no borra: el pago queda como anulado, sus cuotas se reabren y el libro recibe un contramovimiento. */
class AnularPago
{
    public function __construct(private ImputadorDePagos $imputador, private AnularMovimiento $anularMovimiento) {}

    public function __invoke(Pago $pago, string $motivo, ?int $userId = null): Pago
    {
        return DB::transaction(function () use ($pago, $motivo, $userId) {
            $pago = Pago::whereKey($pago->id)->lockForUpdate()->firstOrFail();

            if ($pago->estado === EstadoPago::Anulado) {
                throw ValidationException::withMessages(['pago' => 'Este pago ya está anulado.']);
            }

            foreach ($pago->imputaciones as $imputacion) {
                $this->imputador->revertir($imputacion);
            }

            if ($movimiento = $pago->movimiento) {
                ($this->anularMovimiento)($movimiento, $motivo, $userId);
            }

            $pago->update(['estado' => EstadoPago::Anulado, 'anulado_at' => now(), 'motivo_anulacion' => $motivo]);

            return $pago;
        });
    }
}
