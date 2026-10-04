<?php

use App\Actions\Planes\RegistrarTarifaPlan;
use App\Enums\EstadoCuota;
use App\Models\Cuota;
use App\Models\Plan;
use App\Models\Socio;
use App\Services\Cuotas\ResolverImporteExigible;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->aasr = Plan::where('tipo_plan', 'aasr')->firstOrFail();
    $this->resolver = app(ResolverImporteExigible::class);

    $this->cuota = fn (string $periodo, array $extra = []) => Cuota::factory()->create([
        'plan_id' => $this->aasr->id, 'periodo' => $periodo, 'importe_devengado' => '30000.00', ...$extra,
    ]);
});

test('caso real Branda: debe abril, mayo y junio devengadas a 30.000 y hoy debe 105.000, no 90.000', function () {
    $branda = Socio::factory()->create();
    $cuotas = collect(['2026-04-01', '2026-05-01', '2026-06-01'])->map(fn ($p) => ($this->cuota)($p, ['socio_id' => $branda->id]));

    $hoy = CarbonImmutable::parse('2026-09-08');
    $deuda = $cuotas->sum(fn (Cuota $c) => (float) $this->resolver->deuda($c->load('plan'), $hoy));

    expect($deuda)->toBe(105000.0);
});

test('una cuota ya cobrada queda congelada aunque suba la tarifa', function () {
    $cuota = ($this->cuota)('2026-03-01', ['estado' => EstadoCuota::Pagada, 'importe_cobrado' => '30000.00', 'importe_imputado' => '30000.00']);
    app(RegistrarTarifaPlan::class)($this->aasr, '45000.00', CarbonImmutable::parse('2026-10-01'));

    expect($this->resolver->importe($cuota->load('plan'), CarbonImmutable::parse('2026-12-01')))->toBe('30000.00')
        ->and($this->resolver->deuda($cuota, CarbonImmutable::parse('2026-12-01')))->toBe('0.00');
});

test('una cuota impaga sigue la tarifa: cuando sube, la deuda sube', function () {
    $cuota = ($this->cuota)('2026-08-01');
    app(RegistrarTarifaPlan::class)($this->aasr, '40000.00', CarbonImmutable::parse('2026-10-01'));
    $cuota->load('plan');

    expect($this->resolver->deuda($cuota, CarbonImmutable::parse('2026-09-30')))->toBe('35000.00')
        ->and($this->resolver->deuda($cuota, CarbonImmutable::parse('2026-10-15')))->toBe('40000.00');
});

test('pago parcial más aumento: se revalúa el saldo (supuesto D3)', function () {
    $cuota = ($this->cuota)('2026-05-01', ['estado' => EstadoCuota::Parcial, 'importe_imputado' => '10000.00']);

    expect($this->resolver->deuda($cuota->load('plan'), CarbonImmutable::parse('2026-09-08')))->toBe('25000.00');
});

test('exentas y anuladas no adeudan nada', function () {
    foreach ([EstadoCuota::Exenta, EstadoCuota::Anulada] as $estado) {
        $cuota = ($this->cuota)('2026-05-01', ['estado' => $estado, 'socio_id' => Socio::factory()->create()->id]);
        expect($this->resolver->deuda($cuota->load('plan')))->toBe('0.00');
    }
});

test('las tarifas se suceden sin solaparse: se cierra la anterior y no se aceptan fechas viejas', function () {
    $nueva = app(RegistrarTarifaPlan::class)($this->aasr, '42000.00', CarbonImmutable::parse('2026-11-01'));

    $vigentePrevia = $this->aasr->tarifas()->where('importe', 35000)->firstOrFail();
    expect($vigentePrevia->vigencia_hasta->toDateString())->toBe('2026-10-31')
        ->and($nueva->vigencia_hasta)->toBeNull()
        ->and((float) $this->aasr->tarifaVigente(CarbonImmutable::parse('2026-10-31'))->importe)->toBe(35000.0)
        ->and((float) $this->aasr->tarifaVigente(CarbonImmutable::parse('2026-11-01'))->importe)->toBe(42000.0);

    expect(fn () => app(RegistrarTarifaPlan::class)($this->aasr, '50000.00', CarbonImmutable::parse('2026-11-01')))
        ->toThrow(ValidationException::class);
    expect(fn () => app(RegistrarTarifaPlan::class)($this->aasr, '50000.00', CarbonImmutable::parse('2026-01-01')))
        ->toThrow(ValidationException::class);
});
