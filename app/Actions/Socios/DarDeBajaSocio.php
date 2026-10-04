<?php

namespace App\Actions\Socios;

use App\Enums\EstadoSocio;
use App\Models\GrupoFamiliar;
use App\Models\Socio;
use App\Models\SocioPlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DarDeBajaSocio
{
    public function __construct(private CambiarEstadoSocio $cambiarEstado) {}

    public function __invoke(Socio $socio, CarbonInterface $fecha, string $motivo): Socio
    {
        if ($socio->estaDeBaja()) {
            throw ValidationException::withMessages(['fecha' => 'El socio ya está dado de baja.']);
        }

        // Un titular no puede irse dejando adherentes colgados del grupo.
        $grupoTitulado = GrupoFamiliar::where('titular_socio_id', $socio->id)->withCount('adherentes')->first();
        if ($grupoTitulado && $grupoTitulado->adherentes_count > 0) {
            throw ValidationException::withMessages([
                'fecha' => 'Es titular de un grupo familiar con adherentes. Reasigná el titular o disolvé el grupo antes de darlo de baja.',
            ]);
        }

        return DB::transaction(function () use ($socio, $fecha, $motivo) {
            ($this->cambiarEstado)($socio, EstadoSocio::Baja, $fecha, $motivo);

            // Deja de devengar desde la fecha de baja: el plan vigente se cierra el día anterior.
            SocioPlan::where('socio_id', $socio->id)->whereNull('hasta')->update(['hasta' => $fecha->copy()->subDay()->toDateString()]);

            $socio->forceFill(['fecha_baja' => $fecha->toDateString(), 'motivo_baja' => $motivo])->save();

            return $socio;
        });
    }
}
