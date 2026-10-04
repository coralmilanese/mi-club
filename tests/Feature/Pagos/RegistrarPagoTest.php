<?php

use App\Actions\Cuotas\DevengarCuotas;
use App\Actions\Gastos\AnularGasto;
use App\Actions\Gastos\RegistrarGasto;
use App\Actions\Pagos\AnularPago;
use App\Actions\Pagos\RegistrarPago;
use App\Actions\Planes\AsignarPlanSocio;
use App\Actions\Planes\RegistrarTarifaPlan;
use App\Enums\EstadoCuota;
use App\Enums\EstadoPago;
use App\Models\CategoriaGasto;
use App\Models\Cuenta;
use App\Models\Cuota;
use App\Models\GrupoFamiliar;
use App\Models\MedioPago;
use App\Models\Movimiento;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\Socio;
use App\Services\Cuotas\ResolverImporteExigible;
use App\Services\Pagos\ImputadorDePagos;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriaGastoSeeder;
use Database\Seeders\LibroDeCajaSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([PlanSeeder::class, LibroDeCajaSeeder::class, CategoriaGastoSeeder::class]);
    $this->banco = Cuenta::where('nombre', 'Banco AASR')->firstOrFail();
    $this->fer = Cuenta::where('nombre', 'Efectivo')->firstOrFail();
    $this->transferencia = MedioPago::where('codigo', 'transferencia')->firstOrFail();
    $this->efectivo = MedioPago::where('codigo', 'efectivo')->firstOrFail();

    /** Socio con plan AASR desde enero y cuotas devengadas hasta `$hasta`. */
    $this->socioConCuotas = function (string $hasta = '2026-09', string $plan = 'aasr', array $atrib = []) {
        $s = Socio::factory()->create($atrib);
        app(AsignarPlanSocio::class)($s, Plan::where('tipo_plan', $plan)->firstOrFail(), CarbonImmutable::parse('2026-01-01'));
        for ($m = CarbonImmutable::parse('2026-01-01'); $m->format('Y-m') <= $hasta; $m = $m->addMonth()) {
            app(DevengarCuotas::class)($m);
        }

        return $s;
    };
    $this->pagar = fn (Socio $s, string $fecha, string $importe, ?array $manual = null, ?string $ref = null) => app(RegistrarPago::class)(
        $s, CarbonImmutable::parse($fecha), $importe, $this->banco, $this->transferencia, imputacionesManuales: $manual, referenciaExterna: $ref,
    );
});

test('pago exacto de septiembre: cuota cobrada a tarifa vigente, ingreso BRUTO en el libro e impuestos del día', function () {
    $s = Socio::factory()->create(['apellido' => 'Gelos', 'nombre' => 'Gabriel']);
    app(AsignarPlanSocio::class)($s, Plan::where('tipo_plan', 'aasr')->firstOrFail(), CarbonImmutable::parse('2026-09-01'));
    app(DevengarCuotas::class)(CarbonImmutable::parse('2026-09-01'));

    $pago = ($this->pagar)($s, '2026-09-08', '35000');

    $cuota = Cuota::firstOrFail();
    expect($cuota->estado)->toBe(EstadoCuota::Pagada)
        ->and($cuota->importe_cobrado)->toBe('35000.00')
        ->and($cuota->fecha_cancelacion->toDateString())->toBe('2026-09-08')
        ->and($cuota->tarifa_plan_id_cobrada)->not->toBeNull()
        ->and($pago->concepto)->toBe('Gelos Gabriel septiembre');

    // Ingreso BRUTO de 35.000 y por separado los 3 tributos: SIRCREB 1.050 (3%), crédito 210, débito 6,30.
    $libro = Movimiento::where('cuenta_id', $this->banco->id)->orderBy('id')->pluck('importe', 'concepto')->map(fn ($i) => (float) $i)->all();
    expect($libro)->toBe(['Gelos Gabriel septiembre' => 35000.0, 'IMP. I.B SIRCREB' => 1050.0, 'IMP.DEB/CRED P/CRED.' => 210.0, 'IMP.DEB/CRED P/DEB.' => 6.3])
        ->and($this->banco->saldoAl())->toBe('33733.70');
    expect($pago->movimiento->origenable->is($pago))->toBeTrue();
});

