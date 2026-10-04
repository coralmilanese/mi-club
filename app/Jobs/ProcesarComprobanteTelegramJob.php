<?php

namespace App\Jobs;

use App\Actions\Comprobantes\GuardarComprobante;
use App\Actions\Telegram\ConstruirPropuesta;
use App\Actions\Telegram\RedactarMensajeConfirmacion;
use App\Ai\Agents\ExtractorComprobante;
use App\Enums\EstadoIngesta;
use App\Models\IngestaTelegram;
use App\Services\Ia\CostoIaService;
use App\Services\Socios\MatcherSocios;
use App\Services\Telegram\TecladosTelegram;
use App\Services\Telegram\TelegramClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Files;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Procesa una foto o PDF de comprobante: lo descarga y guarda, lo manda a la IA (sólo extrae texto, nunca
 * elige al socio), matchea contra el padrón con código determinístico y propone la imputación. Nada se
 * impacta en el libro de caja todavía: el tesorero confirma con el botón (ver ConfirmarIngesta).
 */
class ProcesarComprobanteTelegramJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $ingestaId) {}

    public function handle(
        TelegramClient $telegram,
        GuardarComprobante $guardar,
        MatcherSocios $matcher,
        ConstruirPropuesta $construirPropuesta,
        RedactarMensajeConfirmacion $redactar,
        TecladosTelegram $teclados,
        CostoIaService $costo,
    ): void {
        $ingesta = IngestaTelegram::with('chat')->findOrFail($this->ingestaId);
        $ingesta->update(['estado' => EstadoIngesta::Procesando]);
        $chatId = $ingesta->chat->chat_id;

        // Dedup: el mismo archivo (incluso de otro mensaje) ya se cargó antes.
        $telegramFileId = $ingesta->extraccion_json['_telegram_file_id'] ?? null;
        $contenido = $telegram->descargarArchivo($telegramFileId ?? '');
        $extension = $ingesta->tipo->value === 'foto' ? 'jpg' : 'pdf';
        $mime = $ingesta->tipo->value === 'foto' ? 'image/jpeg' : 'application/pdf';

        $archivo = $guardar->desdeContenido($contenido, $extension, $mime, 'telegram', $telegramFileId, $ingesta->message_id);
        $ingesta->update(['comprobante_id' => $archivo->id]);

        if ($archivo->comprobantable_id !== null) {
            $ingesta->update(['estado' => EstadoIngesta::Descartada, 'error_mensaje' => 'Comprobante duplicado']);
            $telegram->enviarMensaje($chatId, '⚠️ Ya habías cargado este comprobante antes. No hice nada.');

            return;
        }

        if ($costo->superoTope()) {
            $ingesta->update(['estado' => EstadoIngesta::Error, 'error_mensaje' => 'Tope de gasto de IA del mes superado']);
            $telegram->enviarMensaje($chatId, '⚠️ Se superó el tope de gasto de IA de este mes. Cargalo a mano desde el sistema: '.route('pagos.create'));

            return;
        }

        $agente = new ExtractorComprobante;
        $adjunto = $ingesta->tipo->value === 'foto'
            ? Files\Image::fromStorage($archivo->archivo_path, $archivo->disco)
            : Files\Document::fromStorage($archivo->archivo_path, $archivo->disco);

        $respuesta = $agente->prompt(
            'Extraé los datos de este comprobante de transferencia bancaria argentino.',
            attachments: [$adjunto],
            provider: 'openrouter',
            model: config('aasr.ia.modelo_vision'),
        );

        // El agente implementa HasStructuredOutput: esto siempre debería ser un StructuredAgentResponse.
        if (! $respuesta instanceof StructuredAgentResponse) {
            throw new \RuntimeException('El proveedor de IA no devolvió una salida estructurada.');
        }
        $r = $respuesta;

        $modelo = config('aasr.ia.modelo_vision');
        $costo->registrar($ingesta, $modelo, $r->usage);
        $ingesta->update(['confianza' => $r['confianza'] ?? 0]);

        if (! ($r['es_comprobante_transferencia'] ?? true)) {
            $ingesta->update(['estado' => EstadoIngesta::Descartada, 'error_mensaje' => 'No parece un comprobante', 'extraccion_json' => $r->structured]);
            $telegram->enviarMensaje($chatId, '🤔 Esa imagen no me parece un comprobante de transferencia. La guardé igual por si querés revisarla desde el sistema.');

            return;
        }

        if (empty($r['importe']) || empty($r['fecha'])) {
            $faltante = empty($r['importe']) ? 'el importe' : 'la fecha';
            $ingesta->update(['estado' => EstadoIngesta::Error, 'extraccion_json' => $r->structured, 'error_mensaje' => "No se pudo leer {$faltante}"]);
            $telegram->enviarMensaje($chatId, "⚠️ No pude leer {$faltante} del comprobante. Cargalo a mano desde el sistema: ".route('pagos.create'));

            return;
        }

        $ingesta->update(['extraccion_json' => $r->structured]);

        $candidatos = $matcher->candidatos($r['titular_origen'] ?? null, $r['cuit_origen'] ?? null, $r['cbu_origen'] ?? null, $r['alias_origen'] ?? null);
        $ingesta->update(['candidatos_json' => $candidatos->map(fn ($c) => ['socio_id' => $c['socio']->id, 'score' => $c['score'], 'motivo' => $c['motivo']])->values()->all()]);

        $mejor = $candidatos->first();
        if (! $mejor || $mejor['score'] < 0.5) {
            $ingesta->update(['estado' => EstadoIngesta::EsperandoSocio]);
            $telegram->enviarMensaje($chatId, '🤷 No pude identificar al socio. Escribime el nombre, o mandá /socios para ver el padrón.');

            return;
        }

        $construirPropuesta($ingesta, $mejor['socio'], (string) $r['importe'], CarbonImmutable::parse($r['fecha']));

        if ($mejor['score'] < 0.85 || ($candidatos->count() > 1 && $candidatos->values()[1]['score'] >= 0.5)) {
            $ingesta->update(['estado' => EstadoIngesta::EsperandoSocio]);
            $telegram->enviarMensaje($chatId, '¿Cuál de estos socios es?', $teclados->candidatosSocio($ingesta, $candidatos->take(5)->values()));

            return;
        }

        $ingesta->update(['estado' => EstadoIngesta::EsperandoConfirmacion]);
        $telegram->enviarMensaje($chatId, $redactar($ingesta), $teclados->confirmacion($ingesta));
    }

    public function failed(\Throwable $e): void
    {
        $ingesta = IngestaTelegram::with('chat')->find($this->ingestaId);
        if (! $ingesta) {
            return;
        }
        $ingesta->update(['estado' => EstadoIngesta::Error, 'error_mensaje' => $e->getMessage()]);
        app(TelegramClient::class)->enviarMensaje($ingesta->chat->chat_id, '⚠️ No pude leer el comprobante, cargalo a mano desde el sistema: '.route('pagos.create'));
    }
}
