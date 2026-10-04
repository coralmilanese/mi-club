<?php

namespace App\Actions\Gastos;

use App\Actions\Libro\AnularMovimiento;
use App\Models\Gasto;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnularGasto
{
    public function __construct(private AnularMovimiento $anularMovimiento) {}

    public function __invoke(Gasto $gasto, string $motivo, ?int $userId = null): Gasto
    {
        return DB::transaction(function () use ($gasto, $motivo, $userId) {
            $gasto = Gasto::whereKey($gasto->id)->lockForUpdate()->firstOrFail();
            if ($gasto->anulado_at) {
                throw ValidationException::withMessages(['gasto' => 'Este gasto ya está anulado.']);
            }

            if ($movimiento = $gasto->movimiento) {
                ($this->anularMovimiento)($movimiento, $motivo, $userId);
            }
            $gasto->update(['anulado_at' => now(), 'motivo_anulacion' => $motivo]);

            return $gasto;
        });
    }
}