test('la fecha del comprobante manda: un pago viejo cargado hoy queda en su día y recalcula ese día', function () {
    $this->travelTo('2026-09-20');
    $s = ($this->socioConCuotas)('2026-09');

    ($this->pagar)($s, '2026-09-11', '35000');

    expect(Movimiento::where('es_tributo', true)->pluck('fecha')->map->toDateString()->unique()->all())->toBe(['2026-09-11'])
        ->and(Movimiento::where('es_tributo', false)->first()->fecha->toDateString())->toBe('2026-09-11')
        ->and(Pago::first()->fecha_registro->toDateString())->toBe('2026-09-20');
});

test('imputa a las cuotas más antiguas primero y las atrasadas a la tarifa de HOY (Branda: 3 × 35.000)', function () {
    $branda = ($this->socioConCuotas)('2026-06'); // abril, mayo y junio quedan devengadas a 30.000
    Cuota::where('socio_id', $branda->id)->whereDate('periodo', '<', '2026-04-01')->update(['estado' => EstadoCuota::Pagada, 'importe_cobrado' => '30000', 'importe_imputado' => '30000']);

    $propuesta = app(ImputadorDePagos::class)->proponer($branda, '105000', CarbonImmutable::parse('2026-09-08'));
    expect($propuesta['exacto'])->toBeTrue()->and($propuesta['imputaciones'])->toHaveCount(3);

    ($this->pagar)($branda, '2026-09-08', '105000');

    $cuotas = Cuota::where('socio_id', $branda->id)->whereDate('periodo', '>=', '2026-04-01')->orderBy('periodo')->get();
    expect($cuotas->pluck('estado')->unique()->all())->toBe([EstadoCuota::Pagada])
        ->and($cuotas->pluck('importe_cobrado')->all())->toBe(['35000.00', '35000.00', '35000.00'])
        ->and($cuotas->pluck('importe_devengado')->all())->toBe(['30000.00', '30000.00', '30000.00']);
});

test('pago parcial (2.567) deja la cuota parcial y luego se completa a la tarifa vigente', function () {
    $s = ($this->socioConCuotas)('2026-09');
    Cuota::where('socio_id', $s->id)->whereDate('periodo', '<', '2026-09-01')->update(['estado' => EstadoCuota::Pagada, 'importe_cobrado' => '30000', 'importe_imputado' => '30000']);

    $pago = ($this->pagar)($s, '2026-09-04', '2567');
    $cuota = Cuota::where('socio_id', $s->id)->whereDate('periodo', '2026-09-01')->firstOrFail();

    expect($cuota->estado)->toBe(EstadoCuota::Parcial)->and($cuota->importe_imputado)->toBe('2567.00')->and($pago->concepto)->toStartWith('Parte cuota');

    ($this->pagar)($s, '2026-09-10', '32433');
    expect($cuota->fresh()->estado)->toBe(EstadoCuota::Pagada)->and($cuota->fresh()->importe_cobrado)->toBe('35000.00');
});

test('pago de varios meses (90.000 = 3 cuotas) genera 3 imputaciones y un solo ingreso', function () {
    $s = ($this->socioConCuotas)('2026-03');

    $pago = ($this->pagar)($s, '2026-03-05', '90000');

    expect($pago->imputaciones)->toHaveCount(3)
        ->and(Movimiento::where('es_tributo', false)->count())->toBe(1)
        ->and(Cuota::where('socio_id', $s->id)->where('estado', EstadoCuota::Pagada->value)->count())->toBe(3)
        ->and($pago->concepto)->toBe($s->nombre_completo.' enero, febrero, marzo');
});

