<?php

namespace App\Console\Commands;

use App\Services\Importacion\ImportadorCuotas;
use Illuminate\Console\Command;

class ImportarCuotas extends Command
{
    protected $signature = 'cuotas:importar {archivo : Ruta al .xlsx} {--anio=2026} {--dry-run : Simula sin escribir en la base}';

    protected $description = 'Aplica la grilla de la hoja Cuotas a las cuotas devengadas y vincula los ingresos del libro con sus socios (requiere libro:importar y planes asignados)';

    public function handle(ImportadorCuotas $importador): int
    {
        $archivo = $this->argument('archivo');
        if (! is_file($archivo)) {
            $this->error("No existe el archivo: {$archivo}");

            return self::FAILURE;
        }

        $r = $importador->importar($archivo, (int) $this->option('anio'), (bool) $this->option('dry-run'));
        $sim = $this->option('dry-run') ? '[SIMULACIÓN] ' : '';

        $this->info("{$sim}Grilla: {$r['filas_grilla']} filas · {$r['cuotas_cobradas']} cuotas marcadas como cobradas por \${$r['monto_aplicado']} (total de la planilla: \${$r['total_grilla']})");
        if ($r['filas_sin_socio']) {
            $this->warn("Filas de la grilla sin socio confiable en el padrón (\${$r['total_sin_socio']} sin aplicar): ".implode('; ', $r['filas_sin_socio']));
        }

        $this->info("Pagos vinculados con su socio y sus meses: {$r['pagos_vinculados']} · de períodos anteriores a ".$this->option('anio')." (ignorados a propósito): {$r['pagos_previos']} · sin vincular: ".count($r['pagos_sin_vincular']).' · cuotas cobradas sin pago asociado: '.$r['cuotas_cobradas_sin_pago']);

        $dir = storage_path('app/revision');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $f = fopen("{$dir}/pagos-sin-vincular.csv", 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['movimiento_id', 'fecha', 'concepto', 'importe', 'motivo'], ',', '"', '');
        foreach ($r['pagos_sin_vincular'] as $p) {
            fputcsv($f, [$p['movimiento_id'], $p['fecha'], $p['concepto'], $p['importe'], $p['motivo']], ',', '"', '');
        }
        fclose($f);
        $this->line("Detalle de los pagos sin vincular: {$dir}/pagos-sin-vincular.csv");

        foreach ($r['advertencias'] as $a) {
            $this->warn("  - {$a}");
        }

        return self::SUCCESS;
    }
}
