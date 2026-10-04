<?php

use App\Actions\Cuotas\DevengarCuotas;
use App\Actions\Planes\AsignarPlanSocio;
use App\Enums\EstadoCuota;
use App\Models\Cuenta;
use App\Models\Cuota;
use App\Models\Gasto;
use App\Models\GrupoFamiliar;
use App\Models\LiquidacionDiaria;
use App\Models\Movimiento;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\Socio;
use App\Services\Importacion\ImportadorCuotas;
use App\Services\Importacion\ImportadorLibroCaja;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriaGastoSeeder;
use Database\Seeders\LibroDeCajaSeeder;
use Database\Seeders\PlanSeeder;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function excel(string $fecha): float
{
    return (float) Date::PHPToExcel(new DateTime($fecha));
}

/** Arma un .xlsx con las hojas ` Libro de Caja` y ` Cuotas` (con el espacio adelante, como el original). */
function excelClub(array $libro, array $cuotas = []): string
{
    $wb = new Spreadsheet;
    $ws = $wb->getActiveSheet()->setTitle(' Libro de Caja');
    $ws->fromArray([null, 'CONCEPTO', 'SALDOS', 'INGRESO', 'EGRESO', 'RESTO', 'TRANSACCIÓN', 'PAGO'], null, 'A1');
    foreach ($libro as $i => $fila) {
        $ws->fromArray($fila, null, 'A'.($i + 2));
    }

    $wc = $wb->createSheet()->setTitle(' Cuotas');
    $wc->fromArray(['NRO', 'SOCIO ', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE'], null, 'A1');
    foreach ($cuotas as $i => [$nombre, $meses]) {
        $wc->fromArray([$i + 1, $nombre, ...array_pad($meses, 9, null)], null, 'A'.($i + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'club').'.xlsx';
    (new Xlsx($wb))->save($path);

    return $path;
}

beforeEach(function () {
    $this->seed([PlanSeeder::class, LibroDeCajaSeeder::class, CategoriaGastoSeeder::class]);
    $this->banco = Cuenta::where('nombre', 'Banco AASR')->firstOrFail();
});

/** Un día del libro: cuota de 95.000, gasto de 20.000 y sus impuestos reales (SIRCREB 3%, crédito 0,6%, débito 0,6%). */
function libroBasico(): array
{
    $f = excel('2026-09-08');

    return [
        [excel('2026-01-01'), 'Saldo Comienzo 2026', null, 1000000, null, 1000000, null, 'Saldo al Comienzo'],
        [$f, 'Gelos Gabriel septiembre', null, 95000, null, null, 'Transferencia', 'Cuota Socio'],
        [$f, 'Planeadores luz y socios', null, null, 20000, null, 'Transferencia', 'CPSR'],
        [$f, 'IMP. I.B SIRCREB', null, null, 2850, null, null, 'Siscreb'],
        [$f, 'IMP.DEB/CRED P/DEB.', null, null, 137.1, null, null, 'Debito/Credito Impuesto'],
        [$f, 'IMP.DEB/CRED P/CRED.', null, null, 570, null, null, 'Debito/Credito Impuesto'],
        [excel('2026-09-10'), 'Kelo Nagore septiembre', null, 35000, null, null, 'Efectivo', 'Cuota Socio'],
        [excel('2026-09-11'), 'Federacion septiembre', null, null, 54400, null, null, null],
        [excel('2026-09-11'), 'Otro cobro', null, 5000, null, null, null, null],
        // Bloque de conciliación del pie
        [null, 'Saldo en cuenta bancaria AASR a la Fecha', 1200000, null, null, null, null, null],
        [null, 'Efectivo en Mano a la Fecha (Fer)', 250000, null, null, null, null, null],
        [null, 'Efectivo en Mano a la Fecha  (Fran)', 100000, null, null, null, null, null],
        [null, 'Dinero Invertido en Plazo Fijo $ 0', null, null, null, null, null, null],
        [null, 'Total/Diferencia', null, null, null, 1550000, null, null],
    ];
}

test('libro: reparte la apertura entre cuentas para que cada saldo cierre con el pie, y no dispara el recálculo', function () {
    $r = app(ImportadorLibroCaja::class)->importar(excelClub(libroBasico()));

    // Banco: 1.200.000 − (95.000 − 20.000 − impuestos 3.557,10 − 54.400 + 5.000) = 1.177.957,10. Efectivo (Fer 250.000 + Fran 100.000 = 350.000) − 35.000 cobrados en el año.
    expect($r['aperturas']['Banco AASR'])->toBe('1177957.10')
        ->and($r['aperturas']['Efectivo'])->toBe('315000.00')
        ->and($r['aperturas'])->not->toHaveKey('Efectivo Fran');
    expect($this->banco->saldoAl())->toBe('1200000.00')
        ->and(Cuenta::where('nombre', 'Efectivo')->first()->saldoAl())->toBe('350000.00')
        ->and($r['saldo_total'])->toBe('1550000.00')->and($r['saldo_esperado'])->toBe('1550000.00');

    // Los impuestos importados quedan tal cual (no se recalcularon) y marcados como ajustados a mano.
    expect(Movimiento::where('es_tributo', true)->count())->toBe(3)
        ->and(LiquidacionDiaria::first()->ajustada_manualmente)->toBeTrue()
        ->and($r['liquidaciones'])->toMatchArray(['dias' => 1, 'coinciden' => 1, 'difieren' => []]);
});

test('libro: normaliza categorías, crea gastos, infiere lo que falta y manda el efectivo a la caja', function () {
    $r = app(ImportadorLibroCaja::class)->importar(excelClub(libroBasico()));

    expect($r['movimientos'])->toMatchArray(['cuotas' => 3, 'gastos' => 2, 'impuestos' => 3, 'otros_ingresos' => 0]);

    $gastos = Gasto::with('categoria')->get();
    expect($gastos->pluck('categoria.codigo')->sort()->values()->all())->toBe(['cpsr', 'faa'])
        ->and(Movimiento::where('concepto', 'Planeadores luz y socios')->first()->categoria_libro)->toBe('CPSR / Planeadores')
        ->and(Movimiento::where('concepto', 'Federacion septiembre')->first()->categoria_libro)->toBe('FAA / Federación')
        ->and(Movimiento::where('concepto', 'Otro cobro')->first()->categoria_libro)->toBe('Cuota Socio')
        ->and(Movimiento::where('concepto', 'Kelo Nagore septiembre')->first()->cuenta->nombre)->toBe('Efectivo');
    expect(collect($r['inferencias'])->join(' '))->toContain('Federacion septiembre');
});

test('libro: dry-run no escribe y no se puede importar sobre un libro con movimientos', function () {
    $archivo = excelClub(libroBasico());

    app(ImportadorLibroCaja::class)->importar($archivo, dryRun: true);
    expect(Movimiento::count())->toBe(0)->and(Gasto::count())->toBe(0);

    app(ImportadorLibroCaja::class)->importar($archivo);
    expect(fn () => app(ImportadorLibroCaja::class)->importar($archivo))->toThrow(RuntimeException::class);
    $this->artisan('libro:importar', ['archivo' => '/no/existe.xlsx'])->assertFailed();
});

test('libro: si el motor de impuestos calcularía otro importe, se informa (validación contra el histórico)', function () {
    $libro = libroBasico();
    $libro[3][3] = null;
    $libro[3][4] = 9999; // SIRCREB distinto del 3% real

    $r = app(ImportadorLibroCaja::class)->importar(excelClub($libro));

    expect($r['liquidaciones']['coinciden'])->toBe(0)->and($r['liquidaciones']['difieren'][0])->toContain('sircreb');
});

/** Socios con plan AASR y cuotas devengadas de enero a marzo (la grilla y el libro ya asumen que existen). */
function socioAasr(string $apellido, string $nombre): Socio
{
    $s = Socio::factory()->create(['apellido' => $apellido, 'nombre' => $nombre]);
    app(AsignarPlanSocio::class)($s, Plan::where('tipo_plan', 'aasr')->firstOrFail(), CarbonImmutable::parse('2026-01-01'));

    return $s;
}

function devengar(string $hasta = '2026-03'): void
{
    for ($m = CarbonImmutable::parse('2026-01-01'); $m->format('Y-m') <= $hasta; $m = $m->addMonth()) {
        app(DevengarCuotas::class)($m);
    }
}

test('cuotas: la grilla marca las cuotas cobradas y el libro se vincula con el socio y sus meses (rangos, abreviaturas, "y")', function () {
    $lopez = socioAasr('Lopez', 'Valeria');
    $branda = socioAasr('Branda', 'Ernesto');
    devengar('2026-03');

    $f = excel('2026-03-20');
    $libro = [
        [excel('2026-01-01'), 'Saldo Comienzo 2026', null, 100000, null, null, null, 'Saldo al Comienzo'],
        [$f, 'Vale Lopez enero a marzo', null, 90000, null, null, 'Transferencia', 'Cuota Socio'],
        [$f, 'Ernesto Branda enero y febrero', null, 60000, null, null, 'Transferencia', 'Cuota Socio'],
        [$f, 'Ernesto Branda marzo', null, 12345, null, null, 'Transferencia', 'Cuota Socio'], // no coincide con nada
        [$f, 'Persona Inexistente enero', null, 30000, null, null, 'Transferencia', 'Cuota Socio'],
    ];
    $grilla = [
        ['LOPEZ VALERIA', [30000, 30000, 30000]],
        ['BRANDA ERNESTO', [30000, 30000, 'deuda']],
        ['NADIE CONOCIDO', [30000]],
    ];
    $archivo = excelClub($libro, $grilla);
    app(ImportadorLibroCaja::class)->importar($archivo);

    $r = app(ImportadorCuotas::class)->importar($archivo);

    expect($r['cuotas_cobradas'])->toBe(5)
        ->and($r['filas_sin_socio'])->toBe(['NADIE CONOCIDO ($30000)'])
        ->and($r['pagos_vinculados'])->toBe(2)
        ->and(collect($r['pagos_sin_vincular'])->pluck('concepto')->all())->toBe(['Ernesto Branda marzo', 'Persona Inexistente enero']);

    expect(Cuota::where('socio_id', $lopez->id)->where('estado', EstadoCuota::Pagada->value)->count())->toBe(3)
        ->and(Cuota::where('socio_id', $branda->id)->whereDate('periodo', '2026-03-01')->first()->estado)->toBe(EstadoCuota::Pendiente);

    $pago = Pago::where('socio_id', $lopez->id)->firstOrFail();
    expect($pago->importe_bruto)->toBe('90000.00')->and($pago->imputaciones)->toHaveCount(3)
        ->and($pago->movimiento->concepto)->toBe('Vale Lopez enero a marzo')
        ->and($pago->imputaciones->first()->cuota->fecha_cancelacion->toDateString())->toBe('2026-03-20');
});

test('cuotas: el importe de un titular se separa en cuota propia + adicionales familiares', function () {
    $titular = socioAasr('Cepeda', 'Agustin');
    $milo = Socio::factory()->create(['apellido' => 'Cepeda', 'nombre' => 'Milo']);
    $grupo = GrupoFamiliar::create(['nombre' => 'Familia Cepeda', 'titular_socio_id' => $titular->id]);
    $titular->update(['grupo_familiar_id' => $grupo->id]);
    $milo->update(['grupo_familiar_id' => $grupo->id]);
    app(AsignarPlanSocio::class)($milo, Plan::where('tipo_plan', 'adicional_familiar')->firstOrFail(), CarbonImmutable::parse('2026-01-01'));
    devengar('2026-01');

    $archivo = excelClub([[excel('2026-01-01'), 'Saldo Comienzo 2026', null, 1, null, null, null, 'Saldo al Comienzo']], [
        ['CEPEDA AGUSTIN', [51500]],
        ['CEPEDA MILO', ['Familiar']],
    ]);
    app(ImportadorCuotas::class)->importar($archivo);

    // 51.500 = 30.000 (titular) + 21.500 (Milo): la tarifa histórica de cada plan.
    expect(Cuota::where('socio_id', $titular->id)->first()->importe_cobrado)->toBe('30000.00')
        ->and(Cuota::where('socio_id', $milo->id)->first()->importe_cobrado)->toBe('21500.00')
        ->and(Cuota::where('socio_id', $milo->id)->first()->socio_pagador_id)->toBe($titular->id);
});

test('cuotas: dry-run no escribe y el comando falla con archivo inexistente', function () {
    socioAasr('Lopez', 'Valeria');
    devengar('2026-01');
    $archivo = excelClub([[excel('2026-01-01'), 'Saldo Comienzo 2026', null, 1, null, null, null, 'Saldo al Comienzo']], [['LOPEZ VALERIA', [30000]]]);

    $r = app(ImportadorCuotas::class)->importar($archivo, dryRun: true);

    expect($r['cuotas_cobradas'])->toBe(1)->and(Cuota::where('estado', EstadoCuota::Pagada->value)->count())->toBe(0);
    $this->artisan('cuotas:importar', ['archivo' => '/no/existe.xlsx'])->assertFailed();
});

test('cuotas: un pago parcial del libro se imputa a la cuota pendiente más antigua', function () {
    $pereyra = socioAasr('Pereyra', 'Jose');
    devengar('2026-03');
    $archivo = excelClub([
        [excel('2026-01-01'), 'Saldo Comienzo 2026', null, 100000, null, null, null, 'Saldo al Comienzo'],
        [excel('2026-03-04'), 'Parte cuota Jose Pereyra', null, 2567, null, null, 'Transferencia', 'Cuota Socio'],
    ], [['PEREYRA JOSE', [null, null, 'deuda']]]);
    app(ImportadorLibroCaja::class)->importar($archivo);

    $r = app(ImportadorCuotas::class)->importar($archivo);

    expect($r['pagos_vinculados'])->toBe(1);
    $enero = Cuota::where('socio_id', $pereyra->id)->whereDate('periodo', '2026-01-01')->first();
    expect($enero->estado)->toBe(EstadoCuota::Parcial)->and($enero->importe_imputado)->toBe('2567.00');
});
