<?php

namespace App\Services\Ia;

use App\Models\IngestaTelegram;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Estima el costo en USD de una respuesta de IA y controla el tope mensual configurado (D4).
 * Precios aproximados por modelo: son una estimación para el tope de gasto, no una factura.
 */
class CostoIaService
{
    /** USD por millón de tokens: [entrada, salida]. Ajustable sin tocar código (ver config/aasr.php). */
    private const PRECIOS_USD_POR_MILLON = [
        'anthropic/claude-sonnet-5' => [3.0, 15.0],
        'anthropic/claude-haiku-4.5' => [1.0, 5.0],
        'anthropic/claude-opus-5' => [15.0, 75.0],
    ];

    public function estimar(string $modelo, Usage $uso): float
    {
        [$entrada, $salida] = self::PRECIOS_USD_POR_MILLON[$modelo] ?? [3.0, 15.0];

        return round(($uso->promptTokens * $entrada + $uso->completionTokens * $salida) / 1_000_000, 6);
    }

    public function gastadoEsteMes(): float
    {
        return (float) IngestaTelegram::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('costo_usd');
    }

    public function superoTope(): bool
    {
        $tope = (float) config('aasr.ia.max_costo_usd_mes');

        return $tope > 0 && $this->gastadoEsteMes() >= $tope;
    }

    public function registrar(IngestaTelegram $ingesta, string $modelo, Usage $uso): void
    {
        $ingesta->update([
            'modelo_ia' => $modelo,
            'tokens_entrada' => $uso->promptTokens,
            'tokens_salida' => $uso->completionTokens,
            'costo_usd' => $this->estimar($modelo, $uso),
        ]);
    }
}
