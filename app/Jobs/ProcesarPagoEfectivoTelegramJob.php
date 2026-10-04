<?php

namespace App\Jobs;

use App\Actions\Telegram\ConstruirPropuesta;
use App\Actions\Telegram\RedactarMensajeConfirmacion;
use App\Ai\Agents\ExtractorPagoEfectivo;
use App\Enums\EstadoIngesta;
use App\Models\IngestaTelegram;
use App\Services\Ia\CostoIaService;
use App\Services\Socios\MatcherSocios;
use App\Services\Telegram\TecladosTelegram;
use App\Services\Telegram\TelegramClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Interpreta un mensaje de texto sobre un cobro en efectivo ("Juan Pérez pagó 35 mil julio") con un modelo
 * de texto barato, matchea el socio y propone la imputación. Mismo patrón que el comprobante, sin archivo.
 */
class ProcesarPagoEfectivoTelegramJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $ingestaId) {}

    public function handle(
        TelegramClient $telegram,
        MatcherSocios $matcher,
        ConstruirPropuesta $construirPropuesta,
        RedactarMensajeConfirmacion $redactar,
        TecladosTelegram $teclados,
        CostoIaService $costo,
    ): void {
        $ingesta = IngestaTelegram::with('chat')->findOrFail($this->ingestaId);
        $ingesta->update(['estado' => EstadoIngesta::Procesando]);
        $chatId = $ingesta->chat->chat_id;

        if ($costo->superoTope()) {
            $ingesta->update(['estado' => EstadoIngesta::Error, 'error_mensaje' => 'Tope de gasto de IA del mes superado']);
            $telegram->enviarMensaje($chatId, '⚠️ Se superó el tope de gasto de IA de este mes. Cargalo a mano desde el sistema: '.route('pagos.create'));

            return;
        }

        $respuesta = (new ExtractorPagoEfectivo)->prompt(
            $ingesta->texto_original ?? '',
            provider: 'openrouter',
            model: config('aasr.ia.modelo_texto'),
        );
        if (! $respuesta instanceof StructuredAgentResponse) {
            throw new \RuntimeException('El proveedor de IA no devolvió una salida estructurada.');
        }
        $r = $respuesta;

        $modelo = config('aasr.ia.modelo_texto');
        $costo->registrar($ingesta, $modelo, $r->usage);
        $ingesta->update(['confianza' => $r['confianza'] ?? 0]);

        if (! ($r['es_pago'] ?? false) || empty($r['socio_texto']) || empty($r['importe'])) {
            $ingesta->update(['estado' => EstadoIngesta::Descartada, 'extraccion_json' => $r->structured, 'error_mensaje' => 'No parece un aviso de cobro']);
            $telegram->enviarMensaje($chatId, '🤔 No entendí que sea un cobro. Si lo es, probá de nuevo con el nombre, el importe y el mes (ej.: "Juan Pérez pagó 35000 julio").');

            return;
        }

        $ingesta->update(['extraccion_json' => $r->structured]);

        $candidatos = $matcher->candidatos($r['socio_texto']);
        $ingesta->update(['candidatos_json' => $candidatos->map(fn ($c) => ['socio_id' => $c['socio']->id, 'score' => $c['score'], 'motivo' => $c['motivo']])->values()->all()]);

        $mejor = $candidatos->first();
        if (! $mejor || $mejor['score'] < 0.5) {
            $ingesta->update(['estado' => EstadoIngesta::EsperandoSocio]);
            $telegram->enviarMensaje($chatId, "🤷 No pude identificar a \"{$r['socio_texto']}\". Escribime el nombre completo.");

            return;
        }

        $fecha = ! empty($r['fecha']) ? CarbonImmutable::parse($r['fecha']) : CarbonImmutable::now();
        $construirPropuesta($ingesta, $mejor['socio'], (string) $r['importe'], $fecha);

        if ($mejor['score'] < 0.85 || ($candidatos->count() > 1 && $candidatos->values()[1]['score'] >= 0.5)) {
            $ingesta->update(['estado' => EstadoIngesta::EsperandoSocio]);
            $telegram->enviarMensaje($chatId, '¿Cuál de estos socios es?', $teclados->candidatosSocio($ingesta, $candidatos->take(5)->values()));

            return;
        }

        $ingesta->update(['estado' => EstadoIngesta::EsperandoConfirmacion]);
        $telegram->enviarMensaje($chatId, "💵 <b>Pago en efectivo</b>\n".$redactar($ingesta), $teclados->confirmacion($ingesta));
    }

    public function failed(\Throwable $e): void
    {
        $ingesta = IngestaTelegram::with('chat')->find($this->ingestaId);
        if (! $ingesta) {
            return;
        }
        $ingesta->update(['estado' => EstadoIngesta::Error, 'error_mensaje' => $e->getMessage()]);
        app(TelegramClient::class)->enviarMensaje($ingesta->chat->chat_id, '⚠️ No pude procesar el mensaje, cargalo a mano desde el sistema: '.route('pagos.create'));
    }
}
