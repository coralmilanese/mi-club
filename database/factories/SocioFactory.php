<?php

namespace Database\Factories;

use App\Enums\CategoriaSocio;
use App\Enums\EstadoSocio;
use App\Enums\SedeSocio;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Socio> */
class SocioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nro_socio' => fake()->unique()->numberBetween(1, 9999),
            'apellido' => fake()->lastName(),
            'nombre' => fake()->firstName(),
            'genero' => fake()->randomElement(['M', 'F']),
            'fecha_nacimiento' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'nacionalidad' => 'Argentino',
            'dni' => (string) fake()->unique()->numberBetween(5_000_000, 60_000_000),
            'direccion' => fake()->streetAddress(),
            'ciudad' => 'Santa Rosa',
            'provincia' => 'La Pampa',
            'email' => fake()->unique()->safeEmail(),
            'telefono' => '2954 '.fake()->numerify('######'),
            'categoria' => CategoriaSocio::Activo,
            'sede' => SedeSocio::Aasr,
            'estado' => EstadoSocio::Activo,
            'fecha_asociacion' => fake()->dateTimeBetween('-5 years', '-1 month')->format('Y-m-d'),
        ];
    }

    public function baja(): static
    {
        return $this->state(fn () => ['estado' => EstadoSocio::Baja, 'fecha_baja' => now()->subMonth()->toDateString()]);
    }

    public function categoria(CategoriaSocio $categoria): static
    {
        return $this->state(fn () => ['categoria' => $categoria]);
    }
}
