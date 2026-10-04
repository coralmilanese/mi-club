<?php

namespace App\Actions\Telegram;

use App\Enums\EstadoIngesta;
use App\Models\IngestaTelegram;
use App\Models\Socio;
use App\Services\Socios\MatcherSocios;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Resuelve la respuesta de texto que el tesorero manda cuando la ingesta está `esperando_socio` o
 * `esperando_fecha`. Es la contraparte de ProcesarComprobanteTelegramJob para el camino "a mano".
 */
class ResolverRespuestaTexto
{
    public function __construct(private MatcherSocios $matcher, private ConstruirPropuesta $construirPropuesta) {}

    /** @return array{tipo: 'propuesta'|'candidatos'|'error', mensaje?: string, candidatos?: Collection} */
    public function __invoke(IngestaTelegram $ingesta, string $texto): array
    {
        return match ($ingesta->estado) {
            EstadoIngesta::EsperandoFecha => $this->resolverFecha($ingesta, $texto),
            EstadoIngesta::EsperandoSocio => $this->resolverSocio($ingesta, $texto),
            default => ['tipo' => 'error', 'mensaje' => 'No esperaba ningún dato tuyo ahora mismo.'],
        };
    }

    private function resolverFecha(IngestaTelegram $ingesta, string $texto): array
    {
        try {
            $fecha = CarbonImmutable::parse($this->normalizarFecha($texto));
        } catch (\Throwable) {
            return ['tipo' => 'error', 'mensaje' => 'No entendí la fecha. Escribila como DD/MM/AAAA.'];
        }

        $d = $ingesta->extraccion_json ?? [];
        $socio = Socio::find($d['socio_id'] ?? null);
        if (! $socio) {
            return ['tipo' => 'error', 'mensaje' => 'Perdí de vista al socio: mandá el comprobante de nuevo.'];
        }

        ($this->construirPropuesta)($ingesta, $socio, (string) ($d['importe'] ?? '0'), $fecha);
        $ingesta->update(['estado' => EstadoIngesta::EsperandoConfirmacion]);

        return ['tipo' => 'propuesta'];
    }

    private function resolverSocio(IngestaTelegram $ingesta, string $texto): array
    {
        $candidatos = $this->matcher->candidatos($texto);
        $mejor = $candidatos->first();
        if (! $mejor) {
            return ['tipo' => 'error', 'mensaje' => 'Sigo sin encontrarlo. Probá con otro nombre.'];
        }
        if ($mejor['score'] < 0.85 || ($candidatos->count() > 1 && $candidatos->values()[1]['score'] >= 0.5)) {
            $ingesta->update(['candidatos_json' => $candidatos->map(fn ($c) => ['socio_id' => $c['socio']->id, 'score' => $c['score'], 'motivo' => $c['motivo']])->values()->all()]);

            return ['tipo' => 'candidatos', 'candidatos' => $candidatos->take(5)->values()];
        }

        $d = $ingesta->extraccion_json ?? [];
        ($this->construirPropuesta)($ingesta, $mejor['socio'], (string) ($d['importe'] ?? '0'), CarbonImmutable::parse($d['fecha'] ?? now()->toDateString()));
        $ingesta->update(['estado' => EstadoIngesta::EsperandoConfirmacion]);

        return ['tipo' => 'propuesta'];
    }

    private function normalizarFecha(string $texto): string
    {
        $t = mb_strtolower(trim($texto));

        return match (true) {
            $t === 'hoy' => now()->toDateString(),
            $t === 'ayer' => now()->subDay()->toDateString(),
            (bool) preg_match('#^(\d{1,2})[/-](\d{1,2})(?:[/-](\d{2,4}))?$#', $t, $m) => sprintf(
                '%04d-%02d-%02d',
                isset($m[3]) ? (strlen($m[3]) === 2 ? (int) ('20'.$m[3]) : (int) $m[3]) : now()->year,
                (int) $m[2],
                (int) $m[1],
            ),
            default => CarbonImmutable::parse($t)->toDateString(),
        };
    }
}
