<?php

namespace Database\Factories;

use App\Enums\TipoMovimiento;
use App\Models\Cuenta;
use App\Models\Movimiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Movimiento> */
class MovimientoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fecha' => now()->toDateString(),
            'concepto' => fake()->sentence(3),
            'tipo' => TipoMovimiento::Ingreso,
            'importe' => '30000.00',
            'cuenta_id' => Cuenta::firstOrCreate(['nombre' => 'Banco AASR'], ['tipo' => 'banco', 'aplica_tributos' => true])->id,
            'categoria_libro' => 'Cuota Socio',
        ];
    }

    public function egreso(): static
    {
        return $this->state(fn () => ['tipo' => TipoMovimiento::Egreso, 'categoria_libro' => 'Varios']);
    }
}
