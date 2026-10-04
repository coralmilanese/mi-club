<?php

namespace Database\Seeders;

use App\Enums\BaseTributo;
use App\Models\Cuenta;
use App\Models\MedioPago;
use App\Models\Tributo;
use Illuminate\Database\Seeder;

/** Cuentas, medios de pago y tributos tal como los usa el Excel (alícuotas históricas incluidas). */
class LibroDeCajaSeeder extends Seeder
{
    public function run(): void
    {
        $banco = Cuenta::firstOrCreate(['nombre' => 'Banco AASR'], ['tipo' => 'banco', 'titular' => 'Asociación Aeromodelista Santa Rosa', 'aplica_tributos' => true]);
        $caja = Cuenta::firstOrCreate(['nombre' => 'Efectivo'], ['tipo' => 'efectivo', 'aplica_tributos' => false]);
        Cuenta::firstOrCreate(['nombre' => 'Plazo Fijo'], ['tipo' => 'inversion', 'aplica_tributos' => false]);

        MedioPago::firstOrCreate(['codigo' => 'transferencia'], ['nombre' => 'Transferencia bancaria', 'cuenta_default_id' => $banco->id]);
        MedioPago::firstOrCreate(['codigo' => 'efectivo'], ['nombre' => 'Efectivo', 'cuenta_default_id' => $caja->id]);

        $tributos = [
            [
                'codigo' => 'sircreb', 'nombre' => 'SIRCREB', 'base' => BaseTributo::Credito, 'es_retencion' => true, 'orden' => 1,
                'categoria_libro' => 'SIRCREB', 'concepto_libro' => 'IMP. I.B SIRCREB',
                // La alícuota bajó de 4% a 3% a mitad de 2026; los registros viejos no se recalculan.
                'alicuotas' => [[4, '2025-01-01', '2026-06-30'], [3, '2026-07-01', null]],
            ],
            [
                'codigo' => 'imp_credito', 'nombre' => 'Impuesto al crédito', 'base' => BaseTributo::Credito, 'orden' => 2,
                'categoria_libro' => 'Débito/Crédito Impuesto', 'concepto_libro' => 'IMP.DEB/CRED P/CRED.',
                'alicuotas' => [[0.6, '2025-01-01', null]],
            ],
            [
                'codigo' => 'imp_debito', 'nombre' => 'Impuesto al débito', 'base' => BaseTributo::Debito, 'incluye_retenciones_en_base' => true, 'orden' => 3,
                'categoria_libro' => 'Débito/Crédito Impuesto', 'concepto_libro' => 'IMP.DEB/CRED P/DEB.',
                'alicuotas' => [[0.6, '2025-01-01', null]],
            ],
        ];

        foreach ($tributos as $datos) {
            $alicuotas = $datos['alicuotas'];
            unset($datos['alicuotas']);

            $tributo = Tributo::firstOrCreate(['codigo' => $datos['codigo']], $datos);

            if ($tributo->wasRecentlyCreated) {
                foreach ($alicuotas as [$pct, $desde, $hasta]) {
                    $tributo->alicuotas()->create(['alicuota' => $pct, 'vigencia_desde' => $desde, 'vigencia_hasta' => $hasta]);
                }
            }
        }
    }
}
