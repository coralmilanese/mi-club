<?php

namespace App\Services\Importacion;

use App\Enums\TipoAsiento;
use App\Enums\TipoMovimiento;
use App\Models\Asiento;
use App\Models\CategoriaGasto;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\LiquidacionDiaria;
use App\Models\LiquidacionDiariaTributo;
use App\Models\MedioPago;
use App\Models\Movimiento;
use App\Models\Tributo;
use App\Services\Libro\LiquidacionDiariaService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Importa la hoja ` Libro de Caja`: aperturas por cuenta (derivadas del bloque de conciliación del pie), movimientos,
 * gastos y las liquidaciones de impuestos tal cual las cobró el banco. NO dispara el recálculo automático: los
 * impuestos históricos ya están conciliados, se marcan `ajustada_manualmente` y el sistema no los reescribe.
 */
class ImportadorLibroCaja
{
    /** Categorías del Excel → categoría de gasto (las dos grafías de cada una se unifican). */
    private const GASTOS = [
        'planeadores' => 'cpsr', 'cpsr' => 'cpsr', 'cpsr / planeadores' => 'cpsr',
        'federacion' => 'faa', 'faa' => 'faa', 'faa / federacion' => 'faa',
        'seguro' => 'seguro',
        'honorarios contadora' => 'honorarios_contadora',
        'serv. pago recaud.' => 'servicios',
    ];

    private const TRIBUTOS = [
        '/^IMP\.\s*I\.B\s*SIRCREB/i' => 'sircreb',
        '/^IMP\.DEB\/CRED P\/CRED/i' => 'imp_credito',
        '/^IMP\.DEB\/CRED P\/DEB/i' => 'imp_debito',
    ];

    /** @var list<string> */
    private array $advertencias = [];

    public function __construct(private LiquidacionDiariaService $liquidaciones) {}

