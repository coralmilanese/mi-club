<?php

namespace App\Services\Libro;

use App\Enums\TipoAsiento;
use App\Enums\TipoMovimiento;
use App\Models\Asiento;
use App\Models\Cuenta;
use App\Models\LiquidacionDiaria;
use App\Models\LiquidacionDiariaTributo;
use App\Models\Movimiento;
use App\Models\Tributo;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Liquidación diaria de impuestos: SIRCREB, impuesto al crédito e impuesto al débito se calculan por DÍA y por CUENTA
 * (no por pago), se recalculan en el momento en que cambia cualquier movimiento del día, y se materializan como
 * movimientos de egreso `es_tributo = true` que quedan afuera de las bases.
 */
class LiquidacionDiariaService
{
    public const OMITIDA = 'omitida';

    public const RECALCULADA = 'recalculada';

    public const MANUAL = 'manual';

    /** @return array{estado: string, motivo?: string, importes?: array<string, string>} */
    public function recalcular(Cuenta $cuenta, CarbonInterface $fecha): array
    {
        if (! $cuenta->aplica_tributos) {
            return ['estado' => self::OMITIDA, 'motivo' => 'La cuenta no aplica tributos.'];
        }

        // Antes de la fecha de corte mandan las liquidaciones importadas del Excel: el sistema no las reescribe.
        $corte = config('aasr.tributos.corte_recalculo_automatico');
        if ($corte && $fecha->toDateString() < CarbonImmutable::parse($corte)->toDateString()) {
            return ['estado' => self::OMITIDA, 'motivo' => "Anterior a la fecha de corte ({$corte})."];
        }

        return DB::transaction(function () use ($cuenta, $fecha) {
            $liq = $this->liquidacionBloqueada($cuenta, $fecha);

            if ($liq->ajustada_manualmente) {
                return ['estado' => self::MANUAL, 'motivo' => 'El día tiene ajuste manual: no se recalcula.'];
            }

            [$credito, $debito] = $this->bases($cuenta, $fecha);
            $importes = $this->calcular($fecha, $credito, $debito);

            $vacio = $credito->isZero() && $debito->isZero();

            $liq->fill([
                'base_credito' => (string) $credito,
                'base_debito_operativo' => (string) $debito,
                'recalculada_at' => now(),
            ])->save();

            $this->materializar($liq, $importes);

            if ($vacio) {
                // Día que quedó en cero (se anuló el único pago): sin movimientos de tributo ni liquidación.
                $liq->delete();
            }

            return ['estado' => self::RECALCULADA, 'importes' => collect($importes)->map(fn ($i) => $i['importe'])->all()];
        });
    }

    /**
     * Override manual: el tesorero fija los importes del día (el banco aplica mínimos y exenciones).
     * Queda `ajustada_manualmente` y el recálculo automático deja de tocar ese día.
     *
     * @param  array<string, string|float|int>  $importesPorCodigo  ej. ['sircreb' => '1050.00', 'imp_credito' => '210.00']
     */
    public function ajustar(Cuenta $cuenta, CarbonInterface $fecha, array $importesPorCodigo, string $motivo, ?int $userId = null): LiquidacionDiaria
    {
        return DB::transaction(function () use ($cuenta, $fecha, $importesPorCodigo, $motivo, $userId) {
            $liq = $this->liquidacionBloqueada($cuenta, $fecha);
            [$credito, $debito] = $this->bases($cuenta, $fecha);

            $importes = [];
            foreach (Tributo::where('activo', true)->orderBy('orden')->get() as $tributo) {
                $alicuota = $tributo->alicuotaVigente($fecha);
                $importes[$tributo->id] = [
                    'tributo' => $tributo,
                    'alicuota' => $alicuota?->alicuota,
                    'base' => null,
                    'importe' => $this->redondear(BigDecimal::of((string) ($importesPorCodigo[$tributo->codigo] ?? 0))),
                ];
            }

            $liq->fill([
                'base_credito' => (string) $credito,
                'base_debito_operativo' => (string) $debito,
                'ajustada_manualmente' => true,
                'ajustada_por_user_id' => $userId,
                'motivo_ajuste' => $motivo,
                'recalculada_at' => now(),
            ])->save();

            $this->materializar($liq, $importes);

            return $liq->refresh();
        });
    }

    /** Vuelve un día ajustado a cálculo automático y lo recalcula de inmediato. */
    public function restablecer(Cuenta $cuenta, CarbonInterface $fecha): void
    {
        LiquidacionDiaria::where('cuenta_id', $cuenta->id)->whereDate('fecha', $fecha->toDateString())
            ->update(['ajustada_manualmente' => false, 'ajustada_por_user_id' => null, 'motivo_ajuste' => null]);

        $this->recalcular($cuenta, $fecha);
    }

