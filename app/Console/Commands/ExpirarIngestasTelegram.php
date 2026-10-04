<?php

namespace App\Console\Commands;

use App\Enums\EstadoIngesta;
use App\Models\IngestaTelegram;
use Illuminate\Console\Command;

/** Las ingestas sin confirmar expiran a los N días (config aasr.telegram.dias_expiracion_pendientes) y quedan en la bandeja web. */
class ExpirarIngestasTelegram extends Command
{
    protected $signature = 'telegram:expirar-pendientes';

    protected $description = 'Marca como expiradas las ingestas de Telegram pendientes hace demasiado tiempo';

    public function handle(): int
    {
        $dias = (int) config('aasr.telegram.dias_expiracion_pendientes', 7);
        $n = IngestaTelegram::whereIn('estado', [EstadoIngesta::EsperandoConfirmacion->value, EstadoIngesta::EsperandoSocio->value, EstadoIngesta::EsperandoFecha->value])
            ->where('updated_at', '<', now()->subDays($dias))
            ->update(['estado' => EstadoIngesta::Expirada->value]);

        $this->info("{$n} ingesta(s) expirada(s).");

        return self::SUCCESS;
    }
}
