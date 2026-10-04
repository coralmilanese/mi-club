<?php

namespace Database\Factories;

use App\Enums\EstadoPago;
use App\Enums\OrigenPago;
use App\Models\Cuenta;
use App\Models\Pago;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Pago> */
class PagoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'socio_id' => Socio::factory(),
            'fecha' => now()->toDateString(),
            'importe_bruto' => '35000.00',
            'cuenta_id' => Cuenta::firstOrCreate(['nombre' => 'Banco AASR'], ['tipo' => 'banco', 'aplica_tributos' => true])->id,
            'estado' => EstadoPago::Confirmado,
            'origen' => OrigenPago::Web,
        ];
    }
}
