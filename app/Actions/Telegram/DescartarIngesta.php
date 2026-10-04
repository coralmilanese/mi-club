<?php

namespace App\Actions\Telegram;

use App\Enums\EstadoIngesta;
use App\Models\IngestaTelegram;

class DescartarIngesta
{
    public function __invoke(IngestaTelegram $ingesta, string $motivo = 'Descartado por el tesorero'): void
    {
        $ingesta->update(['estado' => EstadoIngesta::Descartada, 'error_mensaje' => $motivo]);
    }
}
