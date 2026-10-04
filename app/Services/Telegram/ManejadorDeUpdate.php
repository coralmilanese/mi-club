<?php

namespace App\Services\Telegram;

use App\Actions\Telegram\ConfirmarIngesta;
use App\Actions\Telegram\ConstruirPropuesta;
use App\Actions\Telegram\DescartarIngesta;
use App\Actions\Telegram\EnviarEstadoCuenta;
use App\Actions\Telegram\RedactarMensajeConfirmacion;
use App\Actions\Telegram\ResolverRespuestaTexto;
use App\Enums\EstadoIngesta;
use App\Enums\TipoIngesta;
use App\Jobs\ProcesarComprobanteTelegramJob;
use App\Jobs\ProcesarPagoEfectivoTelegramJob;
use App\Models\IngestaTelegram;
use App\Models\Socio;
use App\Models\TelegramChat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Único punto de entrada del webhook: decide qué hacer con cada update de Telegram.
 * Nunca llama a la IA acá (eso vive en los jobs): esto sólo valida, enruta y, para lo rápido
 * (comandos, botones, respuestas de texto), actúa directo.
 */
class ManejadorDeUpdate
{
    public function __construct(
        private TelegramClient $telegram,
        private AutorizadorTelegram $autorizador,
        private TecladosTelegram $teclados,
        private ComandosTelegram $comandos,
        private RedactarMensajeConfirmacion $redactar,
    ) {}

