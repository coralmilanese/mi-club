<?php

use App\Actions\Libro\AbrirCuenta;
use App\Actions\Libro\RegistrarMovimiento;
use App\Enums\TipoMovimiento;
use App\Models\ArqueoCuenta;
use App\Models\Cuenta;
use App\Models\LiquidacionDiaria;
use App\Models\Movimiento;
use App\Models\Tributo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LibroDeCajaSeeder;

beforeEach(function () {
    $this->seed(LibroDeCajaSeeder::class);
    $this->tesorero = User::factory()->create();
    $this->banco = Cuenta::where('nombre', 'Banco AASR')->firstOrFail();
    $this->fer = Cuenta::where('nombre', 'Efectivo')->firstOrFail();
    $this->mover = fn (Cuenta $c, TipoMovimiento $t, string $fecha, string $importe, string $concepto = 'x') => app(RegistrarMovimiento::class)($c, $t, CarbonImmutable::parse($fecha), $concepto, $importe, 'Cuota Socio');
});

test('el saldo corrido es el real aunque se filtre y aunque se cargue un movimiento con fecha vieja', function () {
    ($this->mover)($this->fer, TipoMovimiento::Ingreso, '2026-03-10', '1000', 'B');
    ($this->mover)($this->fer, TipoMovimiento::Ingreso, '2026-03-20', '500', 'C');
    ($this->mover)($this->fer, TipoMovimiento::Egreso, '2026-03-15', '200', 'D');
    // Carga retroactiva: entra "en el medio" y el saldo de las filas posteriores se reordena solo.
    ($this->mover)($this->fer, TipoMovimiento::Ingreso, '2026-03-05', '100', 'A');

    $this->actingAs($this->tesorero)->get("/libro-caja?cuenta_id={$this->fer->id}&orden=asc")
        ->assertInertia(fn ($p) => $p->where('movimientos.data', fn ($d) => collect($d)->pluck('saldo')->map(fn ($s) => (float) $s)->all() === [100.0, 1100.0, 900.0, 1400.0]));

    // Filtrando desde el 15/03 el saldo sigue siendo el acumulado real (no arranca de cero).
    $this->actingAs($this->tesorero)->get("/libro-caja?cuenta_id={$this->fer->id}&orden=asc&desde=2026-03-15")
        ->assertInertia(fn ($p) => $p->where('movimientos.data.0.concepto', 'D')->where('movimientos.data.0.saldo', '900.00')->where('totales.neto', '300.00'));
});

test('el libro muestra ingreso y egreso en columnas y marca los impuestos automáticos', function () {
    ($this->mover)($this->banco, TipoMovimiento::Ingreso, '2026-09-08', '95000');

    $this->actingAs($this->tesorero)->get('/libro-caja?orden=asc')
        ->assertInertia(fn ($p) => $p
            ->has('movimientos.data', 4)
            ->where('movimientos.data.0.ingreso', '95000.00')->where('movimientos.data.0.egreso', null)
            ->where('movimientos.data.1.es_tributo', true)->where('movimientos.data.1.egreso', '2850.00')
            ->where('movimientos.data.3.saldo', '91562.90'));
});

test('filtra por categoría y por texto del concepto', function () {
    ($this->mover)($this->fer, TipoMovimiento::Ingreso, '2026-03-10', '1000', 'Kelo Nagore julio');
    ($this->mover)($this->fer, TipoMovimiento::Ingreso, '2026-03-11', '1000', 'IMAC Argentina');

    $this->actingAs($this->tesorero)->get('/libro-caja?q=imac')->assertInertia(fn ($p) => $p->has('movimientos.data', 1));
    $this->actingAs($this->tesorero)->get('/libro-caja?categoria=Inexistente')->assertInertia(fn ($p) => $p->has('movimientos.data', 0));
});

test('registrar un movimiento a mano en el banco dispara la liquidación del día', function () {
    $this->actingAs($this->tesorero)->post('/libro-caja/movimientos', [
        'cuenta_id' => $this->banco->id, 'tipo' => 'ingreso', 'fecha' => '2026-08-31', 'concepto' => 'IMAC Argentina', 'categoria' => 'IMAC', 'importe' => '1175000',
    ])->assertSessionHasNoErrors();

    // 3% de 1.175.000 = 35.250 (el Excel muestra 36.150 con el socio de 30.000 incluido).
    expect(Movimiento::where('es_tributo', true)->where('concepto', 'IMP. I.B SIRCREB')->value('importe'))->toBe('35250.00');
});

