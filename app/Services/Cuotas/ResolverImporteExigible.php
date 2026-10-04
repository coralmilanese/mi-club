<?php

namespace App\Services\Cuotas;

use App\Enums\EstadoCuota;
use App\Models\Cuota;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

/**
 * Regla de cobro del club:
 *  - una cuota ya cobrada queda congelada para siempre en `importe_cobrado`;
 *  - una cuota impaga se cobra a la tarifa vigente AL MOMENTO DEL PAGO, no a la del mes devengado.
 * `importe_devengado` sólo sirve para reportes de "cuánto se esperaba recaudar".
 */
class ResolverImporteExigible
{
    /** @var array<string, string|null>|null Memo de tarifas por (plan, fecha), sólo activo dentro de deudaTotal(). */
    private ?array $memo = null;

    /** Importe total que hay que reunir para dar por cancelada la cuota, valuado a `$fecha`. */
    public function importe(Cuota $cuota, CarbonInterface $fecha): string
    {
        return match ($cuota->estado) {
            EstadoCuota::Pagada => $this->dec($cuota->importe_cobrado ?? $cuota->importe_devengado),
            EstadoCuota::Exenta, EstadoCuota::Anulada => '0.00',
            default => $this->dec($this->tarifaVigente($cuota, $fecha) ?? $cuota->importe_devengado),
        };
    }

    /** Lo que falta pagar hoy (o a `$fecha`): exigible − imputado, nunca negativo. Un pago parcial se revalúa. */
    public function deuda(Cuota $cuota, ?CarbonInterface $fecha = null): string
    {
        if (! $cuota->estado->esAdeudable()) {
            return '0.00';
        }

        $deuda = BigDecimal::of($this->importe($cuota, $fecha ?? now()))->minus($cuota->importe_imputado);

        return $deuda->isNegative() ? '0.00' : (string) $deuda->toScale(2, RoundingMode::HALF_UP);
    }

    /**
     * Deuda total de un conjunto de cuotas valuadas a `$fecha` (default hoy), sin repetir la consulta de tarifa por plan.
     *
     * @param  iterable<Cuota>  $cuotas
     */
    public function deudaTotal(iterable $cuotas, ?CarbonInterface $fecha = null): string
    {
        $this->memo = [];
        try {
            $total = BigDecimal::zero();
            foreach ($cuotas as $cuota) {
                $total = $total->plus($this->deuda($cuota, $fecha));
            }

            return (string) $total->toScale(2, RoundingMode::HALF_UP);
        } finally {
            $this->memo = null;
        }
    }

    private function tarifaVigente(Cuota $cuota, CarbonInterface $fecha): ?string
    {
        $clave = $cuota->plan_id.'|'.$fecha->toDateString();
        if ($this->memo !== null && array_key_exists($clave, $this->memo)) {
            return $this->memo[$clave];
        }

        $importe = $cuota->plan->tarifaVigente($fecha)?->importe;
        if ($this->memo !== null) {
            $this->memo[$clave] = $importe;
        }

        return $importe;
    }

    private function dec(mixed $valor): string
    {
        return (string) BigDecimal::of((string) $valor)->toScale(2, RoundingMode::HALF_UP);
    }
}