    /** @param array<string, mixed> $update */
    public function procesar(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->callback($update['callback_query']);

            return;
        }
        if (isset($update['message'])) {
            $this->mensaje($update['message']);
        }
    }

    /** @param array<string, mixed> $m */
    private function mensaje(array $m): void
    {
        $chat = $this->autorizador->resolver((string) $m['chat']['id'], $m['from'] ?? []);
        if (! $chat->autorizado) {
            Log::info('Telegram: mensaje de chat no autorizado', ['chat_id' => $chat->chat_id, 'username' => $chat->username]);

            return; // se ignora silenciosamente (B.10)
        }

        $messageId = (string) $m['message_id'];
        if (IngestaTelegram::where('telegram_chat_id', $chat->id)->where('message_id', $messageId)->exists()) {
            return; // Telegram reintega el webhook si no respondemos rápido: idempotencia
        }

        if (isset($m['photo'])) {
            $fileId = (string) end($m['photo'])['file_id']; // la última es la de mayor resolución
            $this->crearIngestaArchivo($chat, $messageId, TipoIngesta::Foto, $fileId);

            return;
        }
        if (isset($m['document']) && str_contains((string) ($m['document']['mime_type'] ?? ''), 'pdf')) {
            $this->crearIngestaArchivo($chat, $messageId, TipoIngesta::Documento, (string) $m['document']['file_id']);

            return;
        }

        $texto = trim((string) ($m['text'] ?? $m['caption'] ?? ''));
        if ($texto === '') {
            return; // sticker, audio, etc.: no es algo que el bot sepa procesar
        }

        if (str_starts_with($texto, '/')) {
            $this->comando($chat, $texto);

            return;
        }

        $pendiente = IngestaTelegram::where('telegram_chat_id', $chat->id)->whereIn('estado', [EstadoIngesta::EsperandoSocio->value, EstadoIngesta::EsperandoFecha->value])->latest()->first();
        if ($pendiente) {
            $this->responderTexto($pendiente, $texto);

            return;
        }

        $this->crearIngestaTexto($chat, $messageId, $texto);
    }

    private function crearIngestaArchivo(TelegramChat $chat, string $messageId, TipoIngesta $tipo, string $fileId): void
    {
        $ingesta = IngestaTelegram::create([
            'telegram_chat_id' => $chat->id, 'message_id' => $messageId, 'tipo' => $tipo,
            'estado' => EstadoIngesta::Recibida, 'extraccion_json' => ['_telegram_file_id' => $fileId],
        ]);
        ProcesarComprobanteTelegramJob::dispatch($ingesta->id);
    }

    private function crearIngestaTexto(TelegramChat $chat, string $messageId, string $texto): void
    {
        $ingesta = IngestaTelegram::create([
            'telegram_chat_id' => $chat->id, 'message_id' => $messageId, 'tipo' => TipoIngesta::Texto,
            'texto_original' => $texto, 'estado' => EstadoIngesta::Recibida,
        ]);
        ProcesarPagoEfectivoTelegramJob::dispatch($ingesta->id);
    }

    private function comando(TelegramChat $chat, string $texto): void
    {
        [$comando] = explode(' ', $texto, 2);
        $comando = strtolower(explode('@', $comando)[0]); // /saldo@tesoreria_aasr_bot → /saldo
        $resto = trim(substr($texto, strlen(explode(' ', $texto, 2)[0])));

        $respuesta = match ($comando) {
            '/deudores' => $this->comandos->deudores(),
            '/socio' => $resto !== '' ? $this->comandos->socio($resto) : 'Usá /socio seguido del nombre.',
            '/saldo' => $this->comandos->saldo(),
            '/pendientes' => $this->comandos->pendientes(),
            '/start', '/ayuda', '/help' => "Mandame una foto o un PDF de un comprobante y te propongo a qué socio y a qué meses imputarlo.\nComandos: /deudores · /socio (nombre) · /saldo · /pendientes",
            default => null,
        };

        if ($respuesta) {
            $this->telegram->enviarMensaje($chat->chat_id, $respuesta);
        }
    }

    private function responderTexto(IngestaTelegram $ingesta, string $texto): void
    {
        $r = app(ResolverRespuestaTexto::class)($ingesta, $texto);

        match ($r['tipo']) {
            'propuesta' => $this->telegram->enviarMensaje($ingesta->chat->chat_id, $this->redactar->__invoke($ingesta->fresh()), $this->teclados->confirmacion($ingesta)),
            'candidatos' => $this->telegram->enviarMensaje($ingesta->chat->chat_id, '¿Cuál de estos socios es?', $this->teclados->candidatosSocio($ingesta, $r['candidatos'])),
            'error' => $this->telegram->enviarMensaje($ingesta->chat->chat_id, $r['mensaje']),
        };
    }

    /** @param array<string, mixed> $cb */
    private function callback(array $cb): void
    {
        $chat = TelegramChat::where('chat_id', (string) $cb['message']['chat']['id'])->first();
        if (! $chat || ! $chat->autorizado) {
            $this->telegram->responderCallback($cb['id'], 'No autorizado.');

            return;
        }

        $partes = explode(':', (string) $cb['data']);
        $accion = array_shift($partes);
        $args = $partes;
        $ingesta = IngestaTelegram::find($args[0] ?? null);
        if (! $ingesta || $ingesta->telegram_chat_id !== $chat->id) {
            $this->telegram->responderCallback($cb['id'], 'Ya no existe.');

            return;
        }

        try {
            match ($accion) {
                'confirmar' => $this->confirmar($ingesta, $cb),
                'descartar' => $this->descartar($ingesta, $cb),
                'cambiar_socio' => $this->pedirSocio($ingesta, $cb),
                'cambiar_fecha' => $this->pedirFecha($ingesta, $cb),
                'elegir_socio' => $this->elegirSocio($ingesta, $cb, (int) ($args[1] ?? 0)),
                default => $this->telegram->responderCallback($cb['id']),
            };
        } catch (ValidationException $e) {
            $this->telegram->responderCallback($cb['id'], collect($e->errors())->flatten()->join(' '));
        }
    }

    /** @param array<string, mixed> $cb */
    private function confirmar(IngestaTelegram $ingesta, array $cb): void
    {
        $pago = app(ConfirmarIngesta::class)($ingesta);
        $this->telegram->quitarTeclado($cb['message']['chat']['id'], (string) $cb['message']['message_id']);
        $this->telegram->enviarMensaje($ingesta->chat->chat_id, '✅ Confirmado: '.route('socios.show', $pago->socio_id));
        app(EnviarEstadoCuenta::class)($ingesta->chat->chat_id, $pago);
        $this->telegram->responderCallback($cb['id'], 'Confirmado');
    }

    /** @param array<string, mixed> $cb */
    private function descartar(IngestaTelegram $ingesta, array $cb): void
    {
        app(DescartarIngesta::class)($ingesta);
        $this->telegram->quitarTeclado($cb['message']['chat']['id'], (string) $cb['message']['message_id']);
        $this->telegram->enviarMensaje($ingesta->chat->chat_id, '❌ Descartado.');
        $this->telegram->responderCallback($cb['id'], 'Descartado');
    }

    /** @param array<string, mixed> $cb */
    private function pedirSocio(IngestaTelegram $ingesta, array $cb): void
    {
        $ingesta->update(['estado' => EstadoIngesta::EsperandoSocio]);
        $this->telegram->enviarMensaje($ingesta->chat->chat_id, 'Escribime el nombre del socio.');
        $this->telegram->responderCallback($cb['id']);
    }

    /** @param array<string, mixed> $cb */
    private function pedirFecha(IngestaTelegram $ingesta, array $cb): void
    {
        $ingesta->update(['estado' => EstadoIngesta::EsperandoFecha]);
        $this->telegram->enviarMensaje($ingesta->chat->chat_id, 'Escribime la fecha (DD/MM/AAAA).');
        $this->telegram->responderCallback($cb['id']);
    }

    /** @param array<string, mixed> $cb */
    private function elegirSocio(IngestaTelegram $ingesta, array $cb, int $socioId): void
    {
        $socio = Socio::findOrFail($socioId);
        $d = $ingesta->extraccion_json ?? [];
        app(ConstruirPropuesta::class)($ingesta, $socio, (string) ($d['importe'] ?? '0'), CarbonImmutable::parse($d['fecha'] ?? now()->toDateString()));
        $ingesta->update(['estado' => EstadoIngesta::EsperandoConfirmacion]);

        $this->telegram->quitarTeclado($cb['message']['chat']['id'], (string) $cb['message']['message_id']);
        $this->telegram->enviarMensaje($ingesta->chat->chat_id, $this->redactar->__invoke($ingesta->fresh()), $this->teclados->confirmacion($ingesta));
        $this->telegram->responderCallback($cb['id']);
    }
}
