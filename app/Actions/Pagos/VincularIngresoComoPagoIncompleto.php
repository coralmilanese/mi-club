<?php

namespace App\Actions\Pagos;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPago;
use App\Enums\OrigenPago;
use App\Enums\TipoAsiento;
use App\Models\Cuota;
use App\Models\Movimiento;
use App\Models\Pago;
use App\Models\Socio;
use App\Services\Cuotas\ResolverImporteExigible;
use App\Services\Pagos\ImputadorDePagos;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cuando el dinero que entró al banco es MENOS que lo que la planilla del Excel da por cobrado, manda el libro:
 * se reabren las cuotas de esos períodos que estaban "pagadas" sin ningún pago detrás y se imputa lo realmente
 * ingresado, de la más antigua a la más nueva. Lo que falta queda como deuda (cuota parcial o pendiente).
 */
class VincularIngresoComoPagoIncompleto
{
    public function __construct(private ImputadorDePagos $imputador, private ResolverImporteExigible $resolver) {}

    /**
     * @param  list<string>  $periodos  'AAAA-MM' de las cuotas que este ingreso debía cubrir
     */
    public function __invoke(Movimiento $ingreso, Socio $pagador, array $periodos): Pago
    {
        if ($ingreso->origenable_type !== null) {
            throw ValidationException::withMessages(['movimiento' => 'Ese ingreso ya está vinculado a un pago.']);
        }

        return DB::transaction(function () use ($ingreso, $pagador, $periodos) {
            $cuotas = Cuota::where(fn ($q) => $q->where(fn ($q) => $q->where('socio_id', $pagador->id)->whereNull('socio_pagador_id'))->orWhere('socio_pagador_id', $pagador->id))
                ->whereIn(DB::raw("to_char(periodo, 'YYYY-MM')"), $periodos)
                ->orderBy('periodo')->orderBy('id')->lockForUpdate()->get();

            if ($cuotas->isEmpty()) {
                throw ValidationException::withMessages(['periodos' => "{$pagador->nombre_completo} no tiene cuotas en {$this->lista($periodos)}."]);
            }

            // Reabre las que la planilla daba por pagadas sin ningún pago que las respalde.
            foreach ($cuotas as $cuota) {
                if ($cuota->estado === EstadoCuota::Pagada && ! $cuota->imputaciones()->exists()) {
                    $cuota->fill([
                        'estado' => EstadoCuota::Pendiente,
                        'importe_cobrado' => null,
                        'tarifa_plan_id_cobrada' => null,
                        'importe_imputado' => 0,
                        'fecha_cancelacion' => null,
                        'observaciones' => trim(($cuota->observaciones ? $cuota->observaciones."\n" : '').'Reabierta: el ingreso real del libro fue menor a lo que daba por cobrado la planilla.'),
                    ])->save();
                }
            }

            // Reparte lo realmente ingresado entre las cuotas de esos períodos, de la más antigua a la más nueva.
            $restante = BigDecimal::of((string) $ingreso->importe);
            $manual = [];
            foreach ($cuotas->fresh(['plan']) as $cuota) {
                if ($restante->isNegativeOrZero() || ! $cuota->estado->esAdeudable()) {
                    continue;
                }
                $deuda = BigDecimal::of($this->resolver->deuda($cuota, $ingreso->fecha));
                $aplicado = $restante->isLessThan($deuda) ? $restante : $deuda;
                if ($aplicado->isPositive()) {
                    $manual[$cuota->id] = (string) $aplicado->toScale(2, RoundingMode::HALF_UP);
                    $restante = $restante->minus($aplicado);
                }
            }

            $imputaciones = $this->imputador->validarManual($pagador, (string) $ingreso->importe, $manual, $ingreso->fecha);

            $pago = Pago::create([
                'socio_id' => $pagador->id, 'fecha' => $ingreso->fecha->toDateString(), 'importe_bruto' => $ingreso->importe, 'medio_pago_id' => $ingreso->medio_pago_id,
                'cuenta_id' => $ingreso->cuenta_id, 'estado' => EstadoPago::Confirmado, 'origen' => OrigenPago::Importacion, 'concepto' => $ingreso->concepto, 'confirmado_at' => now(),
            ]);
            $this->imputador->aplicar($pago, $imputaciones);

            Movimiento::withoutEvents(function () use ($ingreso, $pago) {
                $ingreso->update(['origenable_type' => $pago->getMorphClass(), 'origenable_id' => $pago->id]);
                $ingreso->asiento?->update(['tipo' => TipoAsiento::CobroCuota]);
            });

            return $pago->load('imputaciones');
        });
    }

    /** @param list<string> $periodos */
    private function lista(array $periodos): string
    {
        return implode(', ', $periodos);
    }
}
