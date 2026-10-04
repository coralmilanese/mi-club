<?php

namespace App\Services\Importacion;

use App\Models\Socio;
use App\Services\Socios\BuscadorPorNombre;
use Carbon\CarbonImmutable;

/**
 * Cruza la grilla de cuotas del Excel con el padrón y deduce el plan de cada socio a partir de lo que
 * efectivamente paga (la columna PLANEADORES-AASR de la hoja Socios no es confiable). Todo son SUGERENCIAS
 * para que el tesorero revise antes de asignar: nada se escribe en la base.
 */
class SugeridorPlanes
{
    private const UMBRAL_CONFIABLE = 0.6;

    /** Importes históricos de cuota base (titular) y de adicional familiar, para detectar grupos. */
    private const BASES = [30000, 35000];

    private const ADICIONALES = [21500, 22500];

    public function __construct(private BuscadorPorNombre $buscador, private LectorGrillaCuotas $lector, private EmparejadorGrilla $emparejador) {}

    /**
     * @return array{socios: list<array<string, mixed>>, sin_socio: list<array<string, mixed>>}
     */
    public function sugerir(string $archivo, int $anio = 2026): array
    {
        $grilla = $this->lector->leer($archivo);
        $asignacion = $this->emparejador->emparejar($grilla);

        $socios = [];
        foreach (Socio::vigentes()->orderBy('apellido')->orderBy('nombre')->get() as $socio) {
            $fila = $asignacion['por_socio'][$socio->id] ?? null;
            $socios[] = $this->analizar($socio, $fila, $grilla, $asignacion, $anio);
        }

        $sinSocio = [];
        foreach ($grilla as $idx => $fila) {
            $m = $asignacion['por_fila'][$idx] ?? null;
            $emparejada = $m !== null && $m['socio']->estado->esVigente() && ($asignacion['por_socio'][$m['socio']->id] ?? null) === $idx;
            if (! $emparejada) {
                $candidatos = $this->buscador->candidatos($fila['nombre'], 3, 0.2)
                    ->map(fn ($c) => "{$c['socio']->nombre_completo} [{$c['socio']->estado->label()}] ({$c['score']})")->join(' | ');
                $sinSocio[] = [
                    'fila_cuotas' => $fila['fila'],
                    'nombre_en_cuotas' => $fila['nombre'],
                    'candidatos' => $candidatos,
                    'resumen' => $this->resumen($fila, $anio),
                ];
            }
        }

        return ['socios' => $socios, 'sin_socio' => $sinSocio];
    }

    /**
     * @param  list<array<string, mixed>>  $grilla
     * @param  array{por_fila: array<int, array{socio: Socio, score: float}>, por_socio: array<int, int>}  $asignacion
     * @return array<string, mixed>
     */
    private function analizar(Socio $socio, ?int $idx, array $grilla, array $asignacion, int $anio): array
    {
        $alertas = [];
        $fila = $idx !== null ? $grilla[$idx] : null;
        $score = $idx !== null ? $asignacion['por_fila'][$idx]['score'] : null;
        $sede = $socio->sede->value;

        $pagos = $fila ? $this->pagos($fila) : [];
        $ultimo = $pagos ? (int) end($pagos) : null;
        $familiares = $fila ? count(array_filter($fila['meses'], fn ($c) => ($c['tipo'] ?? null) === 'familiar')) : 0;

        // Categoría del padrón manda para honorarios y vitalicios; el resto se deduce de lo que paga.
        $plan = null;
        $confianza = $fila ? ($score >= self::UMBRAL_CONFIABLE ? 'alta' : 'baja') : 'baja';
        $titularId = $titularNombre = '';

        if ($socio->categoria->value === 'honorario') {
            $plan = 'honorario';
            $confianza = 'alta';
        } elseif ($socio->categoria->value === 'vitalicio') {
            $plan = 'vitalicio';
            $confianza = 'alta';
        } elseif ($fila && $familiares > 0 && ! $pagos) {
            $plan = 'adicional_familiar';
            [$titularId, $titularNombre] = $this->titularDe($idx, $grilla, $asignacion);
            if ($titularId === '') {
                $alertas[] = 'Figura como "Familiar" pero no pude determinar el titular: asignalo a mano.';
            }
        } elseif ($ultimo !== null) {
            if (in_array($ultimo, [16000, 17000], true)) {
                $plan = 'planeadores';
            } elseif (in_array($ultimo, [21500, 22500, 17500], true)) {
                $plan = 'vitalicio';
                $alertas[] = 'Paga un importe de vitalicio pero su categoría en Socios no lo es.';
            } elseif ($ultimo >= 30000) {
                $plan = 'aasr';
                if ($k = $this->adherentesImplicitos((int) $ultimo)) {
                    $alertas[] = "Paga \${$ultimo}: cuota propia + {$k} adicional(es) familiar(es); revisá que el grupo tenga {$k} adherente(s).";
                }
            }
        }

        if ($plan === null) {
            $plan = $sede === 'planeadores' ? 'planeadores' : 'aasr';
            $confianza = 'baja';
            $alertas[] = $fila ? 'La grilla no muestra pagos que permitan deducir el plan: se propone por la sede de Socios.' : 'No aparece en la grilla de cuotas: se propone por la sede de Socios.';
        }

        if ($fila && $score < self::UMBRAL_CONFIABLE) {
            $alertas[] = sprintf('REVISAR EMPAREJAMIENTO: "%s" se parece poco (%.2f). Confirmá que es la misma persona.', $fila['nombre'], $score);
        }
        if ($plan === 'aasr' && $sede === 'planeadores') {
            $alertas[] = 'Socios lo marca PLANEADORES pero paga cuota AASR completa.';
        } elseif ($plan === 'planeadores' && $sede === 'aasr') {
            $alertas[] = 'Socios lo marca AASR pero paga cuota de Planeadores.';
        }

        $desde = $hasta = '';
        if ($fila) {
            [$desde, $hasta, $bajaMes] = $this->vigencia($fila, $anio);
            if ($bajaMes) {
                $alertas[] = "La grilla lo marca BAJA desde {$bajaMes} pero Socios lo lista como vigente: decidí si corresponde darlo de baja.";
            }
        }

        // Sin confianza suficiente el plan_final queda vacío: hay que decidirlo explícitamente.
        $requiereDecision = $confianza !== 'alta';

        return [
            'socio_id' => $socio->id,
            'socio' => $socio->nombre_completo,
            'categoria' => $socio->categoria->label(),
            'sede_excel' => $socio->sede->label(),
            'fila_cuotas' => $fila['fila'] ?? '',
            'nombre_en_cuotas' => $fila['nombre'] ?? '',
            'similitud' => $score ?? '',
            'pagos' => $fila ? $this->resumen($fila, $anio) : '',
            'ultimo_importe' => $ultimo ?? '',
            'plan_sugerido' => $plan,
            'confianza' => $confianza,
            'alerta' => implode(' | ', $alertas),
            'plan_final' => $requiereDecision ? '' : $plan,
            'desde' => $desde,
            'hasta' => $hasta,
            'titular_grupo_id' => $titularId,
            'titular_grupo' => $titularNombre,
        ];
    }

