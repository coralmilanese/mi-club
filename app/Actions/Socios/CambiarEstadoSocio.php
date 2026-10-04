<?php

namespace App\Actions\Socios;

use App\Enums\EstadoSocio;
use App\Models\Socio;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Único punto de escritura de socios.estado: cierra el tramo de estado abierto,
 * abre uno nuevo y mantiene la denormalización en la fila del socio.
 */
class CambiarEstadoSocio
{
    public function __invoke(Socio $socio, EstadoSocio $nuevo, CarbonInterface $desde, ?string $motivo = null): Socio
    {
        return DB::transaction(function () use ($socio, $nuevo, $desde, $motivo) {
            $socio->estados()->whereNull('hasta')->update(['hasta' => $desde->toDateString()]);
            $socio->estados()->create(['estado' => $nuevo, 'desde' => $desde->toDateString(), 'motivo' => $motivo]);

            $socio->forceFill(['estado' => $nuevo])->save();

            return $socio;
        });
    }
}
