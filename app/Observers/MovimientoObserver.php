<?php

namespace App\Observers;

use App\Jobs\RecalcularLiquidacionDiariaJob;
use App\Models\Movimiento;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Cada vez que se crea, edita, re-fecha, anula o elimina un movimiento no-tributo, se recalculan los impuestos
 * del día completo. Si cambió de fecha o de cuenta, se recalculan las DOS (la vieja y la nueva).
 */
class MovimientoObserver implements ShouldHandleEventsAfterCommit
{
    private const RELEVANTES = ['fecha', 'importe', 'tipo', 'cuenta_id', 'anulado_at', 'es_tributo'];

    public function created(Movimiento $m): void
    {
        $this->recalcular($m->cuenta_id, $m->fecha->toDateString(), $m);
    }

    public function updated(Movimiento $m): void
    {
        if (! $m->wasChanged(self::RELEVANTES)) {
            return;
        }

        $this->recalcular($m->cuenta_id, $m->fecha->toDateString(), $m);

        $fechaVieja = $m->wasChanged('fecha') ? CarbonImmutable::parse($m->getOriginal('fecha'))->toDateString() : $m->fecha->toDateString();
        $cuentaVieja = $m->wasChanged('cuenta_id') ? (int) $m->getOriginal('cuenta_id') : $m->cuenta_id;

        if ($fechaVieja !== $m->fecha->toDateString() || $cuentaVieja !== $m->cuenta_id) {
            $this->recalcular($cuentaVieja, $fechaVieja, $m);
        }
    }

    public function deleted(Movimiento $m): void
    {
        $this->recalcular($m->cuenta_id, $m->fecha->toDateString(), $m);
    }

    private function recalcular(int $cuentaId, string $fecha, Movimiento $m): void
    {
        // Los movimientos de tributo son el resultado del cálculo, no su entrada: si no, habría un bucle.
        if ($m->es_tributo) {
            return;
        }

        RecalcularLiquidacionDiariaJob::dispatch($cuentaId, $fecha);
    }
}
