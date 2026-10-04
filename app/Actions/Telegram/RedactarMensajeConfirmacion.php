<?php

namespace App\Actions\Telegram;

use App\Models\Cuota;
use App\Models\IngestaTelegram;
use App\Models\Socio;
use Carbon\CarbonImmutable;

class RedactarMensajeConfirmacion
{
    public function __invoke(IngestaTelegram $ingesta): string
    {
        $d = $ingesta->extraccion_json ?? [];
        $socio = isset($d['socio_id']) ? Socio::find($d['socio_id']) : null;
        $confianza = $ingesta->confianza !== null ? round(((float) $ingesta->confianza) * 100).'%' : 's/d';

        $lineas = ['✅ <b>Comprobante reconocido</b>'];
        $lineas[] = 'Socio: <b>'.e($socio !== null ? $socio->nombre_completo : '¿?').'</b> ('.$confianza.' de confianza)';
        $lineas[] = 'Importe: $ '.number_format((float) ($d['importe'] ?? 0), 2, ',', '.');
        $lineas[] = 'Fecha: '.(isset($d['fecha']) ? CarbonImmutable::parse($d['fecha'])->format('d/m/Y') : 's/d').' (la del comprobante)';
        if (! empty($d['banco_origen']) || ! empty($d['nro_operacion'])) {
            $lineas[] = 'Origen: '.e($d['banco_origen'] ?? '').(! empty($d['nro_operacion']) ? ' · Op. '.e($d['nro_operacion']) : '');
        }

        $lineas[] = '';
        $lineas[] = $this->lineaImputaciones($d, $socio);

        if ((float) ($d['sobrante'] ?? 0) > 0) {
            $lineas[] = 'Sobran $ '.number_format((float) $d['sobrante'], 2, ',', '.').' como saldo a favor.';
        }

        return implode("\n", $lineas);
    }

    /** @param array<string, mixed> $d */
    private function lineaImputaciones(array $d, ?Socio $pagador): string
    {
        $items = collect($d['imputaciones'] ?? []);
        if ($items->isEmpty()) {
            return 'Imputa a: sin cuotas pendientes (quedaría como saldo a favor)';
        }

        $cuotas = Cuota::with('socio')->whereIn('id', $items->pluck('cuota_id'))->get()->keyBy('id');

        // Una por línea, con a quién corresponde cuando el titular paga también la de un adherente de su grupo.
        $detalle = $items->map(function ($i) use ($cuotas, $pagador) {
            $cuota = $cuotas->get($i['cuota_id']);
            if (! $cuota) {
                return null;
            }
            $periodo = $cuota->periodo->locale('es')->translatedFormat('F Y');
            $quien = $pagador && $cuota->socio_id !== $pagador->id ? ' ('.e($cuota->socio->nombre_completo).')' : '';
            $importe = number_format((float) $i['importe'], 2, ',', '.');
            $parcial = ($i['completa'] ?? true) ? '' : ' — parcial';
            // La cuota ya traía un pago parcial de antes: esto no es un pago nuevo a medias, completa el resto.
            $resto = (float) $cuota->importe_imputado > 0 ? ' (resto; ya tenía $ '.number_format((float) $cuota->importe_imputado, 2, ',', '.').' pagados)' : '';

            return "• {$periodo}{$quien}: \$ {$importe}{$parcial}{$resto}";
        })->filter();

        return "Imputa a:\n".$detalle->join("\n");
    }
}
