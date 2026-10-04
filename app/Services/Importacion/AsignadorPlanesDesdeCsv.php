<?php

namespace App\Services\Importacion;

use App\Actions\Planes\AsignarPlanSocio;
use App\Models\GrupoFamiliar;
use App\Models\Plan;
use App\Models\Socio;
use App\Models\SocioPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aplica la planilla de revisión ya corregida por el tesorero (`planes:sugerir`): asigna el plan,
 * respeta `desde`/`hasta` y arma los grupos familiares. Las filas con plan_final vacío se saltean.
 */
class AsignadorPlanesDesdeCsv
{
    public function __construct(private AsignarPlanSocio $asignar) {}

    /**
     * @return array{asignados: int, sin_cambios: int, salteados: int, errores: list<string>, grupos: int}
     */
    public function aplicar(string $csv, CarbonImmutable $desdePorDefecto, bool $dryRun = false): array
    {
        $filas = $this->leer($csv);
        $r = ['asignados' => 0, 'sin_cambios' => 0, 'salteados' => 0, 'errores' => [], 'grupos' => 0];
        $planes = Plan::pluck('id', 'tipo_plan');

        DB::beginTransaction();
        try {
            foreach ($filas as $n => $f) {
                $rotulo = 'Línea '.($n + 2)." ({$f['socio']})";
                $tipo = trim($f['plan_final']);
                if ($tipo === '') {
                    $r['salteados']++;

                    continue;
                }
                if (! isset($planes[$tipo])) {
                    $r['errores'][] = "{$rotulo}: el plan '{$tipo}' no existe.";

                    continue;
                }
                $socio = Socio::find((int) $f['socio_id']);
                if (! $socio) {
                    $r['errores'][] = "{$rotulo}: no existe el socio {$f['socio_id']}.";

                    continue;
                }

                try {
                    $desde = $f['desde'] !== '' ? CarbonImmutable::parse($f['desde']) : $desdePorDefecto;
                    $actual = SocioPlan::where('socio_id', $socio->id)->whereNull('hasta')->first();
                    // Idempotencia: mismo plan vigente, o ya cargado el mismo tramo (aunque esté cerrado con `hasta`).
                    $yaCargado = ($actual && $actual->plan_id === $planes[$tipo])
                        || SocioPlan::where('socio_id', $socio->id)->where('plan_id', $planes[$tipo])->whereDate('desde', $desde->toDateString())->exists();

                    if ($yaCargado) {
                        $r['sin_cambios']++;
                    } else {
                        $asignacion = ($this->asignar)($socio, Plan::findOrFail($planes[$tipo]), $desde, 'Asignado desde planilla de revisión');
                        if ($f['hasta'] !== '') {
                            $asignacion->update(['hasta' => CarbonImmutable::parse($f['hasta'])->toDateString()]);
                        }
                        $r['asignados']++;
                    }

                    if ($f['titular_grupo_id'] !== '') {
                        $r['grupos'] += $this->agrupar($socio, (int) $f['titular_grupo_id']);
                    }
                } catch (ValidationException $e) {
                    $r['errores'][] = "{$rotulo}: ".collect($e->errors())->flatten()->join(' ');
                }
            }

            // Si hubo errores no se aplica nada: la planilla se corrige y se vuelve a correr.
            ($dryRun || $r['errores']) ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $r;
    }

    /** @return int grupos creados (0 ó 1) */
    private function agrupar(Socio $adherente, int $titularId): int
    {
        $titular = Socio::findOrFail($titularId);
        $creado = 0;

        if (! $titular->grupo_familiar_id) {
            $grupo = GrupoFamiliar::create(['nombre' => "Familia {$titular->apellido}", 'titular_socio_id' => $titular->id]);
            $titular->update(['grupo_familiar_id' => $grupo->id]);
            $creado = 1;
        }

        if ($adherente->id !== $titular->id && $adherente->grupo_familiar_id !== $titular->grupo_familiar_id) {
            $adherente->update(['grupo_familiar_id' => $titular->grupo_familiar_id]);
        }

        return $creado;
    }

    /** @return list<array<string, string>> */
    private function leer(string $csv): array
    {
        $f = fopen($csv, 'r');
        $cabecera = fgetcsv($f, 0, ',', '"', '');
        if ($cabecera === false) {
            throw new \RuntimeException('El CSV está vacío.');
        }
        $cabecera[0] = ltrim($cabecera[0], "\xEF\xBB\xBF");

        foreach (['socio_id', 'socio', 'plan_final', 'desde', 'hasta', 'titular_grupo_id'] as $col) {
            if (! in_array($col, $cabecera, true)) {
                throw new \RuntimeException("Falta la columna '{$col}' en el CSV.");
            }
        }

        $filas = [];
        while (($linea = fgetcsv($f, 0, ',', '"', '')) !== false) {
            if ($linea === [null]) {
                continue;
            }
            $filas[] = array_combine($cabecera, array_pad($linea, count($cabecera), ''));
        }
        fclose($f);

        return $filas;
    }
}
