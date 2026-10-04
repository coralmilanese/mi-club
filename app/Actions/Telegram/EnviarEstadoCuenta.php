<?php

namespace App\Actions\Telegram;

use App\Models\Pago;
use App\Services\Reportes\EstadoCuentaSocio;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Log;

/**
 * Tras confirmar un pago que llegó por Telegram, le manda al chat el estado de cuenta anual del socio en PDF,
 * con todos los meses marcados según su estado. Nunca rompe la confirmación: el pago ya quedó guardado,
 * así que un problema generando o mandando el PDF sólo se loguea.
 */
class EnviarEstadoCuenta
{
    public function __construct(private EstadoCuentaSocio $estadoCuenta, private TelegramClient $telegram) {}

    public function __invoke(string $chatId, Pago $pago): void
    {
        try {
            $anio = $pago->fecha->year;
            $pdf = $this->estadoCuenta->pdf($pago->socio, $anio);
            $this->telegram->enviarDocumento($chatId, $this->estadoCuenta->nombreArchivo($pago->socio, $anio), $pdf->output(), 'Estado de cuenta actualizado');
        } catch (\Throwable $e) {
            Log::warning('No se pudo generar/enviar el estado de cuenta por Telegram', ['pago_id' => $pago->id, 'error' => $e->getMessage()]);
        }
    }
}
