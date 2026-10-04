<?php

namespace App\Actions\Cuotas;

use App\Enums\EstadoCuota;
use App\Enums\EstadoSocio;
use App\Models\Cuota;
use App\Models\SocioPlan;
use App\Services\Pagos\ImputadorDePagos;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Devenga la cuota mensual de todos los socios con un plan que genere cuota en el periodo.
 * Idempotente (unique socio + periodo) y con modo simulación.
 */
class DevengarCuotas
{
    public function __construct(private ImputadorDePagos $imputador) {}

    /**
     * @return array{periodo: string, creadas: int, existentes: int, detalle: list<array<string, mixed>>, advertencias: list<string>}
     */
    public function __invoke(CarbonInterface $periodo, bool $dryRun = false): array
    {
        $inicio = $periodo->copy()->startOfMonth();
        $fin = $periodo->copy()->endOfMonth();

        $asignaciones = SocioPlan::query()
            ->with(['plan', 'socio.grupoFamiliar'])
            ->whereDate('desde', '<=', $fin->toDateString())
            ->where(fn ($q) => $q->whereNull('hasta')->orWhereDate('hasta', '>=', $inicio->toDateString()))
            ->orderBy('desde')
            ->get()
            // Si en el mismo mes hubo un cambio de plan, manda el más reciente.
            ->groupBy('socio_id')
            ->map(fn ($grupo) => $grupo->last())
            ->filter(fn (SocioPlan $a) => $a->plan->genera_cuota && $a->plan->activo)
            ->sortBy(fn (SocioPlan $a) => $a->socio->nombre_completo);

        $resultado = ['periodo' => $inicio->toDateString(), 'creadas' => 0, 'existentes' => 0, 'detalle' => [], 'advertencias' => []];

        DB::transaction(function () use ($asignaciones, $inicio, $dryRun, &$resultado) {
            foreach ($asignaciones as $asignacion) {
                $socio = $asignacion->socio;
                $plan = $asignacion->plan;

                if ($socio->estado === EstadoSocio::Baja && ($socio->fecha_baja === null || $socio->fecha_baja->lte($inicio))) {
                    continue;
                }

                if (Cuota::where('socio_id', $socio->id)->whereDate('periodo', $inicio->toDateString())->exists()) {
                    $resultado['existentes']++;

                    continue;
                }

                $tarifa = $plan->tarifaVigente($inicio);
                if (! $tarifa) {
                    $resultado['advertencias'][] = "{$socio->nombre_completo}: el plan {$plan->nombre} no tiene tarifa vigente al {$inicio->format('d/m/Y')}; no se devengó.";

                    continue;
                }

                // El adherente genera su propia cuota de "adicional familiar", pero la paga el titular del grupo.
                $pagadorId = null;
                if ($plan->es_adicional_familiar) {
                    $pagadorId = $socio->grupoFamiliar?->titular_socio_id;
                    if ($pagadorId === null || $pagadorId === $socio->id) {
                        $pagadorId = null;
                        $resultado['advertencias'][] = "{$socio->nombre_completo}: tiene plan adicional familiar pero no pertenece a un grupo con titular; queda a su nombre.";
                    }
                }

                if (! $dryRun) {
                    $cuota = Cuota::create([
                        'socio_id' => $socio->id,
                        'periodo' => $inicio->toDateString(),
                        'plan_id' => $plan->id,
                        'importe_devengado' => $tarifa->importe,
                        'tarifa_plan_id_devengada' => $tarifa->id,
                        'importe_imputado' => 0,
                        'estado' => EstadoCuota::Pendiente,
                        'socio_pagador_id' => $pagadorId,
                    ]);

                    // Pago adelantado: si el pagador tiene saldo a favor, se imputa a la cuota recién devengada.
                    $this->imputador->aplicarSaldoAFavor($cuota);
                }

                $resultado['creadas']++;
                $resultado['detalle'][] = [
                    'socio' => $socio->nombre_completo,
                    'plan' => $plan->nombre,
                    'importe' => (string) $tarifa->importe,
                    'pagador_id' => $pagadorId,
                ];
            }
        });

        return $resultado;
    }
}
