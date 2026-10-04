<?php

namespace Database\Seeders;

use App\Models\CategoriaGasto;
use Illuminate\Database\Seeder;

/** Las categorías de egreso que aparecen en el Excel (con las dos grafías normalizadas a una). */
class CategoriaGastoSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['codigo' => 'cpsr', 'nombre' => 'CPSR / Planeadores', 'tipo' => 'recurrente'],
            ['codigo' => 'faa', 'nombre' => 'FAA / Federación', 'tipo' => 'recurrente'],
            ['codigo' => 'seguro', 'nombre' => 'Seguro', 'tipo' => 'recurrente'],
            ['codigo' => 'honorarios_contadora', 'nombre' => 'Honorarios contadora', 'tipo' => 'recurrente'],
            ['codigo' => 'luz', 'nombre' => 'Luz', 'tipo' => 'recurrente'],
            ['codigo' => 'servicios', 'nombre' => 'Servicios', 'tipo' => 'eventual'],
            ['codigo' => 'mantenimiento', 'nombre' => 'Mantenimiento', 'tipo' => 'eventual'],
            ['codigo' => 'nafta_tractor', 'nombre' => 'Nafta tractor', 'tipo' => 'eventual'],
            ['codigo' => 'varios', 'nombre' => 'Varios', 'tipo' => 'eventual'],
        ] as $c) {
            CategoriaGasto::firstOrCreate(['codigo' => $c['codigo']], $c);
        }
    }
}
