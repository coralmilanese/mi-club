<?php

namespace App\Services\Pagos;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPago;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\PagoCuota;
use App\Models\Socio;
use App\Services\Cuotas\ResolverImporteExigible;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Imputa pagos a cuotas. Las cuotas impagas se valúan a la tarifa vigente el día del pago; una cuota que se termina
 * de cobrar queda congelada (`importe_cobrado`). Lo que sobra queda como saldo a favor del pagador.
 */
class ImputadorDePagos
{
    public function __construct(private ResolverImporteExigible $resolver) {}

    /**
     * Cuotas que paga un socio, más antiguas primero: las propias y las de sus adherentes (adicional familiar).
     *
     * @return Collection<int, Cuota>
     */
    public function cuotasAdeudadas(Socio $pagador): Collection
    {
        return Cuota::with(['plan', 'socio'])
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('socio_id', $pagador->id)->whereNull('socio_pagador_id'))
                ->orWhere('socio_pagador_id', $pagador->id))
            ->whereIn('estado', [EstadoCuota::Pendiente->value, EstadoCuota::Parcial->value])
            ->orderBy('periodo')->orderBy('id')
            ->get();
    }

    /**
     * Propuesta automática: cuotas más antiguas primero, valuadas a la tarifa de `$fecha`.
     *
     * @return array{imputaciones: list<array{cuota: Cuota, importe: string, completa: bool, deuda: string}>, sobrante: string, exacto: bool}
     */
    public function proponer(Socio $pagador, string $importe, CarbonInterface $fecha): array
    {
        $restante = BigDecimal::of($importe);
        $imputaciones = [];

        foreach ($this->cuotasAdeudadas($pagador) as $cuota) {
            if ($restante->isNegativeOrZero()) {
                break;
            }
            $deuda = BigDecimal::of($this->resolver->deuda($cuota, $fecha));
            if ($deuda->isNegativeOrZero()) {
                continue;
            }
            $aplicado = $restante->isLessThan($deuda) ? $restante : $deuda;
            $imputaciones[] = ['cuota' => $cuota, 'importe' => $this->fmt($aplicado), 'completa' => $aplicado->isEqualTo($deuda), 'deuda' => $this->fmt($deuda)];
            $restante = $restante->minus($aplicado);
        }

        $ultima = $imputaciones ? end($imputaciones) : null;

        return [
            'imputaciones' => $imputaciones,
            'sobrante' => $this->fmt($restante),
            'exacto' => $restante->isZero() && ($ultima === null || $ultima['completa']),
        ];
    }

    /**
     * Valida una imputación manual (cuota_id => importe) contra lo que el pagador realmente debe.
     *
     * @param  array<int|string, string|int|float>  $manual
     * @return list<array{cuota: Cuota, importe: string, completa: bool, deuda: string}>
     */
    public function validarManual(Socio $pagador, string $importeBruto, array $manual, CarbonInterface $fecha): array
    {
        $adeudadas = $this->cuotasAdeudadas($pagador)->keyBy('id');
        $total = BigDecimal::zero();
        $resultado = [];

        foreach ($manual as $cuotaId => $importe) {
            $importe = BigDecimal::of((string) $importe);
            if ($importe->isNegativeOrZero()) {
                continue;
            }
            $cuota = $adeudadas[(int) $cuotaId] ?? null;
            if (! $cuota) {
                throw ValidationException::withMessages(['imputaciones' => "La cuota {$cuotaId} no está pendiente de {$pagador->nombre_completo} (o ya está cobrada)."]);
            }
            $deuda = BigDecimal::of($this->resolver->deuda($cuota, $fecha));
            if ($importe->isGreaterThan($deuda)) {
                throw ValidationException::withMessages(['imputaciones' => "{$cuota->periodo->translatedFormat('F Y')}: se imputan {$this->fmt($importe)} pero la cuota debe {$this->fmt($deuda)}."]);
            }
            $total = $total->plus($importe);
            $resultado[] = ['cuota' => $cuota, 'importe' => $this->fmt($importe), 'completa' => $importe->isEqualTo($deuda), 'deuda' => $this->fmt($deuda)];
        }

        if ($total->isGreaterThan($importeBruto)) {
            throw ValidationException::withMessages(['imputaciones' => "Se imputan {$this->fmt($total)} pero el pago es de {$importeBruto}."]);
        }

        return $resultado;
    }

    /**
     * Aplica la imputación: actualiza cada cuota y deja registrado el PagoCuota.
     *
     * @param  list<array{cuota: Cuota, importe: string, completa: bool, deuda: string}>  $imputaciones
     */
    public function aplicar(Pago $pago, array $imputaciones): void
    {
        foreach ($imputaciones as $i) {
            $this->aplicarACuota($pago, Cuota::whereKey($i['cuota']->id)->lockForUpdate()->with('plan')->firstOrFail(), $i['importe']);
        }
    }

    /** Saldo a favor total de un socio: lo pagado y aún no imputado, sobre pagos confirmados. */
    public function saldoAFavor(Socio $pagador): string
    {
        $pagos = Pago::where('socio_id', $pagador->id)->where('estado', EstadoPago::Confirmado->value);
        $bruto = BigDecimal::of((string) $pagos->clone()->sum('importe_bruto'));
        $imputado = BigDecimal::of((string) PagoCuota::whereIn('pago_id', $pagos->clone()->select('id'))->sum('importe_imputado'));

        return $this->fmt($bruto->minus($imputado));
    }

    /** Al devengar una cuota, si el pagador tiene saldo a favor se imputa solo (pago adelantado). */
    public function aplicarSaldoAFavor(Cuota $cuota): void
    {
        $pagadorId = $cuota->socio_pagador_id ?? $cuota->socio_id;
        $hoy = now();

        $pagos = Pago::where('socio_id', $pagadorId)->where('estado', EstadoPago::Confirmado->value)->orderBy('fecha')->orderBy('id')->get();
        foreach ($pagos as $pago) {
            $cuota->refresh()->load('plan');
            $deuda = BigDecimal::of($this->resolver->deuda($cuota, $hoy));
            if ($deuda->isNegativeOrZero()) {
                return;
            }
            $sobrante = BigDecimal::of($pago->sobrante());
            if ($sobrante->isNegativeOrZero()) {
                continue;
            }
            $this->aplicarACuota($pago, $cuota, $this->fmt($sobrante->isLessThan($deuda) ? $sobrante : $deuda), $hoy);
        }
    }

    private function aplicarACuota(Pago $pago, Cuota $cuota, string $importe, ?CarbonInterface $fechaValuacion = null): void
    {
        $fecha = $fechaValuacion ?? $pago->fecha;
        $exigible = BigDecimal::of($this->resolver->importe($cuota, $fecha));
        $nuevo = BigDecimal::of((string) $cuota->importe_imputado)->plus($importe);

        if ($nuevo->isGreaterThanOrEqualTo($exigible)) {
            // Cobrada: se congela al valor exigible del momento y no se mueve más ante aumentos futuros.
            $cuota->fill([
                'estado' => EstadoCuota::Pagada,
                'importe_cobrado' => $this->fmt($exigible),
                'tarifa_plan_id_cobrada' => $cuota->plan->tarifaVigente($fecha)?->id,
                'importe_imputado' => $this->fmt($nuevo),
                'fecha_cancelacion' => $fecha->toDateString(),
            ]);
        } else {
            $cuota->fill(['estado' => EstadoCuota::Parcial, 'importe_imputado' => $this->fmt($nuevo)]);
        }
        $cuota->save();

        PagoCuota::create(['pago_id' => $pago->id, 'cuota_id' => $cuota->id, 'importe_imputado' => $importe]);
    }

    /** Deshace una imputación (anulación de un pago): la cuota vuelve a estar pendiente o parcial y se descongela. */
    public function revertir(PagoCuota $imputacion): void
    {
        $cuota = Cuota::whereKey($imputacion->cuota_id)->lockForUpdate()->firstOrFail();
        $resto = BigDecimal::of((string) $cuota->importe_imputado)->minus($imputacion->importe_imputado);
        $resto = $resto->isNegative() ? BigDecimal::zero() : $resto;

        $cuota->fill([
            'importe_imputado' => $this->fmt($resto),
            'estado' => $resto->isZero() ? EstadoCuota::Pendiente : EstadoCuota::Parcial,
            'importe_cobrado' => null,
            'tarifa_plan_id_cobrada' => null,
            'fecha_cancelacion' => null,
        ])->save();
    }

    private function fmt(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HALF_UP);
    }
}
