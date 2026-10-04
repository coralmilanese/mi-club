<?php

namespace App\Services\Importacion;

use App\Actions\Cuotas\DevengarCuotas;
use App\Actions\Planes\AsignarPlanSocio;
use App\Actions\Socios\AltaSocio;
use App\Actions\Socios\DarDeBajaSocio;
use App\Enums\CategoriaSocio;
use App\Enums\EstadoSocio;
use App\Enums\SedeSocio;
use App\Enums\TipoAlias;
use App\Models\Plan;
use App\Models\Socio;
use App\Models\SocioAlias;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta como socios a quienes pagan en la grilla de cuotas pero no figuran en la hoja Socios, y registra los apodos
 * con que aparecen en el libro ("Kelo Nagore", "Dani Rosso") para que los pagos se puedan vincular. Los datos personales
 * quedan vacíos: el tesorero los completa desde la ficha.
 */
class CompletadorSociosDesdeGrilla
{
    /** Apodos que el libro usa para socios que ya están en el padrón: [apellido, nombre, [apodos]]. */
    private const ALIAS_EXISTENTES = [
        ['Rosso', 'Daniel', ['Esteban Fabian Rosso', 'Rosso Esteban Fabian', 'Fabian Rosso', 'Dani Rosso']],
        ['Nagore', 'Carmelo', ['Kelo Nagore']],
        ['Pessio', 'Alberto', ['Tito Pessio']],
        ['Alfajeme', 'Matias', ['Mati Alfageme', 'Alfageme Matias']],
        ['Dadone', 'Francisco', ['Fran Dadone']],
        ['Lopez', 'Valeria', ['Vale Lopez']],
    ];

    /** La grilla escribe algunos nombres como "NOMBRE APELLIDO": ahí no se puede adivinar cuál es el apellido. */
    private const APELLIDO_NOMBRE = [
        'bernardo jose folco' => ['Folco', 'Bernardo José'],
    ];

    /** Apodos de los socios nuevos, por nombre en la grilla. */
    private const ALIAS_NUEVOS = [
        'rainhart alejandro javier' => ['Ale Reinhard', 'Ale Rainhart', 'Alejandro Reinhard'],
    ];

    public function __construct(
        private LectorGrillaCuotas $lector,
        private EmparejadorGrilla $emparejador,
        private AltaSocio $alta,
        private AsignarPlanSocio $asignarPlan,
        private DarDeBajaSocio $darDeBaja,
        private DevengarCuotas $devengar,
    ) {}

