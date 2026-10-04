<?php

namespace App\Services\Importacion;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee la grilla socio × mes de la hoja ` Cuotas` (el nombre real lleva un espacio adelante).
 * Cada celda queda tipificada: pago (importe), deuda, baja, familiar u otro.
 */
class LectorGrillaCuotas
{
    /**
     * @return list<array{fila: int, nro: string|null, nombre: string, meses: array<int, array{tipo: string, importe?: float, texto?: string}|null>}>
     */
    public function leer(string $archivo): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([' Cuotas', 'Cuotas']);

        $wb = $reader->load($archivo);
        $ws = $wb->getSheetByName(' Cuotas') ?? $wb->getSheetByName('Cuotas')
            ?? throw new \RuntimeException("El archivo no tiene una hoja 'Cuotas'.");

        $filas = [];
        foreach ($ws->toArray(null, true, false, false) as $i => $c) {
            if ($i === 0) {
                continue;
            }
            $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($c[1] ?? '')));
            if ($nombre === '') {
                break; // el bloque de socios termina en la primera fila sin nombre ("Totales" queda afuera)
            }

            $meses = [];
            for ($m = 1; $m <= 12; $m++) {
                $meses[$m] = $this->celda($c[$m + 1] ?? null);
            }

            $filas[] = ['fila' => $i + 1, 'nro' => isset($c[0]) ? (string) $c[0] : null, 'nombre' => $nombre, 'meses' => $meses];
        }

        return $filas;
    }

    /** @return array{tipo: string, importe?: float, texto?: string}|null */
    private function celda(mixed $v): ?array
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return ['tipo' => 'pago', 'importe' => (float) $v];
        }

        $t = mb_strtolower(trim((string) $v));

        return match (true) {
            $t === 'deuda' => ['tipo' => 'deuda'],
            $t === 'baja' => ['tipo' => 'baja'],
            $t === 'familiar' => ['tipo' => 'familiar'],
            default => ['tipo' => 'otro', 'texto' => trim((string) $v)],
        };
    }
}
