<?php

namespace App\Jobs;

use App\Models\Cuenta;
use App\Services\Libro\LiquidacionDiariaService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Un job por (cuenta, fecha), deduplicado mientras espera en cola: cargar 6 pagos del mismo día no dispara 6 recálculos.
 * El candado se libera al EMPEZAR a procesar, así un movimiento que llega mientras corre encola uno nuevo y no queda un resultado viejo.
 */
class RecalcularLiquidacionDiariaJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 60;

    public function __construct(public int $cuentaId, public string $fecha) {}

    public function uniqueId(): string
    {
        return "liquidacion:{$this->cuentaId}:{$this->fecha}";
    }

    public function handle(LiquidacionDiariaService $servicio): void
    {
        $cuenta = Cuenta::find($this->cuentaId);
        if ($cuenta) {
            $servicio->recalcular($cuenta, CarbonImmutable::parse($this->fecha));
        }
    }
}
