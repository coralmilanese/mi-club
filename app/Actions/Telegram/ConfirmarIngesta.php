<?php

namespace App\Actions\Telegram;

use App\Actions\Pagos\RegistrarPago;
use App\Enums\EstadoIngesta;
use App\Enums\OrigenPago;
use App\Enums\TipoIngesta;
use App\Models\Cuenta;
use App\Models\IngestaTelegram;
use App\Models\MedioPago;
use App\Models\Pago;
use App\Models\Socio;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Confirma una ingesta: crea el Pago con EXACTAMENTE la imputación que se le mostró al tesorero (nunca la
 * recalcula), imputa, y genera el movimiento del libro. Si alguna cuota dejó de estar pendiente mientras
 * tanto (otro canal la cobró), `RegistrarPago` lo rechaza solo: no hay condición de carrera posible.
 */
class ConfirmarIngesta
{
    public function __construct(private RegistrarPago $registrar) {}

    public function __invoke(IngestaTelegram $ingesta): Pago
    {
        if ($ingesta->estado !== EstadoIngesta::EsperandoConfirmacion) {
            throw ValidationException::withMessages(['ingesta' => 'Esto ya no está esperando confirmación.']);
        }

        $d = $ingesta->extraccion_json ?? [];
        if (! isset($d['socio_id'], $d['importe'], $d['fecha'])) {
            throw ValidationException::withMessages(['ingesta' => 'Faltan datos para confirmar este pago.']);
        }

        $esTexto = $ingesta->tipo === TipoIngesta::Texto;
        $cuenta = $esTexto
            ? Cuenta::where('tipo', 'efectivo')->where('activa', true)->firstOrFail()
            : Cuenta::where('aplica_tributos', true)->where('activa', true)->firstOrFail();
        $medio = MedioPago::where('codigo', $esTexto ? 'efectivo' : 'transferencia')->first();

        // Exactamente lo que se le mostró al tesorero: ni se recalcula ni se completa con nada nuevo.
        $imputaciones = collect($d['imputaciones'] ?? [])->mapWithKeys(fn ($i) => [$i['cuota_id'] => $i['importe']])->all();

        $pago = ($this->registrar)(
            pagador: Socio::findOrFail($d['socio_id']),
            fecha: CarbonImmutable::parse($d['fecha']),
            importe: (string) $d['importe'],
            cuenta: $cuenta,
            medio: $medio,
            origen: $esTexto ? OrigenPago::TelegramTexto : OrigenPago::TelegramComprobante,
            imputacionesManuales: $imputaciones,
            comprobante: $esTexto ? null : $ingesta->comprobante,
        );

        $ingesta->update(['estado' => EstadoIngesta::Confirmada, 'pago_id' => $pago->id]);

        return $pago;
    }
}