test('anular desde la UI deja el contramovimiento y exige motivo', function () {
    $m = ($this->mover)($this->banco, TipoMovimiento::Ingreso, '2026-09-08', '10000');

    $this->actingAs($this->tesorero)->post("/libro-caja/movimientos/{$m->id}/anular", ['motivo' => ''])->assertSessionHasErrors('motivo');
    $this->actingAs($this->tesorero)->post("/libro-caja/movimientos/{$m->id}/anular", ['motivo' => 'Duplicado'])->assertSessionHasNoErrors();

    expect($m->fresh()->anulado_por_movimiento_id)->not->toBeNull()->and($this->banco->saldoAl())->toBe('0.00');
});

test('exporta el libro a Excel', function () {
    ($this->mover)($this->fer, TipoMovimiento::Ingreso, '2026-03-10', '1000', 'Kelo Nagore julio');

    $r = $this->actingAs($this->tesorero)->get('/libro-caja/exportar');

    $r->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($r->baseResponse->headers->get('content-disposition'))->toContain('libro-de-caja-');
});

test('los ajustes manuales de la liquidación se guardan con motivo y se pueden revertir', function () {
    ($this->mover)($this->banco, TipoMovimiento::Ingreso, '2026-02-02', '100000');

    $this->actingAs($this->tesorero)->post('/libro-caja/liquidaciones/ajustar', ['cuenta_id' => $this->banco->id, 'fecha' => '2026-02-02', 'importes' => ['sircreb' => '1'], 'motivo' => ''])
        ->assertSessionHasErrors('motivo');

    $this->actingAs($this->tesorero)->post('/libro-caja/liquidaciones/ajustar', ['cuenta_id' => $this->banco->id, 'fecha' => '2026-02-02', 'importes' => ['sircreb' => '3990.55', 'imp_credito' => '600', 'imp_debito' => '23.94'], 'motivo' => 'El banco cobró de más'])
        ->assertSessionHasNoErrors();
    expect(LiquidacionDiaria::firstOrFail()->ajustada_manualmente)->toBeTrue();

    $this->actingAs($this->tesorero)->post('/libro-caja/liquidaciones/restablecer', ['cuenta_id' => $this->banco->id, 'fecha' => '2026-02-02']);
    expect(LiquidacionDiaria::firstOrFail()->ajustada_manualmente)->toBeFalse()
        ->and(Movimiento::where('concepto', 'IMP. I.B SIRCREB')->value('importe'))->toBe('4000.00');

    $this->actingAs($this->tesorero)->get('/libro-caja/liquidaciones?cuenta_id='.$this->banco->id.'&mes=2026-02')
        ->assertInertia(fn ($p) => $p->has('liquidaciones', 1)->where('liquidaciones.0.importes.sircreb', '4000.00'));
});

test('la conciliación compara el saldo calculado con el declarado y guarda el arqueo', function () {
    app(AbrirCuenta::class)($this->fer, '218743.64', CarbonImmutable::parse('2026-01-01'));
    ($this->mover)($this->fer, TipoMovimiento::Ingreso, '2026-07-10', '35000', 'Kelo Nagore');

    $this->actingAs($this->tesorero)->post('/conciliacion', ['fecha' => now()->toDateString(), 'saldos' => [$this->fer->id => '253743.64', $this->banco->id => '']])->assertSessionHasNoErrors();
    $this->actingAs($this->tesorero)->post('/conciliacion', ['fecha' => now()->toDateString(), 'saldos' => [$this->fer->id => '250000']]);

    $arqueos = ArqueoCuenta::orderBy('id')->get();
    expect($arqueos)->toHaveCount(2)
        ->and($arqueos[0]->diferencia)->toBe('0.00')
        ->and($arqueos[1]->diferencia)->toBe('-3743.64');

    $this->actingAs($this->tesorero)->get('/conciliacion')->assertInertia(fn ($p) => $p->component('conciliacion/index')->has('arqueos', 2));
});

