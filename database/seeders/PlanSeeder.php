<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Planes y tarifas tomados del bloque de tarifas de la hoja Cuotas del Excel
 * (vigencias 26-09-2025 y 26-06-2026). Todo se puede cambiar después desde el ABM.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $planes = [
            ['tipo_plan' => 'aasr', 'nombre' => 'Socio AASR', 'orden' => 1, 'tarifas' => [30000, 35000]],
            ['tipo_plan' => 'planeadores', 'nombre' => 'Socio de Plan (Planeadores)', 'descripcion' => 'Socio del Club de Planeadores', 'orden' => 2, 'tarifas' => [16000, 17000]],
            ['tipo_plan' => 'adicional_familiar', 'nombre' => 'Adicional familiar', 'descripcion' => 'Por cada miembro extra del grupo familiar; lo paga el titular', 'orden' => 3, 'es_adicional_familiar' => true, 'tarifas' => [21500, 22500]],
            ['tipo_plan' => 'vitalicio', 'nombre' => 'Vitalicio', 'orden' => 4, 'tarifas' => [21500, 22500]],
            ['tipo_plan' => 'honorario', 'nombre' => 'Honorario', 'descripcion' => 'No paga cuota', 'orden' => 5, 'genera_cuota' => false, 'tarifas' => []],
        ];

        foreach ($planes as $datos) {
            $tarifas = $datos['tarifas'];
            unset($datos['tarifas']);

            $plan = Plan::firstOrCreate(['tipo_plan' => $datos['tipo_plan']], $datos);

            if ($plan->wasRecentlyCreated && $tarifas) {
                $plan->tarifas()->create(['importe' => $tarifas[0], 'vigencia_desde' => '2025-09-26', 'vigencia_hasta' => '2026-06-25']);
                $plan->tarifas()->create(['importe' => $tarifas[1], 'vigencia_desde' => '2026-06-26']);
            }
        }
    }
}
