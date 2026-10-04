<?php

use App\Actions\Cuotas\DevengarCuotas;
use App\Actions\Pagos\VincularIngresoComoPagoIncompleto;
use App\Actions\Planes\AsignarPlanSocio;
use App\Enums\EstadoCuota;
use App\Models\Cuota;
use App\Models\GrupoFamiliar;
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
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function libroYGrilla(array $libro, array $cuotas): string
{
    $wb = new Spreadsheet;
    $ws = $wb->getActiveSheet()->setTitle(' Libro de Caja');
    $ws->fromArray([null, 'CONCEPTO', 'SALDOS', 'INGRESO', 'EGRESO', 'RESTO', 'TRANSACCIÓN', 'PAGO'], null, 'A1');
    foreach ($libro as $i => $fila) {
        $fila[0] = (float) Date::PHPToExcel(new DateTime($fila[0]));
        $ws->fromArray($fila, null, 'A'.($i + 2));
    }
    $wc = $wb->createSheet()->setTitle(' Cuotas');
    $wc->fromArray(['NRO', 'SOCIO ', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL'], null, 'A1');
    foreach ($cuotas as $i => [$nombre, $meses]) {
        $wc->fromArray([$i + 1, $nombre, ...array_pad($meses, 4, null)], null, 'A'.($i + 2));
    }
    $path = tempnam(sys_get_temp_dir(), 'club').'.xlsx';
    (new Xlsx($wb))->save($path);

    return $path;
}

beforeEach(function () {
    $this->seed([PlanSeeder::class, LibroDeCajaSeeder::class, CategoriaGastoSeeder::class]);
    $this->travelTo('2026-09-20');
    $this->apertura = ['2026-01-01', 'Saldo Comienzo 2026', null, 100000, null, null, null, 'Saldo al Comienzo'];
});

test('los pagos de meses anteriores a 2026 se ignoran a propósito: no aparecen como pendientes', function () {
    $s = Socio::factory()->create(['apellido' => 'Branda', 'nombre' => 'Ernesto']);
    app(AsignarPlanSocio::class)($s, Plan::where('tipo_plan', 'aasr')->firstOrFail(), CarbonImmutable::parse('2026-01-01'));
    app(DevengarCuotas::class)(CarbonImmutable::parse('2026-01-01'));

    $archivo = libroYGrilla([
        $this->apertura,
        ['2026-02-04', 'Ernesto Branda noviembre diciembre 2025', null, 60000, null, null, 'Transferencia', 'Cuota Socio'],
        ['2026-02-05', 'Ernesto Branda octubre 2025', null, 30000, null, null, 'Transferencia', 'Cuota Socio'],
        ['2026-02-06', 'Persona Inexistente enero', null, 30000, null, null, 'Transferencia', 'Cuota Socio'],
    ], [['BRANDA ERNESTO', [30000]]]);
    app(ImportadorLibroCaja::class)->importar($archivo);

    $r = app(ImportadorCuotas::class)->importar($archivo);

    expect($r['pagos_previos'])->toBe(2)
        ->and(collect($r['pagos_sin_vincular'])->pluck('concepto')->all())->toBe(['Persona Inexistente enero']);
});

test('pago incompleto: manda el libro; las cuotas se reabren y se imputa lo realmente ingresado', function () {
    $titular = Socio::factory()->create(['apellido' => 'Giuliani', 'nombre' => 'Ezequiel']);
    $polo = Socio::factory()->create(['apellido' => 'Giuliani', 'nombre' => 'Polo']);
    $grupo = GrupoFamiliar::create(['nombre' => 'Familia Giuliani', 'titular_socio_id' => $titular->id]);
    foreach ([$titular, $polo] as $s) {
        $s->update(['grupo_familiar_id' => $grupo->id]);
    }
    app(AsignarPlanSocio::class)($titular, Plan::where('tipo_plan', 'aasr')->firstOrFail(), CarbonImmutable::parse('2026-01-01'));
    app(AsignarPlanSocio::class)($polo, Plan::where('tipo_plan', 'adicional_familiar')->firstOrFail(), CarbonImmutable::parse('2026-01-01'));
    for ($m = CarbonImmutable::parse('2026-01-01'); $m->format('Y-m') <= '2026-03'; $m = $m->addMonth()) {
        app(DevengarCuotas::class)($m);
    }

    // La planilla da febrero y marzo por pagados (2 × 51.500 = 103.000), pero al banco entraron 60.000.
    $archivo = libroYGrilla([
        $this->apertura,
        ['2026-03-18', 'Ezequiel Giuliani febrero, marzo', null, 60000, null, null, 'Transferencia', 'Cuota Socio'],
    ], [['GIULIANI EZEQUIEL', [51500, 51500, 51500]], ['GIULIANI POLO', ['Familiar', 'Familiar', 'Familiar']]]);
    app(ImportadorLibroCaja::class)->importar($archivo);
    app(ImportadorCuotas::class)->importar($archivo);
    expect(Cuota::where('estado', EstadoCuota::Pagada->value)->count())->toBe(6); // la planilla las da todas por pagadas

    $ingreso = Movimiento::where('concepto', 'Ezequiel Giuliani febrero, marzo')->firstOrFail();
    $pago = app(VincularIngresoComoPagoIncompleto::class)($ingreso, $titular, ['2026-02', '2026-03']);

    // Febrero completo (30.000 + 21.500 = 51.500); a marzo le llegan 8.500 y queda incompleto.
    $cuota = fn (Socio $s, string $p) => Cuota::where('socio_id', $s->id)->whereDate('periodo', $p)->firstOrFail();
    expect($pago->importe_bruto)->toBe('60000.00')
        ->and($cuota($titular, '2026-02-01')->estado)->toBe(EstadoCuota::Pagada)
        ->and($cuota($polo, '2026-02-01')->estado)->toBe(EstadoCuota::Pagada)
        ->and($cuota($titular, '2026-03-01')->estado)->toBe(EstadoCuota::Parcial)
        ->and($cuota($titular, '2026-03-01')->importe_imputado)->toBe('8500.00')
        ->and($cuota($polo, '2026-03-01')->estado)->toBe(EstadoCuota::Pendiente)
        ->and($cuota($titular, '2026-01-01')->estado)->toBe(EstadoCuota::Pagada) // enero no se toca
        ->and($ingreso->fresh()->origenable_id)->toBe($pago->id);

    // Volver a importar la planilla NO pisa lo que ya tiene un pago real detrás.
    app(ImportadorCuotas::class)->importar($archivo);
    expect($cuota($titular, '2026-03-01')->estado)->toBe(EstadoCuota::Parcial)
        ->and($cuota($polo, '2026-03-01')->estado)->toBe(EstadoCuota::Pendiente);
});

test('pago incompleto: no se puede vincular dos veces ni a períodos sin cuotas', function () {
    $s = Socio::factory()->create();
    app(AsignarPlanSocio::class)($s, Plan::where('tipo_plan', 'aasr')->firstOrFail(), CarbonImmutable::parse('2026-01-01'));
    app(DevengarCuotas::class)(CarbonImmutable::parse('2026-01-01'));
    $archivo = libroYGrilla([$this->apertura, ['2026-01-20', 'Pago', null, 10000, null, null, 'Transferencia', 'Cuota Socio']], []);
    app(ImportadorLibroCaja::class)->importar($archivo);
    $ingreso = Movimiento::where('concepto', 'Pago')->firstOrFail();

    expect(fn () => app(VincularIngresoComoPagoIncompleto::class)($ingreso, $s, ['2027-05']))->toThrow(ValidationException::class);

    app(VincularIngresoComoPagoIncompleto::class)($ingreso, $s, ['2026-01']);
    expect(fn () => app(VincularIngresoComoPagoIncompleto::class)($ingreso->fresh(), $s, ['2026-01']))->toThrow(ValidationException::class)
        ->and(Pago::count())->toBe(1);

    $this->artisan('pagos:vincular-incompleto', ['movimiento' => 999999, 'socio' => $s->id, '--periodos' => '2026-01'])->assertFailed();
});