test('cuentas: apertura una sola vez y alta de alícuota sin solaparse', function () {
    $this->actingAs($this->tesorero)->post("/configuracion/cuentas/{$this->banco->id}/apertura", ['saldo' => '1884843.25', 'fecha' => '2026-01-01'])->assertSessionHasNoErrors();
    $this->actingAs($this->tesorero)->post("/configuracion/cuentas/{$this->banco->id}/apertura", ['saldo' => '5', 'fecha' => '2026-01-01'])->assertSessionHasErrors('saldo');

    $sircreb = Tributo::where('codigo', 'sircreb')->firstOrFail();
    $this->actingAs($this->tesorero)->post("/configuracion/tributos/{$sircreb->id}/alicuotas", ['alicuota' => '2.5', 'vigencia_desde' => '2027-01-01'])->assertSessionHasNoErrors();
    $this->actingAs($this->tesorero)->post("/configuracion/tributos/{$sircreb->id}/alicuotas", ['alicuota' => '2', 'vigencia_desde' => '2026-05-01'])->assertSessionHasErrors('vigencia_desde');

    expect($sircreb->alicuotaVigente(CarbonImmutable::parse('2026-12-31'))->alicuota)->toBe('3.00000')
        ->and($sircreb->alicuotaVigente(CarbonImmutable::parse('2027-01-01'))->alicuota)->toBe('2.50000');
});

test('solo lectura: ve el libro y la conciliación pero no puede registrar ni anular ni ajustar', function () {
    $lector = User::factory()->lectura()->create();
    $m = ($this->mover)($this->fer, TipoMovimiento::Ingreso, '2026-03-10', '1000');

    foreach (['/libro-caja', '/libro-caja/liquidaciones', '/conciliacion', '/configuracion/cuentas', '/configuracion/tributos'] as $url) {
        $this->actingAs($lector)->get($url)->assertOk();
    }
    $this->actingAs($lector)->post('/libro-caja/movimientos', ['cuenta_id' => $this->fer->id, 'tipo' => 'ingreso', 'fecha' => '2026-03-10', 'concepto' => 'x', 'categoria' => 'x', 'importe' => 1])->assertForbidden();
    $this->actingAs($lector)->post("/libro-caja/movimientos/{$m->id}/anular", ['motivo' => 'x'])->assertForbidden();
    $this->actingAs($lector)->post('/libro-caja/liquidaciones/ajustar', ['cuenta_id' => $this->banco->id, 'fecha' => '2026-03-10', 'importes' => [], 'motivo' => 'x'])->assertForbidden();
    $this->actingAs($lector)->post('/conciliacion', ['fecha' => '2026-03-10', 'saldos' => [$this->fer->id => 1]])->assertForbidden();
});

test('la migración unifica Efectivo Fer y Efectivo Fran en una sola cuenta "Efectivo" sin perder historial', function () {
    $efectivo = Cuenta::where('nombre', 'Efectivo')->firstOrFail();
    $efectivo->update(['nombre' => 'Efectivo Fer']);
    $fran = Cuenta::create(['nombre' => 'Efectivo Fran', 'tipo' => 'efectivo', 'aplica_tributos' => false, 'activa' => true]);

    app(AbrirCuenta::class)($efectivo, '153743.64', CarbonImmutable::parse('2026-01-01'));
    app(AbrirCuenta::class)($fran, '145000.00', CarbonImmutable::parse('2026-01-01'));
    ($this->mover)($fran, TipoMovimiento::Ingreso, '2026-02-10', '30000', 'Cobro caja Fran');
    ($this->mover)($efectivo, TipoMovimiento::Ingreso, '2026-07-10', '35000', 'Cobro caja Fer');
    ArqueoCuenta::create(['cuenta_id' => $fran->id, 'fecha' => '2026-08-01', 'saldo_declarado' => 1, 'saldo_calculado' => 1, 'diferencia' => 0]);

    (include database_path('migrations/2026_09_24_000100_unificar_cuentas_de_efectivo.php'))->up();

    expect(Cuenta::whereIn('nombre', ['Efectivo Fer', 'Efectivo Fran'])->count())->toBe(0);
    $unica = Cuenta::where('nombre', 'Efectivo')->firstOrFail();
    expect($unica->saldoAl())->toBe('363743.64')                       // 153.743,64 + 145.000 + 30.000 + 35.000
        ->and($unica->saldo_inicial)->toBe('298743.64')
        ->and($unica->movimientos()->whereHas('asiento', fn ($q) => $q->where('tipo', 'apertura'))->count())->toBe(1)
        ->and(ArqueoCuenta::first()->cuenta_id)->toBe($unica->id);
});
