<?php

namespace Database\Factories;

use App\Enums\EstadoCuota;
use App\Models\Cuota;
use App\Models\Plan;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cuota> */
class CuotaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'socio_id' => Socio::factory(),
            'periodo' => now()->startOfMonth()->toDateString(),
            'plan_id' => Plan::firstOrCreate(['tipo_plan' => 'aasr'], ['nombre' => 'Socio AASR'])->id,
            'importe_devengado' => '30000.00',
            'importe_imputado' => '0.00',
            'estado' => EstadoCuota::Pendiente,
        ];
    }
}
