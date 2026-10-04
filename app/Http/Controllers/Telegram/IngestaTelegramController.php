<?php

namespace App\Http\Controllers\Telegram;

use App\Actions\Telegram\ConfirmarIngesta;
use App\Actions\Telegram\DescartarIngesta;
use App\Actions\Telegram\EnviarEstadoCuenta;
use App\Http\Controllers\Controller;
use App\Models\IngestaTelegram;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Bandeja web de ingestas que quedaron sin resolver por Telegram: el tesorero las termina acá. */
class IngestaTelegramController extends Controller
{
    public function index(): Response
    {
        $ingestas = IngestaTelegram::with(['chat', 'socioSugerido'])
            ->whereIn('estado', ['esperando_confirmacion', 'esperando_socio', 'esperando_fecha', 'error', 'expirada'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (IngestaTelegram $i) => [
                'id' => $i->id,
                'tipo' => $i->tipo->label(),
                'estado' => $i->estado->value,
                'estado_label' => $i->estado->label(),
                'chat' => $i->chat->nombre ?? $i->chat->username ?? $i->chat->chat_id,
                'texto_original' => $i->texto_original,
                'socio_sugerido' => $i->socioSugerido?->nombre_completo,
                'importe' => $i->extraccion_json['importe'] ?? null,
                'confianza' => $i->confianza,
                'error_mensaje' => $i->error_mensaje,
                'creado' => $i->created_at->diffForHumans(),
                'comprobante_url' => $i->comprobante_id ? route('comprobantes.show', $i->comprobante_id) : null,
            ]);

        return Inertia::render('telegram/pendientes', ['ingestas' => $ingestas]);
    }

    public function confirmar(IngestaTelegram $ingesta, ConfirmarIngesta $confirmar, EnviarEstadoCuenta $enviarEstadoCuenta): RedirectResponse
    {
        try {
            $pago = $confirmar($ingesta);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $enviarEstadoCuenta($ingesta->chat->chat_id, $pago);

        return to_route('telegram.pendientes.index')->with('success', "Pago registrado para {$pago->socio->nombre_completo}.");
    }

    public function descartar(Request $request, IngestaTelegram $ingesta, DescartarIngesta $descartar): RedirectResponse
    {
        $descartar($ingesta, $request->string('motivo')->value() ?: 'Descartado desde el sistema');

        return back()->with('success', 'Ingesta descartada.');
    }
}
