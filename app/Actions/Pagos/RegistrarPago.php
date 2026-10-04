<?php

namespace App\Actions\Pagos;

use App\Actions\Libro\RegistrarMovimiento;
use App\Enums\EstadoPago;
use App\Enums\OrigenPago;
use App\Enums\TipoAsiento;
use App\Enums\TipoMovimiento;
use App\Models\Comprobante;
use App\Models\Cuenta;
use App\Models\MedioPago;
use App\Models\Pago;
use App\Models\Socio;
use App\Services\Pagos\ImputadorDePagos;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pago → imputación a cuotas → movimiento de ingreso en el libro, todo en una transacción.
 * La fecha es la del comprobante (no la de carga); el impuesto del día se recalcula solo al hacer commit.
 */
class RegistrarPago
{
    public function __construct(private ImputadorDePagos $imputador, private RegistrarMovimiento $registrarMovimiento) {}

    /**
     * @param  array<int|string, string|int|float>|null  $imputacionesManuales  cuota_id => importe; null = automática
     */
    public function __invoke(
        Socio $pagador,
        CarbonInterface $fecha,
        string $importe,
        Cuenta $cuenta,
        ?MedioPago $medio = null,
        ?string $concepto = null,
        OrigenPago $origen = OrigenPago::Web,
        ?array $imputacionesManuales = null,
        ?Comprobante $comprobante = null,
        ?string $referenciaExterna = null,
        ?int $userId = null,
    ): Pago {
        $bruto = BigDecimal::of($importe);
        if ($bruto->isNegativeOrZero()) {
            throw ValidationException::withMessages(['importe' => 'El importe debe ser mayor a cero.']);
        }

        if ($referenciaExterna && Pago::where('referencia_externa', $referenciaExterna)->where('estado', EstadoPago::Confirmado->value)->exists()) {
            throw ValidationException::withMessages(['referencia_externa' => "Ya hay un pago cargado con el número de operación {$referenciaExterna}."]);
        }

        return DB::transaction(function () use ($pagador, $fecha, $bruto, $cuenta, $medio, $concepto, $origen, $imputacionesManuales, $comprobante, $referenciaExterna, $userId) {
            $importeStr = (string) $bruto->toScale(2, RoundingMode::HALF_UP);

            $imputaciones = $imputacionesManuales === null
                ? $this->imputador->proponer($pagador, $importeStr, $fecha)['imputaciones']
                : $this->imputador->validarManual($pagador, $importeStr, $imputacionesManuales, $fecha);

            $pago = Pago::create([
                'socio_id' => $pagador->id,
                'fecha' => $fecha->toDateString(),
                'fecha_registro' => now(),
                'importe_bruto' => $importeStr,
                'medio_pago_id' => $medio?->id,
                'cuenta_id' => $cuenta->id,
                'estado' => EstadoPago::Confirmado,
                'origen' => $origen,
                'comprobante_id' => $comprobante?->id,
                'referencia_externa' => $referenciaExterna,
                'concepto' => $concepto,
                'registrado_por_user_id' => $userId,
                'confirmado_at' => now(),
            ]);

            $this->imputador->aplicar($pago, $imputaciones);

            $pago->update(['concepto' => $concepto ?? $this->conceptoAutomatico($pagador, $imputaciones)]);

            $this->registrarMovimiento->__invoke(
                cuenta: $cuenta,
                tipo: TipoMovimiento::Ingreso,
                fecha: $fecha,
                concepto: $pago->concepto,
                importe: $importeStr,
                categoria: 'Cuota Socio',
                medioPagoId: $medio?->id,
                tipoAsiento: TipoAsiento::CobroCuota,
                origen: $pago,
                userId: $userId,
            );

            if ($comprobante) {
                $comprobante->comprobantable()->associate($pago)->save();
            }

            return $pago->load('imputaciones');
        });
    }

    /** "Gelos Gabriel agosto, septiembre" — el mismo formato que el tesorero escribía a mano en el Excel. */
    private function conceptoAutomatico(Socio $pagador, array $imputaciones): string
    {
        if (! $imputaciones) {
            return "{$pagador->nombre_completo} (saldo a favor)";
        }

        $meses = collect($imputaciones)
            ->map(fn ($i) => $i['cuota']->periodo->locale('es')->translatedFormat('F'))
            ->unique()->values();
        $anios = collect($imputaciones)->map(fn ($i) => $i['cuota']->periodo->year)->unique();
        $sufijo = $anios->count() === 1 && $anios->first() !== now()->year ? ' '.$anios->first() : '';

        $parcial = collect($imputaciones)->contains(fn ($i) => ! $i['completa']) ? 'Parte cuota ' : '';

        return $parcial.$pagador->nombre_completo.' '.$meses->join(', ').$sufijo;
    }
}
