<?php

use App\Enums\CategoriaSocio;
use App\Enums\SedeSocio;
use App\Models\GrupoFamiliar;
use App\Models\Plan;
use App\Models\Socio;
use App\Services\Importacion\AsignadorPlanesDesdeCsv;
use App\Services\Importacion\SugeridorPlanes;
use App\Services\Socios\BuscadorPorNombre;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function excelCuotas(array $filas): string
{
    $wb = new Spreadsheet;
    $wb->getActiveSheet()->setTitle(' Cuotas'); // el nombre real de la hoja lleva un espacio adelante
    $ws = $wb->getActiveSheet();
    $ws->fromArray(['NRO', 'SOCIO ', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'], null, 'A1');
    foreach ($filas as $i => [$nombre, $meses]) {
        $ws->fromArray([$i + 1, $nombre, ...array_pad($meses, 12, null)], null, 'A'.($i + 2));
    }
    $path = tempnam(sys_get_temp_dir(), 'cuotas').'.xlsx';
    (new Xlsx($wb))->save($path);

    return $path;
}

beforeEach(fn () => $this->seed(PlanSeeder::class));

test('el buscador tolera tildes, orden invertido y errores de tipeo', function () {
    Socio::factory()->create(['apellido' => 'Alfajeme', 'nombre' => 'Matías']);
    Socio::factory()->create(['apellido' => 'Ramirez', 'nombre' => 'Roberto Mario']);
    Socio::factory()->create(['apellido' => 'Lopez', 'nombre' => 'Valeria']);
    $b = app(BuscadorPorNombre::class);

    expect($b->candidatos('ALFAGEME MATIAS')->first()['socio']->apellido)->toBe('Alfajeme')
        ->and($b->candidatos('ROBERTO MARIO RAMIREZ')->first()['score'])->toBe(1.0)
        ->and($b->candidatos('valeria lopez')->first()['socio']->nombre)->toBe('Valeria')
        ->and($b->candidatos('Zzzz Qqqq'))->toBeEmpty()
        ->and($b->candidatos('   '))->toBeEmpty();
});

test('deduce el plan por lo que paga, detecta grupos familiares y conflictos con la columna de sede', function () {
    $gelos = Socio::factory()->create(['apellido' => 'Gelos', 'nombre' => 'Gabriel', 'sede' => SedeSocio::Planeadores]);
    $alfajeme = Socio::factory()->create(['apellido' => 'Alfajeme', 'nombre' => 'Matias', 'sede' => SedeSocio::Planeadores]);
    $cepeda = Socio::factory()->create(['apellido' => 'Cepeda', 'nombre' => 'Agustin']);
    $milo = Socio::factory()->create(['apellido' => 'Cepeda', 'nombre' => 'Milo']);
    $ripa = Socio::factory()->categoria(CategoriaSocio::Honorario)->create(['apellido' => 'Ripa', 'nombre' => 'Miguel Angel']);
    $branda = Socio::factory()->create(['apellido' => 'Branda', 'nombre' => 'Ernesto']);

    $archivo = excelCuotas([
        ['GELOS GABRIEL', [30000, 30000, 30000, 30000, 30000, 30000, 35000]],
        ['ALFAGEME MATIAS', [16000, 16000, 16000, 16000, 17000, 17000]],
        ['CEPEDA AGUSTIN', [51500, 51500, 51500, 57500, 57500, 57500]],
        ['CEPEDA MILO', ['Familiar', 'Familiar', 'Familiar', 'Familiar', 'Familiar', 'Familiar']],
        ['BRANDA ERNESTO', [30000, 30000, 30000, 'deuda', 'deuda', 'deuda', 'BAJA', 'BAJA']],
        ['BILBAO GUSTAVO', [null, null, null, 16000, 16000]],
    ]);

    $r = app(SugeridorPlanes::class)->sugerir($archivo);
    $por = collect($r['socios'])->keyBy('socio_id');

    expect($por[$gelos->id]['plan_sugerido'])->toBe('aasr')
        ->and($por[$gelos->id]['alerta'])->toContain('PLANEADORES pero paga cuota AASR')
        ->and($por[$alfajeme->id]['plan_sugerido'])->toBe('planeadores')
        ->and($por[$cepeda->id]['alerta'])->toContain('1 adicional')
        ->and($por[$milo->id]['plan_sugerido'])->toBe('adicional_familiar')
        ->and($por[$milo->id]['titular_grupo_id'])->toBe($cepeda->id)
        ->and($por[$ripa->id]['plan_sugerido'])->toBe('honorario')
        ->and($por[$branda->id]['desde'])->toBe('2026-01-01')
        ->and($por[$branda->id]['hasta'])->toBe('2026-06-30')
        ->and($por[$branda->id]['alerta'])->toContain('BAJA desde julio');

    // Bilbao no está en el padrón: queda en la lista de filas sin socio.
    expect(collect($r['sin_socio'])->pluck('nombre_en_cuotas')->all())->toBe(['BILBAO GUSTAVO']);
});

test('sin datos suficientes el plan_final queda vacío para forzar la decisión', function () {
    $sinDatos = Socio::factory()->create(['apellido' => 'Diaz', 'nombre' => 'Diego']);

    $r = app(SugeridorPlanes::class)->sugerir(excelCuotas([['OTRO PERSONAJE', [30000]]]));
    $fila = collect($r['socios'])->firstWhere('socio_id', $sinDatos->id);

    expect($fila['plan_sugerido'])->toBe('aasr')->and($fila['confianza'])->toBe('baja')->and($fila['plan_final'])->toBe('');
});

function csvRevision(array $filas): string
{
    $path = tempnam(sys_get_temp_dir(), 'rev').'.csv';
    $f = fopen($path, 'w');
    fputcsv($f, ['socio_id', 'socio', 'plan_final', 'desde', 'hasta', 'titular_grupo_id'], ',', '"', '');
    foreach ($filas as $fila) {
        fputcsv($f, $fila, ',', '"', '');
    }
    fclose($f);

    return $path;
}

test('la planilla revisada asigna planes, respeta desde/hasta y arma el grupo familiar', function () {
    [$titular, $adherente, $ex, $pendiente] = Socio::factory()->count(4)->create();

    $csv = csvRevision([
        [$titular->id, 'Titular', 'aasr', '2026-01-01', '', ''],
        [$adherente->id, 'Adherente', 'adicional_familiar', '2026-01-01', '', $titular->id],
        [$ex->id, 'Ex', 'aasr', '2026-01-01', '2026-06-30', ''],
        [$pendiente->id, 'Pendiente', '', '', '', ''],
    ]);

    $r = app(AsignadorPlanesDesdeCsv::class)->aplicar($csv, CarbonImmutable::parse('2026-01-01'));

    expect($r)->toMatchArray(['asignados' => 3, 'salteados' => 1, 'grupos' => 1, 'errores' => []]);
    expect($ex->planes()->first()->hasta->toDateString())->toBe('2026-06-30')
        ->and($pendiente->planes()->count())->toBe(0);

    $grupo = GrupoFamiliar::firstOrFail();
    expect($grupo->titular_socio_id)->toBe($titular->id)->and($adherente->fresh()->grupo_familiar_id)->toBe($grupo->id);

    // Re-correr la misma planilla no duplica nada.
    $segunda = app(AsignadorPlanesDesdeCsv::class)->aplicar($csv, CarbonImmutable::parse('2026-01-01'));
    expect($segunda['asignados'])->toBe(0)->and($segunda['sin_cambios'])->toBe(3)->and(GrupoFamiliar::count())->toBe(1);
});

test('un error en la planilla no aplica nada y el dry-run tampoco escribe', function () {
    [$a, $b] = Socio::factory()->count(2)->create();

    $conError = csvRevision([[$a->id, 'A', 'aasr', '', '', ''], [$b->id, 'B', 'plan_inventado', '', '', '']]);
    $r = app(AsignadorPlanesDesdeCsv::class)->aplicar($conError, CarbonImmutable::parse('2026-01-01'));
    expect($r['errores'])->toHaveCount(1)->and($a->planes()->count())->toBe(0);

    $ok = csvRevision([[$a->id, 'A', 'aasr', '', '', '']]);
    app(AsignadorPlanesDesdeCsv::class)->aplicar($ok, CarbonImmutable::parse('2026-01-01'), dryRun: true);
    expect($a->planes()->count())->toBe(0);

    expect(Plan::count())->toBe(5);
});
