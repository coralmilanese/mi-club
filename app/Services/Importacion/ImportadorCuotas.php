<?php

namespace App\Services\Importacion;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPago;
use App\Enums\OrigenPago;
use App\Enums\TipoAsiento;
use App\Enums\TipoMovimiento;
use App\Models\Cuota;
use App\Models\Movimiento;
use App\Models\Pago;
use App\Models\PagoCuota;
use App\Models\Socio;
use App\Services\Pagos\ImputadorDePagos;
use App\Services\Socios\BuscadorPorNombre;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Migra la grilla de cuotas del Excel (` Cuotas`) a las cuotas ya devengadas del sistema y, con el libro de caja ya
 * importado, vincula cada ingreso "Cuota Socio" con su socio y sus meses (crea el Pago). Todo lo que no se puede
 * emparejar con certeza NO se inventa: queda en el reporte para que el tesorero lo resuelva.
 */
class ImportadorCuotas
{
    private const MESES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    /** Marca de un ingreso que corresponde a períodos anteriores al año migrado: se ignora a propósito. */
    private const PREVIO = 'previo';

    private const MES_REGEX = 'ene(?:ro)?|feb(?:rero)?|mar(?:zo)?|abr(?:il)?|may(?:o)?|jun(?:io)?|jul(?:io)?|ago(?:sto)?|sep(?:t(?:iembre)?)?|set(?:iembre)?|oct(?:ubre)?|nov(?:iembre)?|dic(?:iembre)?';

    /** @var list<string> */
    private array $advertencias = [];

    private int $anioMigrado = 2026;

    /** @var array<int, array<string, float>> socio_id → período → importe que la grilla registra pero para el que todavía no hay cuota (pagos adelantados) */
    private array $futuros = [];

    public function __construct(
        private LectorGrillaCuotas $lector,
        private EmparejadorGrilla $emparejador,
        private BuscadorPorNombre $buscador,
        private ImputadorDePagos $imputador,
    ) {}