    /** @return array{aliases: int, creados: list<string>, cuotas_devengadas: int} */
    public function completar(string $archivo, int $anio = 2026, bool $dryRun = false): array
    {
        DB::beginTransaction();
        try {
            $aliases = $this->aliasesExistentes();

            $grilla = $this->lector->leer($archivo);
            $asignacion = $this->emparejador->emparejar($grilla);

            $creados = [];
            foreach ($grilla as $idx => $fila) {
                $pagos = array_values(array_filter($fila['meses'], fn ($c) => ($c['tipo'] ?? null) === 'pago' && $c['importe'] > 0));
                if (! $pagos || $this->emparejador->esConfiable($asignacion, $idx)) {
                    continue;
                }
                $creados[] = $this->crear($fila, (float) end($pagos)['importe'], $anio);
            }
            $aliases += $this->aliasesNuevos($grilla, $creados);

            $devengadas = 0;
            $hasta = min(CarbonImmutable::now()->startOfMonth(), CarbonImmutable::create($anio, 12, 1));
            for ($m = CarbonImmutable::create($anio, 1, 1); $m->lte($hasta); $m = $m->addMonth()) {
                $devengadas += ($this->devengar)($m)['creadas'];
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return ['aliases' => $aliases, 'creados' => array_map(fn ($c) => $c['descripcion'], $creados), 'cuotas_devengadas' => $devengadas];
    }

    /** @param array{fila: int, nombre: string, meses: array<int, array<string, mixed>|null>} $fila */
    private function crear(array $fila, float $ultimoImporte, int $anio): array
    {
        [$apellido, $nombre] = $this->separarNombre($fila['nombre']);

        $primero = collect($fila['meses'])->search(fn ($c) => $c && ($c['tipo'] ?? null) !== 'baja');
        $bajaMes = collect($fila['meses'])->search(fn ($c) => ($c['tipo'] ?? null) === 'baja');
        $desde = CarbonImmutable::create($anio, $primero ?: 1, 1);

        $planTipo = $ultimoImporte <= 17000 ? 'planeadores' : 'aasr';
        $plan = Plan::where('tipo_plan', $planTipo)->firstOrFail();

        $socio = ($this->alta)([
            'apellido' => $apellido,
            'nombre' => $nombre,
            'categoria' => CategoriaSocio::Activo,
            'sede' => $planTipo === 'planeadores' ? SedeSocio::Planeadores : SedeSocio::Aasr,
            'estado' => EstadoSocio::Activo,
            'desde_estado' => $desde->toDateString(),
            'observaciones' => 'Dado de alta desde la planilla Cuotas del Excel: pagaba pero no figuraba en la hoja Socios. Completar los datos personales.',
        ]);
        ($this->asignarPlan)($socio, $plan, $desde, 'Deducido de los importes de la planilla Cuotas');

        $descripcion = "{$socio->nombre_completo} → {$plan->nombre} desde {$desde->format('m/Y')}";
        if ($bajaMes) {
            $fechaBaja = CarbonImmutable::create($anio, (int) $bajaMes, 1);
            ($this->darDeBaja)($socio, $fechaBaja, "La planilla Cuotas lo marca BAJA desde {$fechaBaja->locale('es')->translatedFormat('F')}.");
            $descripcion .= " · baja desde {$fechaBaja->format('m/Y')}";
        }

        return ['socio' => $socio, 'clave' => $this->clave($fila['nombre']), 'descripcion' => $descripcion];
    }

    /** @return array{0: string, 1: string} [apellido, nombre] */
    private function separarNombre(string $nombreGrilla): array
    {
        if ($conocido = self::APELLIDO_NOMBRE[$this->clave($nombreGrilla)] ?? null) {
            return $conocido;
        }

        $partes = preg_split('/\s+/', trim($nombreGrilla));
        $titulo = fn (string $t) => mb_convert_case(mb_strtolower($t), MB_CASE_TITLE);

        // Convención de la grilla: "APELLIDO Nombre(s)".
        return [$titulo(array_shift($partes)), $titulo(implode(' ', $partes))];
    }

    private function aliasesExistentes(): int
    {
        $n = 0;
        foreach (self::ALIAS_EXISTENTES as [$apellido, $nombre, $apodos]) {
            $socio = Socio::where('apellido', $apellido)->where('nombre', 'like', "{$nombre}%")->first();
            if ($socio) {
                $n += $this->registrarAliases($socio, $apodos);
            }
        }

        return $n;
    }

    /** @param list<array{socio: Socio, clave: string, descripcion: string}> $creados */
    private function aliasesNuevos(array $grilla, array $creados): int
    {
        $n = 0;
        foreach ($creados as $c) {
            $n += $this->registrarAliases($c['socio'], self::ALIAS_NUEVOS[$c['clave']] ?? []);
        }

        return $n;
    }

    /** @param list<string> $apodos */
    private function registrarAliases(Socio $socio, array $apodos): int
    {
        $n = 0;
        foreach ($apodos as $apodo) {
            $alias = SocioAlias::firstOrCreate(
                ['tipo' => TipoAlias::Apodo->value, 'valor' => $apodo],
                ['socio_id' => $socio->id, 'verificado_at' => now()],
            );
            $n += $alias->wasRecentlyCreated ? 1 : 0;
        }

        return $n;
    }

    private function clave(string $t): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $t)));
    }
}