    /**
     * @return array<string, mixed>
     */
    public function importar(string $archivo, bool $dryRun = false): array
    {
        if (Movimiento::query()->exists()) {
            throw new \RuntimeException('El libro de caja ya tiene movimientos: la importación solo se puede hacer sobre un libro vacío.');
        }
        $this->advertencias = [];

        [$filas, $pie] = $this->leer($archivo);

        $banco = Cuenta::where('nombre', 'Banco AASR')->firstOrFail();
        $efectivoCuenta = Cuenta::where('nombre', config('aasr.importacion.cuenta_efectivo', 'Efectivo'))->firstOrFail();
        $plazo = Cuenta::where('nombre', 'Plazo Fijo')->firstOrFail();
        $transferencia = MedioPago::where('codigo', 'transferencia')->firstOrFail();
        $efectivo = MedioPago::where('codigo', 'efectivo')->firstOrFail();

        DB::beginTransaction();
        try {
            $resumen = Movimiento::withoutEvents(function () use ($filas, $pie, $banco, $efectivoCuenta, $plazo, $transferencia, $efectivo) {
                $aperturas = $this->aperturas($filas, $pie, $banco, $efectivoCuenta, $plazo);
                $creados = $this->cargarFilas($filas, $banco, $efectivoCuenta, $transferencia, $efectivo);
                $comparacion = $this->cargarLiquidaciones($banco, $creados['tributos']);

                return ['aperturas' => $aperturas, 'movimientos' => $creados['contadores'], 'liquidaciones' => $comparacion, 'inferencias' => $creados['inferencias']];
            });

            $resumen['saldos'] = collect([$banco, $efectivoCuenta, $plazo])->mapWithKeys(fn (Cuenta $c) => [$c->nombre => $c->saldoAl()])->all();
            $resumen['saldo_total'] = number_format(array_sum(array_map('floatval', $resumen['saldos'])), 2, '.', '');
            $resumen['saldo_esperado'] = $pie['total'] !== null ? number_format($pie['total'], 2, '.', '') : null;
            $resumen['advertencias'] = $this->advertencias;

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $resumen;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array{apertura: float|null, banco: float|null, efectivo: float|null, plazo: float|null, total: float|null}}
     */
    private function leer(string $archivo): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([' Libro de Caja', 'Libro de Caja']);
        $wb = $reader->load($archivo);
        $ws = $wb->getSheetByName(' Libro de Caja') ?? $wb->getSheetByName('Libro de Caja')
            ?? throw new \RuntimeException("El archivo no tiene una hoja 'Libro de Caja'.");

        $filas = [];
        $pie = ['apertura' => null, 'banco' => null, 'efectivo' => null, 'plazo' => 0.0, 'total' => null];

        foreach ($ws->toArray(null, true, false, false) as $i => $c) {
            if ($i === 0) {
                continue;
            }
            $concepto = trim((string) ($c[1] ?? ''));

            // Bloque de conciliación del pie.
            if (is_numeric($c[2] ?? null)) {
                if (preg_match('/^Saldo en cuenta bancaria/i', $concepto)) {
                    $pie['banco'] = (float) $c[2];
                } elseif (preg_match('/^Efectivo en Mano/i', $concepto)) {
                    // El Excel cuenta el efectivo en manos de dos personas (Fer y Fran); acá es una sola cuenta.
                    $pie['efectivo'] = ($pie['efectivo'] ?? 0.0) + (float) $c[2];
                }
            }
            if (preg_match('/plazo fijo\s*\$\s*([\d.,]+)/i', $concepto, $m)) {
                $pie['plazo'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            }
            if (preg_match('/^Total\/Diferencia/i', $concepto)) {
                $pie['total'] = is_numeric($c[5] ?? null) ? round((float) $c[5], 2) : null;
            }

            if (! is_numeric($c[0] ?? null) || (! is_numeric($c[3] ?? null) && ! is_numeric($c[4] ?? null))) {
                continue;
            }

            $fila = [
                'fila' => $i + 1,
                'fecha' => CarbonImmutable::instance(Date::excelToDateTimeObject((float) $c[0]))->startOfDay(),
                'concepto' => $concepto,
                'ingreso' => is_numeric($c[3] ?? null) ? $this->dec((float) $c[3]) : null,
                'egreso' => is_numeric($c[4] ?? null) ? $this->dec((float) $c[4]) : null,
                'transaccion' => mb_strtolower(trim((string) ($c[6] ?? ''))),
                'categoria' => trim((string) ($c[7] ?? '')),
            ];

            if (preg_match('/^Saldo Comienzo/i', $concepto)) {
                $pie['apertura'] = (float) $fila['ingreso'];

                continue;
            }
            $filas[] = $fila;
        }

        return [$filas, $pie];
    }

    /**
     * El Excel tiene UN saldo de apertura para todo el club. Se reparte entre cuentas de modo que el saldo final de
     * cada una coincida con el bloque de conciliación del pie (banco, efectivo, plazo fijo).
     *
     * @param  list<array<string, mixed>>  $filas
     * @param  array<string, float|null>  $pie
     * @return array<string, string>
     */
    private function aperturas(array $filas, array $pie, Cuenta $banco, Cuenta $efectivoCuenta, Cuenta $plazo): array
    {
        $neto = fn (bool $efectivo) => array_sum(array_map(
            fn ($f) => ((float) ($f['ingreso'] ?? 0)) - ((float) ($f['egreso'] ?? 0)),
            array_filter($filas, fn ($f) => ($f['transaccion'] === 'efectivo') === $efectivo),
        ));

        $fecha = CarbonImmutable::parse(config('aasr.importacion.fecha_apertura', '2026-01-01'));

        if ($pie['banco'] === null || $pie['efectivo'] === null) {
            $this->advertencias[] = 'No encontré el bloque de conciliación del pie: todo el saldo de apertura se carga en el Banco.';
            $montos = [$banco->id => (float) $pie['apertura'], $efectivoCuenta->id => 0.0, $plazo->id => 0.0];
        } else {
            $montos = [
                $banco->id => round($pie['banco'] - $neto(false), 2),
                $efectivoCuenta->id => round($pie['efectivo'] - $neto(true), 2),
                $plazo->id => round($pie['plazo'] ?? 0, 2),
            ];
            $suma = array_sum($montos);
            if ($pie['apertura'] !== null && abs($suma - $pie['apertura']) > 0.01) {
                $this->advertencias[] = sprintf('Las aperturas derivadas del pie (%.2f) no suman el saldo de apertura del Excel (%.2f).', $suma, $pie['apertura']);
            }
        }

        $resultado = [];
        foreach ([$banco, $efectivoCuenta, $plazo] as $cuenta) {
            $monto = $montos[$cuenta->id];
            if ($monto < 0) {
                $this->advertencias[] = "La apertura derivada de {$cuenta->nombre} es negativa ({$monto}): revisá el bloque de conciliación.";
            }
            if ($monto <= 0) {
                $resultado[$cuenta->nombre] = '0.00';

                continue;
            }
            $cuenta->update(['saldo_inicial' => $monto, 'fecha_saldo_inicial' => $fecha->toDateString()]);
            $asiento = Asiento::create(['fecha' => $fecha->toDateString(), 'descripcion' => "Saldo de apertura {$fecha->format('d/m/Y')}", 'tipo' => TipoAsiento::Apertura]);
            Movimiento::create([
                'fecha' => $fecha->toDateString(), 'concepto' => "Saldo de apertura {$fecha->format('d/m/Y')}", 'tipo' => TipoMovimiento::Ingreso,
                'importe' => $this->dec($monto), 'cuenta_id' => $cuenta->id, 'categoria_libro' => 'Saldo al Comienzo', 'asiento_id' => $asiento->id,
            ]);
            $resultado[$cuenta->nombre] = $this->dec($monto);
        }

        return $resultado;
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array{contadores: array<string, int>, tributos: array<string, array<string, array{importe: BigDecimal, movimiento_id: int}>>, inferencias: list<string>}
     */
    private function cargarFilas(array $filas, Cuenta $banco, Cuenta $cajaEfectivo, MedioPago $transferencia, MedioPago $efectivo): array
    {
        $tributos = Tributo::pluck('id', 'codigo');
        $categoriasGasto = CategoriaGasto::pluck('id', 'codigo');
        $contadores = ['cuotas' => 0, 'gastos' => 0, 'impuestos' => 0, 'otros_ingresos' => 0];
        $porFecha = [];
        $inferencias = [];

        foreach ($filas as $f) {
            $esEfectivo = $f['transaccion'] === 'efectivo';
            $cuenta = $esEfectivo ? $cajaEfectivo : $banco;
            $medio = $esEfectivo ? $efectivo : $transferencia;
            $fecha = $f['fecha']->toDateString();
            $tipo = $f['ingreso'] !== null ? TipoMovimiento::Ingreso : TipoMovimiento::Egreso;
            $importe = $f['ingreso'] ?? $f['egreso'];

            $codigoTributo = $this->tributoDe($f['concepto']);
            if ($codigoTributo && isset($tributos[$codigoTributo])) {
                $t = Tributo::find($tributos[$codigoTributo]);
                $asiento = Asiento::firstOrCreate(['tipo' => TipoAsiento::LiquidacionDiaria->value, 'fecha' => $fecha, 'descripcion' => "Liquidación de impuestos — {$banco->nombre}"]);
                $mov = Movimiento::create([
                    'fecha' => $fecha, 'concepto' => $f['concepto'], 'tipo' => TipoMovimiento::Egreso, 'importe' => $importe, 'cuenta_id' => $banco->id,
                    'categoria_libro' => $t->categoria_libro, 'es_tributo' => true, 'asiento_id' => $asiento->id,
                ]);
                $previo = $porFecha[$fecha][$codigoTributo] ?? null;
                $porFecha[$fecha][$codigoTributo] = [
                    'importe' => ($previo['importe'] ?? BigDecimal::zero())->plus($importe),
                    'movimiento_id' => $previo['movimiento_id'] ?? $mov->id,
                ];
                $contadores['impuestos']++;

                continue;
            }

            [$categoria, $inferida] = $this->categoria($f, $tipo);
            if ($inferida) {
                $inferencias[] = "Fila {$f['fila']}: '{$f['concepto']}' sin categoría en el Excel → '{$categoria}'.";
            }

            $codigoGasto = null;
            if ($tipo === TipoMovimiento::Egreso) {
                $codigoGasto = self::GASTOS[$this->clave($f['categoria'])] ?? self::GASTOS[$this->clave($categoria)] ?? null;
                if ($codigoGasto === null) {
                    $codigoGasto = 'varios';
                    $inferencias[] = "Fila {$f['fila']}: egreso '{$f['concepto']}' con categoría desconocida '{$categoria}' → gasto 'Varios'.";
                }
            }

            if ($codigoGasto) {
                $cat = CategoriaGasto::find($categoriasGasto[$codigoGasto]);
                $categoria = $cat->nombre;
                $asiento = Asiento::create(['fecha' => $fecha, 'descripcion' => $f['concepto'], 'tipo' => TipoAsiento::PagoGasto]);
                $gasto = Gasto::create([
                    'categoria_gasto_id' => $cat->id, 'fecha' => $fecha, 'importe' => $importe, 'descripcion' => $f['concepto'],
                    'medio_pago_id' => $medio->id, 'cuenta_id' => $cuenta->id,
                ]);
                Movimiento::create([
                    'fecha' => $fecha, 'concepto' => $f['concepto'], 'tipo' => $tipo, 'importe' => $importe, 'cuenta_id' => $cuenta->id, 'medio_pago_id' => $medio->id,
                    'categoria_libro' => $categoria, 'asiento_id' => $asiento->id, 'origenable_type' => $gasto->getMorphClass(), 'origenable_id' => $gasto->id,
                ]);
                $contadores['gastos']++;

                continue;
            }

            // Todo egreso terminó como gasto más arriba: acá sólo quedan ingresos.
            $esCuota = $categoria === 'Cuota Socio';
            $asiento = Asiento::create(['fecha' => $fecha, 'descripcion' => $f['concepto'], 'tipo' => $esCuota ? TipoAsiento::CobroCuota : TipoAsiento::Ajuste]);
            Movimiento::create([
                'fecha' => $fecha, 'concepto' => $f['concepto'], 'tipo' => $tipo, 'importe' => $importe, 'cuenta_id' => $cuenta->id, 'medio_pago_id' => $medio->id,
                'categoria_libro' => $categoria, 'asiento_id' => $asiento->id,
            ]);
            $contadores[$esCuota ? 'cuotas' : 'otros_ingresos']++;
        }

        return ['contadores' => $contadores, 'tributos' => $porFecha, 'inferencias' => $inferencias];
    }

    /**
     * Una liquidación diaria por cada día con impuestos, marcada como ajustada a mano (importada del Excel).
     * Además compara con lo que calcularía el motor: valida las fórmulas contra todo el histórico real.
     *
     * @param  array<string, array<string, array{importe: BigDecimal, movimiento_id: int}>>  $porFecha
     * @return array{dias: int, coinciden: int, difieren: list<string>}
     */
    private function cargarLiquidaciones(Cuenta $banco, array $porFecha): array
    {
        $coinciden = 0;
        $difieren = [];
        $tributos = Tributo::where('activo', true)->get()->keyBy('codigo');

        foreach ($porFecha as $fecha => $importes) {
            $f = CarbonImmutable::parse($fecha);
            [$credito, $debito] = $this->liquidaciones->bases($banco, $f);
            $calculado = $this->liquidaciones->calcular($f, $credito, $debito);

            $liq = LiquidacionDiaria::create([
                'cuenta_id' => $banco->id, 'fecha' => $fecha,
                'base_credito' => (string) $credito, 'base_debito_operativo' => (string) $debito,
                'ajustada_manualmente' => true, 'motivo_ajuste' => 'Importado del Excel (ya conciliado con el resumen bancario).', 'recalculada_at' => now(),
            ]);

            $ok = true;
            foreach ($importes as $codigo => $datos) {
                $tributo = $tributos[$codigo];
                $c = $calculado[$tributo->id] ?? null;
                LiquidacionDiariaTributo::create([
                    'liquidacion_diaria_id' => $liq->id, 'tributo_id' => $tributo->id, 'alicuota_aplicada' => $c['alicuota'] ?? null,
                    'base_imponible' => $c['base'] ?? null, 'importe' => (string) $datos['importe']->toScale(2, RoundingMode::HALF_UP), 'movimiento_id' => $datos['movimiento_id'],
                ]);
                Movimiento::whereKey($datos['movimiento_id'])->update(['origenable_type' => (new LiquidacionDiariaTributo)->getMorphClass(), 'origenable_id' => LiquidacionDiariaTributo::where('liquidacion_diaria_id', $liq->id)->where('tributo_id', $tributo->id)->value('id')]);

                if ($c && abs((float) $c['importe'] - (float) (string) $datos['importe']) > 0.011) {
                    $ok = false;
                    $difieren[] = sprintf('%s %s: Excel %s vs motor %s', $fecha, $codigo, $datos['importe'], $c['importe']);
                }
            }
            $ok ? $coinciden++ : null;
        }

        return ['dias' => count($porFecha), 'coinciden' => $coinciden, 'difieren' => $difieren];
    }

    /** @param array<string, mixed> $f @return array{0: string, 1: bool} categoría normalizada y si hubo que inferirla */
    private function categoria(array $f, TipoMovimiento $tipo): array
    {
        $cat = $f['categoria'];
        $clave = $this->clave($cat);

        return match (true) {
            $cat === '' && $tipo === TipoMovimiento::Ingreso => ['Cuota Socio', true],
            $cat === '' && str_contains($this->clave($f['concepto']), 'federaci') => ['FAA / Federación', true],
            $cat === '' && str_contains($this->clave($f['concepto']), 'planeadores') => ['CPSR / Planeadores', true],
            $cat === '' => ['Sin categoría', true],
            $clave === 'debito/credito impuesto' => ['Débito/Crédito Impuesto', false],
            $clave === 'siscreb' => ['SIRCREB', false],
            in_array($clave, ['cpsr', 'planeadores'], true) => ['CPSR / Planeadores', false],
            in_array($clave, ['faa', 'federacion'], true) => ['FAA / Federación', false],
            $clave === 'seguro' => ['Seguro', false],
            default => [$cat, false],
        };
    }

    private function tributoDe(string $concepto): ?string
    {
        foreach (self::TRIBUTOS as $regex => $codigo) {
            if (preg_match($regex, $concepto)) {
                return $codigo;
            }
        }

        return null;
    }

    private function clave(string $texto): string
    {
        return mb_strtolower(strtr(trim($texto), ['ó' => 'o', 'Ó' => 'O', 'é' => 'e', 'í' => 'i', 'á' => 'a', 'ú' => 'u']));
    }

    private function dec(float|string $v): string
    {
        return (string) BigDecimal::of((string) $v)->toScale(2, RoundingMode::HALF_UP);
    }
}
