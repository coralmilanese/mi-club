<?php

namespace App\Actions\Planes;

use App\Models\Plan;
use App\Models\Socio;
use App\Models\SocioPlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Asigna un plan a un socio conservando el historial: cierra el tramo vigente y abre uno nuevo. */
class AsignarPlanSocio
{
    public function __invoke(Socio $socio, Plan $plan, CarbonInterface $desde, ?string $motivo = null): SocioPlan
    {
        if ($socio->estaDeBaja()) {
            throw ValidationException::withMessages(['plan_id' => "{$socio->nombre_completo} está dado de baja."]);
        }

        return DB::transaction(function () use ($socio, $plan, $desde, $motivo) {
            $vigente = SocioPlan::where('socio_id', $socio->id)->whereNull('hasta')->lockForUpdate()->first();

            if ($vigente) {
                if ($vigente->plan_id === $plan->id) {
                    throw ValidationException::withMessages(['plan_id' => "{$socio->nombre_completo} ya tiene el plan {$plan->nombre}."]);
                }
                if ($desde->toDateString() <= $vigente->desde->toDateString()) {
                    throw ValidationException::withMessages(['desde' => "{$socio->nombre_completo}: la fecha debe ser posterior al inicio del plan actual (".$vigente->desde->format('d/m/Y').').']);
                }
                $vigente->update(['hasta' => $desde->copy()->subDay()->toDateString()]);
            }

            return SocioPlan::create([
                'socio_id' => $socio->id,
                'plan_id' => $plan->id,
                'desde' => $desde->toDateString(),
                'motivo' => $motivo,
            ]);
        });
    }
}