    /** @return list<float> */
    private function pagos(array $fila): array
    {
        $p = [];
        foreach ($fila['meses'] as $c) {
            if (($c['tipo'] ?? null) === 'pago' && $c['importe'] > 0) {
                $p[] = $c['importe'];
            }
        }

        return $p;
    }

    /** "Cuotas: 30.000×6, 35.000×1" */
    private function resumen(array $fila, int $anio): string
    {
        $cuenta = [];
        foreach ($fila['meses'] as $c) {
            if (! $c) {
                continue;
            }
            $clave = match ($c['tipo']) {
                'pago' => number_format($c['importe'], 0, ',', '.'),
                default => $c['tipo'],
            };
            $cuenta[$clave] = ($cuenta[$clave] ?? 0) + 1;
        }

        return implode(', ', array_map(fn ($k, $n) => "{$k}×{$n}", array_keys($cuenta), $cuenta)) ?: '(sin datos)';
    }

    /** @return array{0: string, 1: string, 2: string|null} desde (YYYY-MM-DD), hasta, mes en que la grilla marca BAJA */
    private function vigencia(array $fila, int $anio): array
    {
        $primero = null;
        foreach ($fila['meses'] as $mes => $c) {
            if ($c && $c['tipo'] !== 'baja') {
                $primero = $mes;
                break;
            }
        }
        $desde = $primero ? CarbonImmutable::create($anio, $primero, 1)->toDateString() : '';

        $bajaDesde = null;
        foreach ($fila['meses'] as $mes => $c) {
            if (($c['tipo'] ?? null) === 'baja') {
                $bajaDesde = $mes;
                break;
            }
        }
        $hasta = $bajaDesde && $bajaDesde > 1 ? CarbonImmutable::create($anio, $bajaDesde, 1)->subDay()->toDateString() : '';

        $nombreMes = $bajaDesde ? CarbonImmutable::create($anio, $bajaDesde, 1)->locale('es')->translatedFormat('F') : null;

        return [$desde, $hasta, $nombreMes];
    }

    /** Titular = socio de la fila anterior más cercana que no sea "Familiar" (en la grilla los adherentes van debajo del titular). */
    private function titularDe(int $idx, array $grilla, array $asignacion): array
    {
        for ($i = $idx - 1; $i >= 0; $i--) {
            $esFamiliar = collect($grilla[$i]['meses'])->contains(fn ($c) => ($c['tipo'] ?? null) === 'familiar');
            if ($esFamiliar) {
                continue;
            }
            $m = $asignacion['por_fila'][$i] ?? null;

            return $m && $m['score'] >= self::UMBRAL_CONFIABLE ? [$m['socio']->id, $m['socio']->nombre_completo] : ['', ''];
        }

        return ['', ''];
    }

    /** Si el importe es base + k × adicional (con k ≥ 1) devuelve k. */
    private function adherentesImplicitos(int $importe): int
    {
        foreach (self::BASES as $base) {
            foreach (self::ADICIONALES as $adicional) {
                $resto = $importe - $base;
                if ($resto > 0 && $resto % $adicional === 0) {
                    return intdiv($resto, $adicional);
                }
            }
        }

        return 0;
    }
}
