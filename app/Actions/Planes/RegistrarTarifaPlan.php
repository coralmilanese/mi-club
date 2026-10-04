<?php

namespace App\Actions\Planes;

use App\Models\Plan;
use App\Models\TarifaPlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Las tarifas nunca se editan: se sucede una nueva y se cierra la anterior (hasta = nueva − 1 día).
 * Siempre debe empezar después de la última vigencia cargada, así no puede haber solapamientos.
 */
class RegistrarTarifaPlan
{
    public function __invoke(Plan $plan, string $importe, CarbonInterface $desde, ?int $userId = null): TarifaPlan
    {
        return DB::transaction(function () use ($plan, $importe, $desde, $userId) {
            $ultima = $plan->tarifas()->reorder('vigencia_desde', 'desc')->lockForUpdate()->first();

            if ($ultima && $desde->toDateString() <= $ultima->vigencia_desde->toDateString()) {
                throw ValidationException::withMessages([
                    'vigencia_desde' => 'La nueva tarifa debe empezar después del '.$ultima->vigencia_desde->format('d/m/Y').' (última vigencia cargada).',
                ]);
            }

            if ($ultima && ($ultima->vigencia_hasta === null || $ultima->vigencia_hasta->gte($desde))) {
                $ultima->update(['vigencia_hasta' => $desde->copy()->subDay()->toDateString()]);
            }

            return $plan->tarifas()->create([
                'importe' => $importe,
                'vigencia_desde' => $desde->toDateString(),
                'creado_por_user_id' => $userId,
            ]);
        });
    }
}
