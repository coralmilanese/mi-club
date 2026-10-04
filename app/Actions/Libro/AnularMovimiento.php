<?php

namespace App\Actions\Libro;

use App\Enums\TipoMovimiento;
use App\Models\Movimiento;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El libro es un registro contable: no se borra. Anular genera un contramovimiento que referencia al original;
 * ambos quedan marcados como anulados (fuera de las bases de impuestos) y el saldo neto no cambia.
 */
class AnularMovimiento
{
    public function __invoke(Movimiento $original, string $motivo, ?int $userId = null): Movimiento
    {
        return DB::transaction(function () use ($original, $motivo, $userId) {
            $original = Movimiento::whereKey($original->id)->lockForUpdate()->firstOrFail();

            if ($original->es_tributo) {
                throw ValidationException::withMessages(['movimiento' => 'Los impuestos se generan solos: ajustá el día en la liquidación diaria.']);
            }
            if ($original->estaAnulado()) {
                throw ValidationException::withMessages(['movimiento' => 'Este movimiento ya está anulado.']);
            }

            $ahora = now();
            $contra = Movimiento::create([
                'fecha' => $original->fecha->toDateString(),
                'concepto' => "ANULACIÓN: {$original->concepto} ({$motivo})",
                'tipo' => $original->tipo === TipoMovimiento::Ingreso ? TipoMovimiento::Egreso : TipoMovimiento::Ingreso,
                'importe' => $original->importe,
                'cuenta_id' => $original->cuenta_id,
                'medio_pago_id' => $original->medio_pago_id,
                'categoria_libro' => $original->categoria_libro,
                'asiento_id' => $original->asiento_id,
                'anulado_at' => $ahora,
                'creado_por_user_id' => $userId,
            ]);

            $original->update(['anulado_at' => $ahora, 'anulado_por_movimiento_id' => $contra->id]);

            return $contra;
        });
    }
}
