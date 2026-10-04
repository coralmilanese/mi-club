<?php

namespace App\Console\Commands;

use App\Services\Importacion\SugeridorPlanes;
use Illuminate\Console\Command;

class SugerirPlanes extends Command
{
    protected $signature = 'planes:sugerir {archivo : Ruta al .xlsx} {--anio=2026} {--salida= : Carpeta de salida (default storage/app/revision)}';

    protected $description = 'Genera la planilla de revisión (CSV) con el plan deducido de cada socio a partir de lo que paga en la grilla de cuotas';

    private const COLUMNAS = ['socio_id', 'socio', 'categoria', 'sede_excel', 'fila_cuotas', 'nombre_en_cuotas', 'similitud', 'pagos', 'ultimo_importe', 'plan_sugerido', 'confianza', 'alerta', 'plan_final', 'desde', 'hasta', 'titular_grupo_id', 'titular_grupo'];

    public function handle(SugeridorPlanes $sugeridor): int
    {
        $archivo = $this->argument('archivo');
        if (! is_file($archivo)) {
            $this->error("No existe el archivo: {$archivo}");

            return self::FAILURE;
        }

        $r = $sugeridor->sugerir($archivo, (int) $this->option('anio'));

        $dir = $this->option('salida') ?: storage_path('app/revision');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->escribir("{$dir}/revision-planes.csv", self::COLUMNAS, $r['socios']);
        $this->escribir("{$dir}/grilla-sin-socio-vigente.csv", ['fila_cuotas', 'nombre_en_cuotas', 'candidatos', 'resumen'], $r['sin_socio']);

        $total = count($r['socios']);
        $listos = count(array_filter($r['socios'], fn ($s) => $s['plan_final'] !== ''));
        $conAlerta = count(array_filter($r['socios'], fn ($s) => $s['alerta'] !== ''));

        $this->info("Socios vigentes: {$total} · con plan propuesto listo: {$listos} · a decidir a mano: ".($total - $listos).". Con alertas: {$conAlerta}.");
        $this->line("Planilla:                    {$dir}/revision-planes.csv");
        $this->line('Filas de la grilla sin socio vigente ('.count($r['sin_socio'])."): {$dir}/grilla-sin-socio-vigente.csv");

        return self::SUCCESS;
    }

    /** @param list<string> $columnas @param list<array<string, mixed>> $filas */
    private function escribir(string $ruta, array $columnas, array $filas): void
    {
        $f = fopen($ruta, 'w');
        fwrite($f, "\xEF\xBB\xBF"); // BOM: Excel/LibreOffice abren bien los acentos
        fputcsv($f, $columnas, ',', '"', '');
        foreach ($filas as $fila) {
            fputcsv($f, array_map(fn ($c) => $fila[$c] ?? '', $columnas), ',', '"', '');
        }
        fclose($f);
    }
}
