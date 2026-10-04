<?php

namespace App\Actions\Tributos;

use App\Models\AlicuotaTributo;
use App\Models\Tributo;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Igual que las tarifas de cuota: la alícuota no se edita, se sucede una nueva con su vigencia. */
class RegistrarAlicuota
{
    public function __invoke(Tributo $tributo, string $alicuota, CarbonInterface $desde): AlicuotaTributo
    {
        return DB::transaction(function () use ($tributo, $alicuota, $desde) {
            $ultima = $tributo->alicuotas()->reorder('vigencia_desde', 'desc')->lockForUpdate()->first();

            if ($ultima && $desde->toDateString() <= $ultima->vigencia_desde->toDateString()) {
                throw ValidationException::withMessages([
                    'vigencia_desde' => 'La nueva alícuota debe empezar después del '.$ultima->vigencia_desde->format('d/m/Y').'.',
                ]);
            }

            if ($ultima && ($ultima->vigencia_hasta === null || $ultima->vigencia_hasta->gte($desde))) {
                $ultima->update(['vigencia_hasta' => $desde->copy()->subDay()->toDateString()]);
            }

            return $tributo->alicuotas()->create(['alicuota' => $alicuota, 'vigencia_desde' => $desde->toDateString()]);
        });
    }
}
