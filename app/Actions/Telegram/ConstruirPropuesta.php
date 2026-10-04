<?php

namespace App\Actions\Telegram;

use App\Models\IngestaTelegram;
use App\Models\Socio;
use App\Services\Pagos\ImputadorDePagos;
use Carbon\CarbonImmutable;

/**
 * Recalcula la propuesta de imputación para una ingesta (al matchear el socio, o tras un cambio de socio/fecha)
 * y la deja guardada en extraccion_json, lista para mostrarse y para confirmarse tal cual.
 */
class ConstruirPropuesta
{
    public function __construct(private ImputadorDePagos $imputador) {}

    /** @return array<string, mixed> */
    public function __invoke(IngestaTelegram $ingesta, Socio $socio, string $importe, CarbonImmutable $fecha): array
    {
        $propuesta = $this->imputador->proponer($socio, $importe, $fecha);

        $datos = $ingesta->extraccion_json ?? [];
        $datos['socio_id'] = $socio->id;
        $datos['importe'] = $importe;
        $datos['fecha'] = $fecha->toDateString();
        $datos['imputaciones'] = array_map(fn ($i) => ['cuota_id' => $i['cuota']->id, 'importe' => $i['importe'], 'completa' => $i['completa']], $propuesta['imputaciones']);
        $datos['sobrante'] = $propuesta['sobrante'];

        $ingesta->update(['extraccion_json' => $datos, 'socio_sugerido_id' => $socio->id]);

        return $datos;
    }
}
