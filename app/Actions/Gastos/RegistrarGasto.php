<?php

namespace App\Actions\Gastos;

use App\Actions\Libro\RegistrarMovimiento;
use App\Enums\TipoAsiento;
use App\Enums\TipoMovimiento;
use App\Models\CategoriaGasto;
use App\Models\Comprobante;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\MedioPago;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Gasto → movimiento de egreso; el gasto entra en la base del impuesto al débito del día. */
class RegistrarGasto
{
    public function __construct(private RegistrarMovimiento $registrarMovimiento) {}

    public function __invoke(
        CategoriaGasto $categoria,
        CarbonInterface $fecha,
        string $importe,
        string $descripcion,
        Cuenta $cuenta,
        ?MedioPago $medio = null,
        ?string $proveedor = null,
        ?CarbonInterface $periodoDesde = null,
        ?CarbonInterface $periodoHasta = null,
        ?Comprobante $comprobante = null,
        ?int $userId = null,
    ): Gasto {
        if (! $categoria->activa) {
            throw ValidationException::withMessages(['categoria_gasto_id' => "La categoría {$categoria->nombre} está inactiva."]);
        }

        return DB::transaction(function () use ($categoria, $fecha, $importe, $descripcion, $cuenta, $medio, $proveedor, $periodoDesde, $periodoHasta, $comprobante, $userId) {
            $gasto = Gasto::create([
                'categoria_gasto_id' => $categoria->id,
                'fecha' => $fecha->toDateString(),
                'importe' => $importe,
                'descripcion' => $descripcion,
                'proveedor' => $proveedor,
                'medio_pago_id' => $medio?->id,
                'cuenta_id' => $cuenta->id,
                'periodo_cubierto_desde' => $periodoDesde?->toDateString(),
                'periodo_cubierto_hasta' => $periodoHasta?->toDateString(),
                'comprobante_id' => $comprobante?->id,
                'registrado_por_user_id' => $userId,
            ]);

            $this->registrarMovimiento->__invoke(
                cuenta: $cuenta,
                tipo: TipoMovimiento::Egreso,
                fecha: $fecha,
                concepto: $descripcion,
                importe: $importe,
                categoria: $categoria->nombre,
                medioPagoId: $medio?->id,
                tipoAsiento: TipoAsiento::PagoGasto,
                origen: $gasto,
                userId: $userId,
            );

            return $gasto;
        });
    }
}
