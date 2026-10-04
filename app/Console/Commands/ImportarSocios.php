<?php

namespace App\Console\Commands;

use App\Services\Importacion\ImportadorSocios;
use Illuminate\Console\Command;

class ImportarSocios extends Command
{
    protected $signature = 'socios:importar {archivo : Ruta al .xlsx} {--dry-run : Simula sin escribir en la base}';

    protected $description = "Importa la hoja 'Socios' del Libro de Caja AASR (activos + bajas)";

    public function handle(ImportadorSocios $importador): int
    {
        $archivo = $this->argument('archivo');
        if (! is_file($archivo)) {
            $this->error("No existe el archivo: {$archivo}");

            return self::FAILURE;
        }

        $r = $importador->importar($archivo, (bool) $this->option('dry-run'));

        $this->info(($this->option('dry-run') ? '[SIMULACIÓN] ' : '')."Socios creados: {$r['creados']} · omitidos: {$r['omitidos']}");
        foreach ($r['por_estado'] as $estado => $n) {
            $this->line("  - {$estado}: {$n}");
        }
        $this->line("Campos vacíos/placeholder limpiados: {$r['limpiados']}");

        if ($r['reingresos']) {
            $this->newLine();
            $this->warn('Reingresos detectados (mismo DNI en varias secciones):');
            foreach ($r['reingresos'] as $x) {
                $this->line("  - {$x}");
            }
        }
        if ($r['advertencias']) {
            $this->newLine();
            $this->warn('Filas problemáticas:');
            foreach ($r['advertencias'] as $x) {
                $this->line("  - {$x}");
            }
        }

        return self::SUCCESS;
    }
}
