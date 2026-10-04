<?php

namespace App\Actions\Libro;

use App\Enums\TipoAsiento;
use App\Enums\TipoMovimiento;
use App\Models\Asiento;
use App\Models\Cuenta;
use App\Models\Movimiento;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único punto de entrada para escribir en el libro (pagos y gastos lo usan; a mano sólo se permiten ajustes explícitos).
 * El impuesto del día se recalcula solo, vía MovimientoObserver.
 */
class RegistrarMovimiento
{
    public function __invoke(
        Cuenta $cuenta,
        TipoMovimiento $tipo,
        CarbonInterface $fecha,
        string $concepto,
        string $importe,
        string $categoria,
        ?int $medioPagoId = null,
        TipoAsiento $tipoAsiento = TipoAsiento::Ajuste,
        ?Model $origen = null,
        ?int $userId = null,
        ?Asiento $asiento = null,
    ): Movimiento {
        $monto = BigDecimal::of($importe);
        if ($monto->isNegativeOrZero()) {
            throw ValidationException::withMessages(['importe' => 'El importe debe ser mayor a cero.']);
        }
        if (! $cuenta->activa) {
            throw ValidationException::withMessages(['cuenta_id' => "La cuenta {$cuenta->nombre} está inactiva."]);
        }

        return DB::transaction(function () use ($cuenta, $tipo, $fecha, $concepto, $monto, $categoria, $medioPagoId, $tipoAsiento, $origen, $userId, $asiento) {
            $asiento ??= Asiento::create(['fecha' => $fecha->toDateString(), 'descripcion' => $concepto, 'tipo' => $tipoAsiento]);

            return Movimiento::create([
                'fecha' => $fecha->toDateString(),
                'concepto' => $concepto,
                'tipo' => $tipo,
                'importe' => (string) $monto->toScale(2, RoundingMode::HALF_UP),
                'cuenta_id' => $cuenta->id,
                'medio_pago_id' => $medioPagoId,
                'categoria_libro' => $categoria,
                'asiento_id' => $asiento->id,
                'origenable_type' => $origen?->getMorphClass(),
                'origenable_id' => $origen?->getKey(),
                'creado_por_user_id' => $userId,
            ]);
        });
    }
}