test('lo que sobra queda como saldo a favor y se imputa solo cuando se devenga el mes siguiente', function () {
    $s = ($this->socioConCuotas)('2026-08');
    Cuota::where('socio_id', $s->id)->update(['estado' => EstadoCuota::Pagada, 'importe_cobrado' => '30000', 'importe_imputado' => '30000']);

    $pago = ($this->pagar)($s, '2026-08-20', '35000'); // no debe nada: todo es saldo a favor
    expect($pago->imputaciones)->toHaveCount(0)
        ->and(app(ImputadorDePagos::class)->saldoAFavor($s))->toBe('35000.00')
        ->and($pago->concepto)->toContain('saldo a favor');

    $this->travelTo('2026-09-01');
    app(DevengarCuotas::class)(CarbonImmutable::parse('2026-09-01'));

    $septiembre = Cuota::where('socio_id', $s->id)->whereDate('periodo', '2026-09-01')->firstOrFail();
    expect($septiembre->estado)->toBe(EstadoCuota::Pagada)
        ->and(app(ImputadorDePagos::class)->saldoAFavor($s))->toBe('0.00');
});

test('el titular paga su cuota y la de su adherente en un solo pago (57.500)', function () {
    $titular = Socio::factory()->create();
    $milo = Socio::factory()->create();
    $grupo = GrupoFamiliar::create(['nombre' => 'Familia', 'titular_socio_id' => $titular->id]);
    $titular->update(['grupo_familiar_id' => $grupo->id]);
    $milo->update(['grupo_familiar_id' => $grupo->id]);
    app(AsignarPlanSocio::class)($titular, Plan::where('tipo_plan', 'aasr')->firstOrFail(), CarbonImmutable::parse('2026-09-01'));
    app(AsignarPlanSocio::class)($milo, Plan::where('tipo_plan', 'adicional_familiar')->firstOrFail(), CarbonImmutable::parse('2026-09-01'));
    app(DevengarCuotas::class)(CarbonImmutable::parse('2026-09-01'));

    $pago = ($this->pagar)($titular, '2026-09-08', '57500');

    expect($pago->imputaciones)->toHaveCount(2)
        ->and(Cuota::where('estado', EstadoCuota::Pagada->value)->count())->toBe(2)
        ->and(Cuota::where('socio_id', $milo->id)->first()->importe_cobrado)->toBe('22500.00');
});

test('imputación manual: se puede reasignar, pero no más de lo que debe la cuota ni cuotas ajenas', function () {
    $s = ($this->socioConCuotas)('2026-03');
    $otro = ($this->socioConCuotas)('2026-03');
    $marzo = Cuota::where('socio_id', $s->id)->whereDate('periodo', '2026-03-01')->firstOrFail();
    $ajena = Cuota::where('socio_id', $otro->id)->first();

    // Salteando enero y febrero: paga solo marzo.
    $pago = ($this->pagar)($s, '2026-03-05', '30000', [$marzo->id => '30000']);
    expect($marzo->fresh()->estado)->toBe(EstadoCuota::Pagada)
        ->and(Cuota::where('socio_id', $s->id)->where('estado', EstadoCuota::Pendiente->value)->count())->toBe(2);

    $enero = Cuota::where('socio_id', $s->id)->whereDate('periodo', '2026-01-01')->firstOrFail();
    expect(fn () => ($this->pagar)($s, '2026-03-06', '99999', [$enero->id => '99999']))->toThrow(ValidationException::class);
    expect(fn () => ($this->pagar)($s, '2026-03-06', '30000', [$ajena->id => '30000']))->toThrow(ValidationException::class);
    expect(fn () => ($this->pagar)($s, '2026-03-06', '1000', [$enero->id => '30000']))->toThrow(ValidationException::class);
});

