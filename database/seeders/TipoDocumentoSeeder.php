<?php

namespace Database\Seeders;

use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;

class TipoDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['codigo' => 'foto', 'nombre' => 'Foto', 'obligatorio' => true],
            ['codigo' => 'planilla_datos', 'nombre' => 'Planilla de datos personales', 'obligatorio' => true],
        ] as $tipo) {
            TipoDocumento::firstOrCreate(['codigo' => $tipo['codigo']], $tipo);
        }
    }
}
