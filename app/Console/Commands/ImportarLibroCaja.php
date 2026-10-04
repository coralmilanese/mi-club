<?php

namespace App\Console\Commands;

use App\Services\Importacion\ImportadorLibroCaja;
use Illuminate\Console\Command;

class ImportarLibroCaja extends Command
{
    protected $signature = 'libro:importar {archivo : Ruta al .xlsx} {--dry-run : Simula sin escribir en la base}';

    protected $description = "Importa la hoja 'Libro de Caja' (movimientos, gastos, aperturas por cuenta e impuestos históricos)";

    public function handle(ImportadorLibroCaja $importador): int
    {
        $archivo = $this->argument('archivo');
        if (! is_file($archivo)) {
            $this->error("No existe el archivo: {$archivo}");

            return self::FAILURE;
        }

        try {
            $r = $importador->importar($archivo, (bool) $this->option('dry-run'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? '[SIMULACIÓN] ' : '').'Libro de caja importado');
        $this->line('Movimientos: '.collect($r['movimientos'])->map(fn ($n, $k) => "{$k}={$n}")->join(' · '));

        $this->newLine();
        $this->line('Aperturas por cuenta (derivadas del bloque de conciliación del Excel):');
        foreach ($r['aperturas'] as $cuenta => $monto) {
            $this->line(sprintf('  %-14s %s', $cuenta, $monto));
        }

        $this->newLine();
        $this->line('Saldos resultantes:');
        foreach ($r['saldos'] as $cuenta => $saldo) {
            $this->line(sprintf('  %-14s %s', $cuenta, $saldo));
        }
        $ok = $r['saldo_esperado'] !== null && abs((float) $r['saldo_total'] - (float) $r['saldo_esperado']) < 0.01;
        $this->line(sprintf('  %-14s %s (Excel: %s) %s', 'TOTAL', $r['saldo_total'], $r['saldo_esperado'] ?? 's/d', $ok ? '✔ cuadra' : '✘ NO CUADRA'));

        $l = $r['liquidaciones'];
        $this->newLine();
        $this->line("Impuestos: {$l['dias']} días liquidados · {$l['coinciden']} coinciden exactamente con lo que calcula el sistema · ".count($l['difieren']).' difieren');
        foreach (array_slice($l['difieren'], 0, 15) as $d) {
            $this->warn("  {$d}");
        }

        foreach (['inferencias' => 'Categorías inferidas', 'advertencias' => 'Advertencias'] as $k => $titulo) {
            if ($r[$k]) {
                $this->newLine();
                $this->warn("{$titulo}:");
                foreach ($r[$k] as $x) {
                    $this->line("  - {$x}");
                }
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
