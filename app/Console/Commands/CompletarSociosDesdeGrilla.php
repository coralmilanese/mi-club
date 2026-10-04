<?php

namespace App\Console\Commands;

use App\Services\Importacion\CompletadorSociosDesdeGrilla;
use Illuminate\Console\Command;

class CompletarSociosDesdeGrilla extends Command
{
    protected $signature = 'socios:completar-desde-grilla {archivo : Ruta al .xlsx} {--anio=2026} {--dry-run}';

    protected $description = 'Da de alta como socios a quienes pagan en la grilla de cuotas y no están en el padrón, registra apodos y devenga sus cuotas';

    public function handle(CompletadorSociosDesdeGrilla $completador): int
    {
        $archivo = $this->argument('archivo');
        if (! is_file($archivo)) {
            $this->error("No existe el archivo: {$archivo}");

            return self::FAILURE;
        }

        $r = $completador->completar($archivo, (int) $this->option('anio'), (bool) $this->option('dry-run'));
        $sim = $this->option('dry-run') ? '[SIMULACIÓN] ' : '';

        $this->info("{$sim}Socios dados de alta: ".count($r['creados']).' · apodos registrados: '.$r['aliases'].' · cuotas devengadas: '.$r['cuotas_devengadas']);
        foreach ($r['creados'] as $c) {
            $this->line("  + {$c}");
        }

        return self::SUCCESS;
    }
}
