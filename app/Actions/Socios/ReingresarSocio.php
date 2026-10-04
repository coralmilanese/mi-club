<?php

namespace App\Actions\Socios;

use App\Enums\EstadoSocio;
use App\Models\Socio;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReingresarSocio
{
    public function __construct(private CambiarEstadoSocio $cambiarEstado) {}

    public function __invoke(Socio $socio, CarbonInterface $fecha, ?string $motivo = null): Socio
    {
        if (! $socio->estaDeBaja()) {
            throw ValidationException::withMessages(['fecha' => 'Solo se puede reingresar a un socio dado de baja.']);
        }

        return DB::transaction(function () use ($socio, $fecha, $motivo) {
            ($this->cambiarEstado)($socio, EstadoSocio::Activo, $fecha, $motivo ?? 'Reingreso');

            // La baja anterior queda en el historial de estados; la ficha vuelve a estar vigente.
            $socio->forceFill(['fecha_baja' => null, 'motivo_baja' => null])->save();

            return $socio;
        });
    }
}
