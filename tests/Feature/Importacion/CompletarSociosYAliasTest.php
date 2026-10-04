<?php

use App\Actions\Cuotas\DevengarCuotas;
use App\Actions\Planes\AsignarPlanSocio;
use App\Enums\EstadoCuota;
use App\Enums\EstadoSocio;
use App\Enums\TipoAlias;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\Socio;
use App\Models\SocioAlias;
use App\Services\Importacion\CompletadorSociosDesdeGrilla;
use App\Services\Importacion\ImportadorCuotas;
use App\Services\Importacion\ImportadorLibroCaja;
use App\Services\Pagos\ImputadorDePagos;
use App\Services\Socios\BuscadorPorNombre;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriaGastoSeeder;
use Database\Seeders\LibroDeCajaSeeder;
use Database\Seeders\PlanSeeder;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function planilla(array $libro, array $cuotas): string
{
    $wb = new Spreadsheet;
    $ws = $wb->getActiveSheet()->setTitle(' Libro de Caja');
    $ws->fromArray([null, 'CONCEPTO', 'SALDOS', 'INGRESO', 'EGRESO', 'RESTO', 'TRANSACCIÓN', 'PAGO'], null, 'A1');
    foreach ($libro as $i => $fila) {
        $fila[0] = is_string($fila[0]) ? (float) Date::PHPToExcel(new DateTime($fila[0])) : $fila[0];
        $ws->fromArray($fila, null, 'A'.($i + 2));
    }
    $wc = $wb->createSheet()->setTitle(' Cuotas');
    $wc->fromArray(['NRO', 'SOCIO ', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'], null, 'A1');
    foreach ($cuotas as $i => [$nombre, $meses]) {
        $wc->fromArray([$i + 1, $nombre, ...array_pad($meses, 12, null)], null, 'A'.($i + 2));
    }
    $path = tempnam(sys_get_temp_dir(), 'club').'.xlsx';
    (new Xlsx($wb))->save($path);

    return $path;
}

beforeEach(function () {
    $this->seed([PlanSeeder::class, LibroDeCajaSeeder::class, CategoriaGastoSeeder::class]);
    $this->travelTo('2026-09-20');
});

test('el buscador encuentra a un socio por su apodo verificado', function () {
    $nagore = Socio::factory()->create(['apellido' => 'Nagore', 'nombre' => 'Carmelo']);
    Socio::factory()->create(['apellido' => 'Otero', 'nombre' => 'Marcelo']);
    SocioAlias::create(['socio_id' => $nagore->id, 'tipo' => TipoAlias::Apodo, 'valor' => 'Kelo Nagore', 'verificado_at' => now()]);

    $r = app(BuscadorPorNombre::class)->candidatos('kelo nagore', 3);

    expect($r->first()['socio']->id)->toBe($nagore->id)->and($r->first()['score'])->toBe(1.0);
});

test('completa el padrón con quienes pagan en la grilla: alta, plan por importe, baja según la grilla y apodos', function () {
    $rosso = Socio::factory()->create(['apellido' => 'Rosso', 'nombre' => 'Daniel']);
    $archivo = planilla([[...['2026-01-01', 'Saldo Comienzo 2026', null, 1, null, null, null, 'Saldo al Comienzo']]], [
        ['ROSSO ESTEBAN FABIAN', [30000, 30000, 30000]],                        // es Rosso Daniel: no se duplica
        ['RAINHART ALEJANDRO JAVIER', [30000, 30000, 35000, 35000]],           // aasr
        ['BILBAO GUSTAVO', [null, null, null, 16000, 16000]],                   // planeadores, arranca en abril
        ['SASONE GASTON', [16000, 16000, 'deuda', 'BAJA', 'BAJA']],             // baja desde abril
        ['BERNARDO JOSE FOLCO', [30000, 30000]],                                // "NOMBRE APELLIDO": override conocido
        ['CEPEDA MILO', ['Familiar', 'Familiar']],                              // solo "Familiar": no se crea nadie
    ]);

    $r = app(CompletadorSociosDesdeGrilla::class)->completar($archivo);

    expect($r['creados'])->toHaveCount(4)->and(Socio::count())->toBe(5);

    $rainhart = Socio::where('apellido', 'Rainhart')->firstOrFail();
    expect($rainhart->nombre)->toBe('Alejandro Javier')
        ->and($rainhart->estado)->toBe(EstadoSocio::Activo)
        ->and($rainhart->observaciones)->toContain('planilla Cuotas')
        ->and($rainhart->planes()->first()->plan->tipo_plan)->toBe('aasr');

    $bilbao = Socio::where('apellido', 'Bilbao')->firstOrFail();
    expect($bilbao->planes()->first()->plan->tipo_plan)->toBe('planeadores')
        ->and($bilbao->planes()->first()->desde->toDateString())->toBe('2026-04-01')
        ->and($bilbao->sede->value)->toBe('planeadores');

    $sasone = Socio::where('apellido', 'Sasone')->firstOrFail();
    expect($sasone->estado)->toBe(EstadoSocio::Baja)
        ->and($sasone->fecha_baja->toDateString())->toBe('2026-04-01')
        ->and($sasone->planes()->first()->hasta->toDateString())->toBe('2026-03-31');

    expect(Socio::where('apellido', 'Folco')->where('nombre', 'Bernardo José')->exists())->toBeTrue();

    // Devengó sus cuotas hasta septiembre (Sasone solo hasta marzo) y el apodo de Rosso quedó registrado.
    expect(Cuota::where('socio_id', $sasone->id)->count())->toBe(3)
        ->and(Cuota::where('socio_id', $bilbao->id)->count())->toBe(6)
        ->and(SocioAlias::where('socio_id', $rosso->id)->pluck('valor')->all())->toContain('Dani Rosso', 'Esteban Fabian Rosso');
    expect(SocioAlias::where('socio_id', $rainhart->id)->pluck('valor')->all())->toContain('Ale Reinhard');
});

test('completar es idempotente y el dry-run no escribe', function () {
    $archivo = planilla([['2026-01-01', 'Saldo Comienzo 2026', null, 1, null, null, null, 'Saldo al Comienzo']], [['CATONI JAVIER', [16000, 16000]]]);

    app(CompletadorSociosDesdeGrilla::class)->completar($archivo, dryRun: true);
    expect(Socio::count())->toBe(0);

    app(CompletadorSociosDesdeGrilla::class)->completar($archivo);
    $segunda = app(CompletadorSociosDesdeGrilla::class)->completar($archivo);

    expect(Socio::count())->toBe(1)->and($segunda['creados'])->toBe([]);
});

test('el pago de "Esteban Fabian Rosso" se vincula a Rosso Daniel gracias a los apodos', function () {
    $rosso = Socio::factory()->create(['apellido' => 'Rosso', 'nombre' => 'Daniel']);
    app(AsignarPlanSocio::class)($rosso, Plan::where('tipo_plan', 'aasr')->firstOrFail(), CarbonImmutable::parse('2026-01-01'));
    for ($m = CarbonImmutable::parse('2026-01-01'); $m->format('Y-m') <= '2026-03'; $m = $m->addMonth()) {
        app(DevengarCuotas::class)($m);
    }

    $archivo = planilla([
        ['2026-01-01', 'Saldo Comienzo 2026', null, 100000, null, null, null, 'Saldo al Comienzo'],
        ['2026-03-20', 'Esteban Fabian Rosso enero, febrero', null, 60000, null, null, 'Transferencia', 'Cuota Socio'],
    ], [['ROSSO ESTEBAN FABIAN', [30000, 30000]]]);
    app(ImportadorLibroCaja::class)->importar($archivo);
    app(CompletadorSociosDesdeGrilla::class)->completar($archivo);

    $r = app(ImportadorCuotas::class)->importar($archivo);

    expect($r['filas_sin_socio'])->toBe([])->and($r['pagos_vinculados'])->toBe(1)
        ->and(Pago::firstOrFail()->socio_id)->toBe($rosso->id);
});

test('pago adelantado: lo que cubre meses aún no devengados queda como saldo a favor y se aplica al devengar', function () {
    $socio = Socio::factory()->create(['apellido' => 'Bilbao', 'nombre' => 'Gustavo']);
    app(AsignarPlanSocio::class)($socio, Plan::where('tipo_plan', 'planeadores')->firstOrFail(), CarbonImmutable::parse('2026-07-01'));
    foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $m) {
        app(DevengarCuotas::class)(CarbonImmutable::parse($m));
    }

    // Pagó julio a diciembre de una vez: 6 × 16.000 = 96.000. Octubre a diciembre todavía no se devengaron.
    $archivo = planilla([
        ['2026-01-01', 'Saldo Comienzo 2026', null, 100000, null, null, null, 'Saldo al Comienzo'],
        ['2026-07-02', 'Gustavo Bilbao julio a diciembre', null, 96000, null, null, 'Transferencia', 'Cuota Socio'],
    ], [['BILBAO GUSTAVO', [null, null, null, null, null, null, 16000, 16000, 16000, 16000, 16000, 16000]]]);
    app(ImportadorLibroCaja::class)->importar($archivo);
    $r = app(ImportadorCuotas::class)->importar($archivo);

    expect($r['pagos_vinculados'])->toBe(1)
        ->and(Cuota::where('socio_id', $socio->id)->where('estado', EstadoCuota::Pagada->value)->count())->toBe(3)
        ->and(app(ImputadorDePagos::class)->saldoAFavor($socio))->toBe('48000.00');

    // Al devengar octubre a tarifa vigente (17.000) se imputa solo el saldo a favor.
    $this->travelTo('2026-10-01');
    app(DevengarCuotas::class)(CarbonImmutable::parse('2026-10-01'));
    $octubre = Cuota::where('socio_id', $socio->id)->whereDate('periodo', '2026-10-01')->firstOrFail();

    expect($octubre->estado)->toBe(EstadoCuota::Pagada)->and(app(ImputadorDePagos::class)->saldoAFavor($socio))->toBe('31000.00');
});

test('la importación de cuotas se puede repetir sin duplicar pagos ni observaciones, y una frase "pago parte cuota Kelo" no se toma como pago parcial', function () {
    $pereyra = Socio::factory()->create(['apellido' => 'Pereyra', 'nombre' => 'Jose']);
    app(AsignarPlanSocio::class)($pereyra, Plan::where('tipo_plan', 'aasr')->firstOrFail(), CarbonImmutable::parse('2026-01-01'));
    for ($m = CarbonImmutable::parse('2026-01-01'); $m->format('Y-m') <= '2026-02'; $m = $m->addMonth()) {
        app(DevengarCuotas::class)($m);
    }
    $archivo = planilla([
        ['2026-01-01', 'Saldo Comienzo 2026', null, 100000, null, null, null, 'Saldo al Comienzo'],
        ['2026-01-20', 'Jose Pereyra enero', null, 30000, null, null, 'Transferencia', 'Cuota Socio'],
        ['2026-02-20', 'Jose Pereyra pago parte cuota Kelo', null, 2000, null, null, 'Transferencia', 'Cuota Socio'],
    ], [['PEREYRA JOSE', [30000]]]);
    app(ImportadorLibroCaja::class)->importar($archivo);

    $primera = app(ImportadorCuotas::class)->importar($archivo);
    $segunda = app(ImportadorCuotas::class)->importar($archivo);

    expect($primera['pagos_vinculados'])->toBe(1)->and($segunda['pagos_vinculados'])->toBe(0)
        ->and(Pago::count())->toBe(1)
        ->and(collect($primera['pagos_sin_vincular'])->pluck('concepto')->all())->toBe(['Jose Pereyra pago parte cuota Kelo']);
    expect(substr_count((string) Cuota::whereDate('periodo', '2026-01-01')->first()->observaciones, 'planilla Cuotas'))->toBe(1);
});
