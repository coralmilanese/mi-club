<?php

namespace App\Services\Importacion;

use App\Models\Socio;
use App\Services\Socios\BuscadorPorNombre;

/**
 * Empareja cada fila de la grilla de cuotas (nombre en texto libre) con un socio del padrón.
 * Si dos filas apuntan al mismo socio, gana la de mayor similitud.
 */
class EmparejadorGrilla
{
    /** Similitud a partir de la cual el emparejamiento se considera confiable. */
    public const UMBRAL_CONFIABLE = 0.6;

    /** Similitud mínima para siquiera proponerlo como candidato. */
    public const UMBRAL_MINIMO = 0.3;

    public function __construct(private BuscadorPorNombre $buscador) {}

    /**
     * @param  list<array{nombre: string}>  $grilla
     * @return array{por_fila: array<int, array{socio: Socio, score: float}>, por_socio: array<int, int>}
     */
    public function emparejar(array $grilla): array
    {
        $porFila = [];
        foreach ($grilla as $idx => $fila) {
            $mejor = $this->buscador->candidatos($fila['nombre'], 1, self::UMBRAL_MINIMO)->first();
            if ($mejor) {
                $porFila[$idx] = $mejor;
            }
        }

        $porSocio = [];
        foreach ($porFila as $idx => $m) {
            $actual = $porSocio[$m['socio']->id] ?? null;
            if ($actual === null || $porFila[$actual]['score'] < $m['score']) {
                $porSocio[$m['socio']->id] = $idx;
            }
        }

        return ['por_fila' => $porFila, 'por_socio' => $porSocio];
    }

    /** ¿La fila `$idx` quedó emparejada de forma confiable con su socio? */
    public function esConfiable(array $asignacion, int $idx): bool
    {
        $m = $asignacion['por_fila'][$idx] ?? null;

        return $m !== null && $m['score'] >= self::UMBRAL_CONFIABLE && ($asignacion['por_socio'][$m['socio']->id] ?? null) === $idx;
    }
}
