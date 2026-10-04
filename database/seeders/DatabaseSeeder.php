<?php

namespace Database\Seeders;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => config('aasr.tesorero.email')],
            [
                'name' => config('aasr.tesorero.nombre'),
                'password' => config('aasr.tesorero.password'),
                'rol' => Rol::Tesorero,
                'email_verified_at' => now(),
            ],
        );

        $this->call([TipoDocumentoSeeder::class, PlanSeeder::class, LibroDeCajaSeeder::class, CategoriaGastoSeeder::class]);
    }
}