test('anular un pago reabre las cuotas, descongela lo cobrado y deja un contramovimiento', function () {
    $s = ($this->socioConCuotas)('2026-02');
    $pago = ($this->pagar)($s, '2026-02-10', '60000');
    expect(Cuota::where('estado', EstadoCuota::Pagada->value)->count())->toBe(2);

    app(AnularPago::class)($pago, 'Cargado dos veces');

    $cuotas = Cuota::where('socio_id', $s->id)->get();
    expect($cuotas->pluck('estado')->unique()->all())->toBe([EstadoCuota::Pendiente])
        ->and($cuotas->pluck('importe_cobrado')->filter()->all())->toBe([])
        ->and($pago->fresh()->estado)->toBe(EstadoPago::Anulado)
        ->and($this->banco->saldoAl())->toBe('0.00')
        ->and(Movimiento::where('es_tributo', true)->count())->toBe(0);

    expect(fn () => app(AnularPago::class)($pago, 'otra vez'))->toThrow(ValidationException::class);
    expect(app(ImputadorDePagos::class)->saldoAFavor($s))->toBe('0.00');
});

test('una cuota cobrada queda congelada aunque después suba la tarifa', function () {
    $s = ($this->socioConCuotas)('2026-09');
    Cuota::where('socio_id', $s->id)->whereDate('periodo', '<', '2026-09-01')->update(['estado' => EstadoCuota::Pagada, 'importe_cobrado' => '30000', 'importe_imputado' => '30000']);
    ($this->pagar)($s, '2026-09-08', '35000');

    app(RegistrarTarifaPlan::class)(Plan::where('tipo_plan', 'aasr')->firstOrFail(), '50000', CarbonImmutable::parse('2026-10-01'));

    $cuota = Cuota::where('socio_id', $s->id)->whereDate('periodo', '2026-09-01')->firstOrFail();
    expect(app(ResolverImporteExigible::class)->deuda($cuota->load('plan'), CarbonImmutable::parse('2026-12-01')))->toBe('0.00');
});

test('un mismo número de operación no se puede cargar dos veces', function () {
    $s = ($this->socioConCuotas)('2026-03');
    ($this->pagar)($s, '2026-03-05', '30000', null, 'OP-4829173');

    expect(fn () => ($this->pagar)($s, '2026-03-05', '30000', null, 'OP-4829173'))->toThrow(ValidationException::class);

    // Anulado el primero, la referencia queda libre.
    app(AnularPago::class)(Pago::first(), 'error');
    ($this->pagar)($s, '2026-03-05', '30000', null, 'OP-4829173');
    expect(Pago::where('estado', EstadoPago::Confirmado->value)->count())->toBe(1);
});

test('en efectivo el pago va a la caja elegida y no genera impuestos', function () {
    $s = ($this->socioConCuotas)('2026-07');

    app(RegistrarPago::class)($s, CarbonImmutable::parse('2026-07-10'), '90000', $this->fer, $this->efectivo);

    expect($this->fer->saldoAl())->toBe('90000.00')->and($this->banco->saldoAl())->toBe('0.00')->and(Movimiento::where('es_tributo', true)->count())->toBe(0);
});

test('gastos: el egreso entra en el libro con su categoría y suma a la base del débito; anular lo revierte', function () {
    $cpsr = CategoriaGasto::where('codigo', 'cpsr')->firstOrFail();

    $gasto = app(RegistrarGasto::class)($cpsr, CarbonImmutable::parse('2026-09-08'), '20000.00', 'Planeadores luz y socios', $this->banco, $this->transferencia, userId: null);

    $mov = Movimiento::where('es_tributo', false)->firstOrFail();
    expect($mov->categoria_libro)->toBe('CPSR / Planeadores')->and($mov->tipo->value)->toBe('egreso')
        ->and((float) Movimiento::where('concepto', 'IMP.DEB/CRED P/DEB.')->value('importe'))->toBe(120.0);

    app(AnularGasto::class)($gasto, 'Duplicado');
    expect($gasto->fresh()->anulado_at)->not->toBeNull()->and($this->banco->saldoAl())->toBe('0.00')->and(Movimiento::where('es_tributo', true)->count())->toBe(0);

    $cpsr->update(['activa' => false]);
    expect(fn () => app(RegistrarGasto::class)($cpsr, CarbonImmutable::parse('2026-09-08'), '1', 'x', $this->banco))->toThrow(ValidationException::class);
});
