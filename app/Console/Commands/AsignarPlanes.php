<?php

namespace App\Console\Commands;

use App\Services\Importacion\AsignadorPlanesDesdeCsv;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AsignarPlanes extends Command
{
    protected $signature = 'planes:asignar {csv : Planilla de revisión corregida} {--desde=2026-01-01 : Fecha de inicio por defecto} {--dry-run}';

    protected $description = 'Asigna los planes (y arma los grupos familiares) según la planilla de revisión de planes:sugerir';

    public function handle(AsignadorPlanesDesdeCsv $asignador): int
    {
        $csv = $this->argument('csv');
        if (! is_file($csv)) {
            $this->error("No existe el archivo: {$csv}");

            return self::FAILURE;
        }

        try {
            $r = $asignador->aplicar($csv, CarbonImmutable::parse($this->option('desde')), (bool) $this->option('dry-run'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? '[SIMULACIÓN] ' : '')."Asignados: {$r['asignados']} · sin cambios: {$r['sin_cambios']} · salteados (plan_final vacío): {$r['salteados']} · grupos creados: {$r['grupos']}");

        if ($r['errores']) {
            $this->error('No se aplicó nada por estos errores:');
            foreach ($r['errores'] as $e) {
                $this->line("  - {$e}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
