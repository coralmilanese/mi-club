<?php

namespace App\Services\Telegram;

use App\Models\IngestaTelegram;
use App\Models\Socio;
use Illuminate\Support\Collection;

/** Arma los teclados inline de confirmación del bot. */
class TecladosTelegram
{
    /** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
    public function confirmacion(IngestaTelegram $ingesta): array
    {
        return ['inline_keyboard' => [
            [['text' => '✅ Confirmar', 'callback_data' => "confirmar:{$ingesta->id}"]],
            [['text' => '✏️ Cambiar socio', 'callback_data' => "cambiar_socio:{$ingesta->id}"], ['text' => '📅 Cambiar fecha', 'callback_data' => "cambiar_fecha:{$ingesta->id}"]],
            [['text' => '❌ Descartar', 'callback_data' => "descartar:{$ingesta->id}"]],
        ]];
    }

    /** @param Collection<int, array{socio: Socio, score: float, motivo: string}> $candidatos */
    public function candidatosSocio(IngestaTelegram $ingesta, Collection $candidatos): array
    {
        $filas = $candidatos->map(fn ($c) => [['text' => $c['socio']->nombre_completo, 'callback_data' => "elegir_socio:{$ingesta->id}:{$c['socio']->id}"]])->values()->all();
        $filas[] = [['text' => '❌ Descartar', 'callback_data' => "descartar:{$ingesta->id}"]];

        return ['inline_keyboard' => $filas];
    }
}
