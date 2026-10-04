<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Efectivo Fer" y "Efectivo Fran" pasan a ser una sola cuenta: "Efectivo". Se conserva todo el historial:
 * los movimientos, pagos, gastos y arqueos de Fran se reasignan, y las dos aperturas se suman en una.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fer = DB::table('cuentas')->where('nombre', 'Efectivo Fer')->first();
        $fran = DB::table('cuentas')->where('nombre', 'Efectivo Fran')->first();

        if (! $fer && ! $fran) {
            return; // base nueva: el seeder ya crea "Efectivo"
        }

        DB::transaction(function () use ($fer, $fran) {
            if (! $fer) {
                DB::table('cuentas')->where('id', $fran->id)->update(['nombre' => 'Efectivo']);

                return;
            }

            DB::table('cuentas')->where('id', $fer->id)->update(['nombre' => 'Efectivo']);
            // Con una sola caja, el medio "Efectivo" ya sabe a qué cuenta va.
            DB::table('medios_pago')->where('codigo', 'efectivo')->whereNull('cuenta_default_id')->update(['cuenta_default_id' => $fer->id]);
            if (! $fran) {
                return;
            }

            // Las dos aperturas se convierten en un único asiento de apertura.
            $aperturas = fn (int $cuentaId) => DB::table('movimientos')
                ->join('asientos', 'asientos.id', '=', 'movimientos.asiento_id')
                ->where('asientos.tipo', 'apertura')->where('movimientos.cuenta_id', $cuentaId)
                ->select('movimientos.id', 'movimientos.importe', 'movimientos.asiento_id')->first();

            $aperturaFer = $aperturas($fer->id);
            $aperturaFran = $aperturas($fran->id);
            if ($aperturaFran) {
                if ($aperturaFer) {
                    DB::table('movimientos')->where('id', $aperturaFer->id)->update(['importe' => DB::raw('importe + '.((float) $aperturaFran->importe))]);
                    DB::table('movimientos')->where('id', $aperturaFran->id)->delete();
                    DB::table('asientos')->where('id', $aperturaFran->asiento_id)->delete();
                }
            }

            foreach (['movimientos', 'pagos', 'gastos', 'arqueos_cuenta', 'liquidaciones_diarias'] as $tabla) {
                DB::table($tabla)->where('cuenta_id', $fran->id)->update(['cuenta_id' => $fer->id]);
            }
            DB::table('medios_pago')->where('cuenta_default_id', $fran->id)->update(['cuenta_default_id' => $fer->id]);

            DB::table('cuentas')->where('id', $fer->id)->update([
                'saldo_inicial' => DB::raw('saldo_inicial + '.((float) $fran->saldo_inicial)),
                'fecha_saldo_inicial' => $fer->fecha_saldo_inicial ?? $fran->fecha_saldo_inicial,
            ]);
            DB::table('cuentas')->where('id', $fran->id)->delete();
        });
    }

    public function down(): void
    {
        // Irreversible: no se puede saber qué movimientos eran de cada caja.
    }
};