    /**
     * Cálculo puro (sin tocar la base). SIRCREB va primero: la base del impuesto al débito lo necesita.
     *
     * @return array<int, array{tributo: Tributo, alicuota: string|null, base: string|null, importe: string}>
     */
    public function calcular(CarbonInterface $fecha, BigDecimal $baseCredito, BigDecimal $baseDebito): array
    {
        $resultado = [];
        $retenciones = BigDecimal::zero();

        foreach (Tributo::where('activo', true)->orderBy('orden')->orderBy('id')->get() as $tributo) {
            $alicuota = $tributo->alicuotaVigente($fecha);
            $base = $tributo->base->value === 'credito' ? $baseCredito : $baseDebito;
            if ($tributo->incluye_retenciones_en_base) {
                $base = $base->plus($retenciones);
            }

            $importe = $alicuota
                ? $this->redondear($base->multipliedBy((string) $alicuota->alicuota)->dividedBy(100, 10, RoundingMode::HALF_UP))
                : '0.00';

            if ($tributo->es_retencion) {
                $retenciones = $retenciones->plus($importe);
            }

            $resultado[$tributo->id] = [
                'tributo' => $tributo,
                'alicuota' => $alicuota?->alicuota,
                'base' => (string) $base->toScale(2, RoundingMode::HALF_UP),
                'importe' => $importe,
            ];
        }

        return $resultado;
    }

    /**
     * Bases del día: movimientos NO tributo, NO anulados y que no sean la apertura de la cuenta.
     *
     * @return array{0: BigDecimal, 1: BigDecimal} [crédito (ingresos), débito operativo (egresos)]
     */
    public function bases(Cuenta $cuenta, CarbonInterface $fecha): array
    {
        $sumas = Movimiento::query()
            ->where('cuenta_id', $cuenta->id)
            ->whereDate('fecha', $fecha->toDateString())
            ->where('es_tributo', false)
            ->whereNull('anulado_at')
            ->whereDoesntHave('asiento', fn ($q) => $q->where('tipo', TipoAsiento::Apertura->value))
            ->selectRaw('tipo, SUM(importe) AS total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        return [
            BigDecimal::of((string) ($sumas[TipoMovimiento::Ingreso->value] ?? 0)),
            BigDecimal::of((string) ($sumas[TipoMovimiento::Egreso->value] ?? 0)),
        ];
    }

    private function liquidacionBloqueada(Cuenta $cuenta, CarbonInterface $fecha): LiquidacionDiaria
    {
        $liq = LiquidacionDiaria::firstOrCreate(['cuenta_id' => $cuenta->id, 'fecha' => $fecha->toDateString()]);

        // Lock pesimista: dos recálculos concurrentes del mismo día se serializan.
        return LiquidacionDiaria::whereKey($liq->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Upsert de los tributos del día y de sus movimientos. Un importe en cero borra el movimiento (no deja filas en $0).
     *
     * @param  array<int, array{tributo: Tributo, alicuota: string|null, base: string|null, importe: string}>  $importes
     */
    private function materializar(LiquidacionDiaria $liq, array $importes): void
    {
        $cuenta = $liq->cuenta;
        $fecha = $liq->fecha;

        foreach ($importes as $tributoId => $r) {
            $ldt = LiquidacionDiariaTributo::where('liquidacion_diaria_id', $liq->id)->where('tributo_id', $tributoId)->first();
            $importe = BigDecimal::of($r['importe']);

            if ($importe->isZero()) {
                if ($ldt) {
                    $ldt->movimiento?->delete();
                    $ldt->delete();
                }

                continue;
            }

            $ldt ??= new LiquidacionDiariaTributo(['liquidacion_diaria_id' => $liq->id, 'tributo_id' => $tributoId]);
            $ldt->fill(['alicuota_aplicada' => $r['alicuota'], 'base_imponible' => $r['base'], 'importe' => $r['importe']]);
            $ldt->save();

            $asiento = Asiento::firstOrCreate([
                'tipo' => TipoAsiento::LiquidacionDiaria->value,
                'fecha' => $fecha->toDateString(),
                'descripcion' => "Liquidación de impuestos — {$cuenta->nombre}",
            ]);

            $datos = [
                'fecha' => $fecha->toDateString(),
                'concepto' => $r['tributo']->concepto_libro ?? $r['tributo']->categoria_libro,
                'tipo' => TipoMovimiento::Egreso,
                'importe' => $r['importe'],
                'cuenta_id' => $cuenta->id,
                'categoria_libro' => $r['tributo']->categoria_libro,
                'es_tributo' => true,
                'asiento_id' => $asiento->id,
                'origenable_type' => $ldt->getMorphClass(),
                'origenable_id' => $ldt->id,
            ];

            if ($ldt->movimiento) {
                $ldt->movimiento->update($datos);
            } else {
                $mov = Movimiento::create($datos);
                $ldt->update(['movimiento_id' => $mov->id]);
            }
        }
    }

    private function redondear(BigDecimal $valor): string
    {
        return (string) $valor->toScale(2, RoundingMode::HALF_UP);
    }
}
