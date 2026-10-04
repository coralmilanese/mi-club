<?php

namespace App\Actions\Socios;

use App\Enums\EstadoSocio;
use App\Models\Socio;
use Illuminate\Support\Facades\DB;

class AltaSocio
{
    /** @param array<string, mixed> $datos */
    public function __invoke(array $datos): Socio
    {
        return DB::transaction(function () use ($datos) {
            $estado = $datos['estado'] ?? EstadoSocio::Alta;
            $estado = $estado instanceof EstadoSocio ? $estado : EstadoSocio::from($estado);
            // Fecha en la que arranca el estado inicial (por defecto, la de asociación o hoy).
            $desdeEstado = $datos['desde_estado'] ?? null;
            unset($datos['desde_estado']);

            $socio = Socio::create([...$datos, 'estado' => $estado]);

            $socio->estados()->create([
                'estado' => $estado,
                'desde' => $desdeEstado ?? $socio->fecha_asociacion?->toDateString() ?? now()->toDateString(),
                'motivo' => 'Alta',
            ]);

            return $socio;
        });
    }
}