    /** @return array<string, mixed> */
    public function importar(string $archivo, int $anio = 2026, bool $dryRun = false): array
    {
        $this->advertencias = [];
        $this->futuros = [];
        $grilla = $this->lector->leer($archivo);

        DB::beginTransaction();
        try {
            $asignacion = $this->emparejador->emparejar($grilla);
            $grillaR = $this->aplicarGrilla($grilla, $asignacion, $anio);
            $pagosR = $this->vincularPagos($anio);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [...$grillaR, ...$pagosR, 'advertencias' => $this->advertencias];
    }

    // ---- Fase 1: la grilla socio × mes -----------------------------------------------------------------------------

    /**
     * @param  list<array{fila: int, nombre: string, meses: array<int, array<string, mixed>|null>}>  $grilla
     * @param  array{por_fila: array<int, array{socio: Socio, score: float}>, por_socio: array<int, int>}  $asignacion
     * @return array<string, mixed>
     */
    private function aplicarGrilla(array $grilla, array $asignacion, int $anio): array
    {
        $cobradas = 0;
        $monto = BigDecimal::zero();
        $sinSocio = [];
        $totalGrilla = BigDecimal::zero();
        $totalSinSocio = BigDecimal::zero();

        foreach ($grilla as $idx => $fila) {
            $sumaFila = BigDecimal::zero();
            foreach ($fila['meses'] as $c) {
                if (($c['tipo'] ?? null) === 'pago') {
                    $sumaFila = $sumaFila->plus((string) $c['importe']);
                }
            }
            $totalGrilla = $totalGrilla->plus($sumaFila);

            if (! $this->emparejador->esConfiable($asignacion, $idx)) {
                $sinSocio[] = $fila['nombre'].($sumaFila->isZero() ? '' : " (\${$sumaFila})");
                $totalSinSocio = $totalSinSocio->plus($sumaFila);

                continue;
            }

            $socio = $asignacion['por_fila'][$idx]['socio'];
            foreach ($fila['meses'] as $mes => $celda) {
                if (($celda['tipo'] ?? null) !== 'pago' || $celda['importe'] <= 0) {
                    continue;
                }
                $periodo = CarbonImmutable::create($anio, $mes, 1);
                $aplicado = $this->marcarCobrada($socio, $periodo, (string) $celda['importe']);
                if ($aplicado !== null) {
                    $cobradas += $aplicado['cuotas'];
                    $monto = $monto->plus((string) $celda['importe']);
                } elseif ($periodo->gt(CarbonImmutable::now()->startOfMonth())) {
                    // Pago adelantado: la cuota se devenga más adelante y tomará este saldo a favor.
                    $this->futuros[$socio->id][$periodo->format('Y-m')] = (float) $celda['importe'];
                } else {
                    $this->advertencias[] = "{$socio->nombre_completo} {$periodo->format('m/Y')}: la grilla registra \${$celda['importe']} pero no hay cuota devengada (¿sin plan ese mes?). No se cargó.";
                }
            }
        }

        return [
            'filas_grilla' => count($grilla),
            'filas_sin_socio' => $sinSocio,
            'cuotas_cobradas' => $cobradas,
            'monto_aplicado' => (string) $monto,
            'total_grilla' => (string) $totalGrilla,
            'total_sin_socio' => (string) $totalSinSocio,
        ];
    }

    /**
     * Marca la cuota del socio como cobrada por lo que dice la grilla. Si el socio es titular de un grupo, el importe
     * incluye los adicionales de sus adherentes: se separa buscando base + k × adicional entre las tarifas históricas.
     *
     * @return array{cuotas: int}|null
     */
    private function marcarCobrada(Socio $socio, CarbonImmutable $periodo, string $importe): ?array
    {
        $propia = Cuota::with('plan.tarifas')->where('socio_id', $socio->id)->whereDate('periodo', $periodo->toDateString())->whereNull('socio_pagador_id')->first();
        if (! $propia) {
            return null;
        }

        $adicionales = Cuota::with('plan.tarifas')->where('socio_pagador_id', $socio->id)->whereDate('periodo', $periodo->toDateString())->get();
        $n = 1;

        // Si alguna cuota del grupo ya tiene un pago real detrás, manda el libro y no se pisa con la planilla.
        if ($propia->imputaciones()->exists() || $adicionales->contains(fn (Cuota $a) => $a->imputaciones()->exists())) {
            return ['cuotas' => 0];
        }

        if ($adicionales->isEmpty()) {
            $this->cobrar($propia, $importe);

            return ['cuotas' => 1];
        }

        $bases = $propia->plan->tarifas->pluck('importe')->map(fn ($i) => (float) $i)->all();
        $adics = $adicionales->first()->plan->tarifas->pluck('importe')->map(fn ($i) => (float) $i)->all();
        $k = $adicionales->count();
        foreach ($bases as $base) {
            foreach ($adics as $adic) {
                if (abs($base + $k * $adic - (float) $importe) < 0.005) {
                    $this->cobrar($propia, number_format($base, 2, '.', ''));
                    foreach ($adicionales as $a) {
                        $this->cobrar($a, number_format($adic, 2, '.', ''));
                        $n++;
                    }

                    return ['cuotas' => $n];
                }
            }
        }

        // No cierra con ninguna combinación de tarifas: el titular absorbe el total y se avisa.
        $this->advertencias[] = "{$socio->nombre_completo} {$periodo->format('m/Y')}: \${$importe} no se puede separar en cuota propia + {$k} adicional(es) con las tarifas conocidas. Se cargó todo a su cuota propia.";
        $this->cobrar($propia, $importe);

        return ['cuotas' => 1];
    }

    private function cobrar(Cuota $cuota, string $importe): void
    {
        if ($cuota->estado === EstadoCuota::Pagada && abs((float) $cuota->importe_cobrado - (float) $importe) < 0.005) {
            return; // ya cargada (la importación es idempotente)
        }
        if ($cuota->imputaciones()->exists()) {
            return; // ya tiene un pago real detrás: manda el libro, no la planilla
        }

        $tarifa = $cuota->plan->tarifas->first(fn ($t) => abs((float) $t->importe - (float) $importe) < 0.005);

        $cuota->fill([
            'estado' => EstadoCuota::Pagada,
            'importe_cobrado' => $importe,
            'importe_imputado' => $importe,
            'tarifa_plan_id_cobrada' => $tarifa?->id,
            'observaciones' => str_contains((string) $cuota->observaciones, 'planilla Cuotas del Excel') ? $cuota->observaciones : trim(($cuota->observaciones ? $cuota->observaciones."\n" : '').'Cobrada según la planilla Cuotas del Excel.'),
        ])->save();
    }

    // ---- Fase 2: ingresos del libro ↔ socio ↔ meses ---------------------------------------------------------------

    /** @return array{pagos_vinculados: int, pagos_previos: int, pagos_sin_vincular: list<array<string, mixed>>, cuotas_cobradas_sin_pago: int} */
    private function vincularPagos(int $anio): array
    {
        $this->anioMigrado = $anio;
        $previos = 0;
        $vinculados = 0;
        $pendientes = [];
        // Cuotas que ya tienen un pago: si se vuelve a correr la importación no se les asocia otro.
        $usadas = PagoCuota::query()->pluck('cuota_id');

        $ingresos = Movimiento::query()->where('categoria_libro', 'Cuota Socio')->where('tipo', TipoMovimiento::Ingreso->value)
            ->whereNull('origenable_type')->where('es_tributo', false)->orderBy('fecha')->orderBy('id')->get();

        foreach ($ingresos as $mov) {
            $r = $this->vincular($mov, $usadas);
            if ($r === true) {
                $vinculados++;
            } elseif ($r === self::PREVIO) {
                $previos++;
            } else {
                $pendientes[] = ['movimiento_id' => $mov->id, 'fecha' => $mov->fecha->toDateString(), 'concepto' => $mov->concepto, 'importe' => $mov->importe, 'motivo' => $r];
            }
        }

        $sinPago = Cuota::where('estado', EstadoCuota::Pagada->value)->whereDoesntHave('imputaciones')->count();

        return ['pagos_vinculados' => $vinculados, 'pagos_previos' => $previos, 'pagos_sin_vincular' => $pendientes, 'cuotas_cobradas_sin_pago' => $sinPago];
    }

    /** @param Collection<int, int> $usadas ids de cuotas ya vinculadas a un pago */
    private function vincular(Movimiento $mov, Collection $usadas): true|string
    {
        [$nombre, $meses, $parcial] = $this->interpretar($mov->concepto);
        if (mb_strlen($nombre) < 3) {
            return 'No pude leer el nombre del socio en el concepto.';
        }

        $candidatos = $this->buscador->candidatos($nombre, 3, 0.25);
        if ($candidatos->isEmpty()) {
            return "Ningún socio del padrón se parece a '{$nombre}'.";
        }

        if ($parcial && ! $meses) {
            return $this->vincularParcial($mov, $candidatos->first()['socio']);
        }
        if (! $meses) {
            return 'El concepto no indica qué meses paga.';
        }

        $motivo = 'Los meses indicados no coinciden con cuotas cobradas de los candidatos (¿incluye períodos anteriores a 2026?).';
        foreach ($candidatos as $c) {
            /** @var Socio $socio */
            $socio = $c['socio'];
            $todas = Cuota::where(fn ($q) => $q->where(fn ($q) => $q->where('socio_id', $socio->id)->whereNull('socio_pagador_id'))->orWhere('socio_pagador_id', $socio->id))->get();
            $porPeriodo = $todas->groupBy(fn (Cuota $q) => $q->periodo->format('Y-m'));

            // Mes sin año: el del movimiento si hay cuota ese mes; si no, el año anterior (pago de meses atrasados).
            $periodos = [];
            foreach ($meses as [$mes, $anioExplicito]) {
                $anios = $anioExplicito ? [$anioExplicito] : [$mov->fecha->year, $mov->fecha->year - 1];
                $elegido = collect($anios)->first(fn ($a) => isset($porPeriodo[sprintf('%04d-%02d', $a, $mes)]) || isset($this->futuros[$socio->id][sprintf('%04d-%02d', $a, $mes)])) ?? $anios[array_key_last($anios)];
                $periodos[] = sprintf('%04d-%02d', $elegido, $mes);
            }
            $periodos = array_values(array_unique($periodos));

            // Pagos de meses anteriores al año que se migra (ej. "diciembre 2025"): no importan, quedan solo en el libro.
            if (collect($periodos)->contains(fn ($p) => (int) substr($p, 0, 4) < $this->anioMigrado)) {
                return self::PREVIO;
            }

            $cobradas = $todas->filter(fn (Cuota $q) => in_array($q->periodo->format('Y-m'), $periodos, true) && $q->estado === EstadoCuota::Pagada && ! $usadas->contains($q->id));
            $esperado = $cobradas->sum(fn (Cuota $q) => (float) $q->importe_cobrado);
            $periodosCubiertos = $cobradas->map(fn (Cuota $q) => $q->periodo->format('Y-m'))->unique();

            if ($cobradas->isNotEmpty() && $periodosCubiertos->count() === count($periodos) && abs($esperado - (float) $mov->importe) < 0.01) {
                $this->crearPago($mov, $socio, $cobradas);
                $usadas->push(...$cobradas->pluck('id')->all());

                return true;
            }

            // Pago adelantado: los meses sin cuota todavía figuran en la grilla; lo que cubren queda como saldo a favor.
            $faltantes = array_values(array_diff($periodos, $periodosCubiertos->all()));
            $adelantado = collect($faltantes)->map(fn ($p) => $this->futuros[$socio->id][$p] ?? null);
            if ($cobradas->isNotEmpty() && $faltantes && $adelantado->every(fn ($v) => $v !== null) && abs($esperado + $adelantado->sum() - (float) $mov->importe) < 0.01) {
                $this->crearPago($mov, $socio, $cobradas);
                $usadas->push(...$cobradas->pluck('id')->all());
                $this->advertencias[] = sprintf('%s: pago adelantado de $%.2f; $%.2f quedaron como saldo a favor para %s.', $socio->nombre_completo, (float) $mov->importe, $adelantado->sum(), implode(', ', $faltantes));

                return true;
            }
            if ($cobradas->isNotEmpty()) {
                $motivo = sprintf("'%s' (%.2f): las cuotas cobradas de %s suman %.2f y el ingreso es %.2f.", $socio->nombre_completo, $c['score'], implode(', ', $periodos), $esperado, (float) $mov->importe);
            }
        }

        return $motivo;
    }

    /** "Parte cuota Jose Pereyra": pago parcial a la cuota pendiente más antigua del socio. */
    private function vincularParcial(Movimiento $mov, Socio $socio): true|string
    {
        $propuesta = $this->imputador->proponer($socio, (string) $mov->importe, $mov->fecha);
        if (! $propuesta['imputaciones']) {
            return "'{$socio->nombre_completo}' no tiene cuotas pendientes para imputar el pago parcial.";
        }

        $pago = Pago::create([
            'socio_id' => $socio->id, 'fecha' => $mov->fecha->toDateString(), 'importe_bruto' => $mov->importe, 'medio_pago_id' => $mov->medio_pago_id,
            'cuenta_id' => $mov->cuenta_id, 'estado' => EstadoPago::Confirmado, 'origen' => OrigenPago::Importacion, 'concepto' => $mov->concepto, 'confirmado_at' => now(),
        ]);
        $this->imputador->aplicar($pago, $propuesta['imputaciones']);
        $this->enlazar($mov, $pago);
        $this->advertencias[] = "Pago parcial de \${$mov->importe} imputado a la cuota más antigua pendiente de {$socio->nombre_completo}: confirmalo.";

        return true;
    }

    /** @param Collection<int, Cuota> $cuotas */
    private function crearPago(Movimiento $mov, Socio $socio, Collection $cuotas): void
    {
        $pago = Pago::create([
            'socio_id' => $socio->id, 'fecha' => $mov->fecha->toDateString(), 'importe_bruto' => $mov->importe, 'medio_pago_id' => $mov->medio_pago_id,
            'cuenta_id' => $mov->cuenta_id, 'estado' => EstadoPago::Confirmado, 'origen' => OrigenPago::Importacion, 'concepto' => $mov->concepto, 'confirmado_at' => now(),
        ]);

        foreach ($cuotas as $cuota) {
            PagoCuota::create(['pago_id' => $pago->id, 'cuota_id' => $cuota->id, 'importe_imputado' => $cuota->importe_cobrado]);
            $cuota->update(['fecha_cancelacion' => $mov->fecha->toDateString()]);
        }

        $this->enlazar($mov, $pago);
    }

    private function enlazar(Movimiento $mov, Pago $pago): void
    {
        Movimiento::withoutEvents(function () use ($mov, $pago) {
            $mov->update(['origenable_type' => $pago->getMorphClass(), 'origenable_id' => $pago->id]);
            $mov->asiento?->update(['tipo' => TipoAsiento::CobroCuota]);
        });
    }

    /**
     * "Etchelecu Martín noviembre, diciembre, enero" → ['etchelecu martin', [[11,null],[12,null],[1,null]], false].
     * Entiende abreviaturas ("dic"), años ("dic 2025"), rangos ("enero a junio") y "y".
     *
     * @return array{0: string, 1: list<array{0: int, 1: int|null}>, 2: bool}
     */
    private function interpretar(string $concepto): array
    {
        $texto = mb_strtolower(strtr($concepto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u']));
        // Solo "Parte cuota <socio>" al comienzo: frases como "Jose Pereyra pago parte cuota Kelo" son de otra persona y se revisan a mano.
        $parcial = (bool) preg_match('/^\s*parte\s+(de\s+)?cuota/', $texto);
        $texto = preg_replace('/\(.*?\)|^\s*parte\s+(de\s+)?cuota/', ' ', $texto);

        preg_match_all('/\b('.self::MES_REGEX.')\b(?:\s+(?:del\s+)?(20\d\d|2\d)\b)?/', $texto, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $tokens = [];
        foreach ($m as $hit) {
            $tokens[] = [
                'mes' => $this->mesNumero($hit[1][0]),
                'anio' => isset($hit[2]) && $hit[2][1] >= 0 ? (int) (strlen($hit[2][0]) === 2 ? '20'.$hit[2][0] : $hit[2][0]) : null,
                'ini' => $hit[0][1],
                'fin' => $hit[0][1] + strlen($hit[0][0]),
            ];
        }

        $meses = [];
        foreach ($tokens as $i => $t) {
            $anterior = $tokens[$i - 1] ?? null;
            $entre = $anterior ? trim(substr($texto, $anterior['fin'], $t['ini'] - $anterior['fin'])) : null;

            if ($anterior && $entre === 'a') {
                // Rango: se completan los meses intermedios (cruzando de año si hace falta).
                $anio = $anterior['anio'];
                for ($mes = $anterior['mes'] % 12 + 1, $a = $anio; ; $mes = $mes % 12 + 1) {
                    if ($mes === 1 && $a !== null) {
                        $a++;
                    }
                    if ($mes === $t['mes']) {
                        break;
                    }
                    $meses[] = [$mes, $a];
                    if (count($meses) > 24) {
                        break;
                    }
                }
            }
            $meses[] = [$t['mes'], $t['anio']];
        }

        // El nombre es lo que queda al sacar los meses, los años y las palabras de enlace.
        $nombre = $texto;
        foreach (array_reverse($tokens) as $t) {
            $nombre = substr_replace($nombre, ' ', $t['ini'], $t['fin'] - $t['ini']);
        }
        $nombre = preg_replace('/\b(a|y|del|de|pago|parte|cuota|dic|del)\b/', ' ', $nombre);
        $nombre = trim(preg_replace('/[^a-z ñ]+|\s+/u', ' ', $nombre));

        return [$nombre, $meses, $parcial];
    }

    private function mesNumero(string $token): int
    {
        foreach (self::MESES as $nombre => $n) {
            if (str_starts_with($nombre, substr($token, 0, 3))) {
                return $n;
            }
        }

        return 1;
    }
}
