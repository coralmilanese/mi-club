<?php

use App\Actions\Libro\AbrirCuenta;
use App\Actions\Libro\AnularMovimiento;
use App\Actions\Libro\RegistrarMovimiento;
use App\Enums\TipoMovimiento;
use App\Models\Cuenta;
use App\Models\LiquidacionDiaria;
use App\Models\Movimiento;
use App\Services\Libro\LiquidacionDiariaService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\LibroDeCajaSeeder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LibroDeCajaSeeder::class);
    $this->banco = Cuenta::where('nombre', 'Banco AASR')->firstOrFail();
    $this->efectivo = Cuenta::where('nombre', 'Efectivo')->firstOrFail();

    $this->ingreso = fn (string $fecha, string $importe, string $concepto = 'Cuota', ?Cuenta $c = null) => app(RegistrarMovimiento::class)(
        $c ?? $this->banco, TipoMovimiento::Ingreso, CarbonImmutable::parse($fecha), $concepto, $importe, 'Cuota Socio',
    );
    $this->egreso = fn (string $fecha, string $importe, string $concepto = 'Gasto', ?Cuenta $c = null) => app(RegistrarMovimiento::class)(
        $c ?? $this->banco, TipoMovimiento::Egreso, CarbonImmutable::parse($fecha), $concepto, $importe, 'Varios',
    );
    // Importes de los tributos del día, por concepto del resumen bancario.
    $this->tributos = fn (string $fecha, ?Cuenta $c = null) => Movimiento::where('cuenta_id', ($c ?? $this->banco)->id)->whereDate('fecha', $fecha)->where('es_tributo', true)
        ->orderBy('id')->pluck('importe', 'concepto')->map(fn ($i) => (float) $i)->all();
});

describe('cálculo puro (casos reales del Excel)', function () {
    test('08/09/2026: acreditado 95.000 → SIRCREB 2.850 (3%), crédito 570, débito 17,10', function () {
        $r = app(LiquidacionDiariaService::class)->calcular(CarbonImmutable::parse('2026-09-08'), BigDecimal::of('95000'), BigDecimal::zero());

        expect(collect($r)->mapWithKeys(fn ($x) => [$x['tributo']->codigo => $x['importe']])->all())
            ->toBe(['sircreb' => '2850.00', 'imp_credito' => '570.00', 'imp_debito' => '17.10']);
    });

    test('02/02/2026: acreditado 309.500 → SIRCREB 12.380 (4%), crédito 1.857, débito 74,28', function () {
        $r = app(LiquidacionDiariaService::class)->calcular(CarbonImmutable::parse('2026-02-02'), BigDecimal::of('309500'), BigDecimal::zero());

        expect(collect($r)->mapWithKeys(fn ($x) => [$x['tributo']->codigo => $x['importe']])->all())
            ->toBe(['sircreb' => '12380.00', 'imp_credito' => '1857.00', 'imp_debito' => '74.28']);
    });

    test('19/01/2026: el SIRCREB entra en la base del impuesto al débito → 2.520,00', function () {
        // 30.000 acreditados (SIRCREB 1.200) y 358.000 + 60.800 debitados: (418.800 + 1.200) × 0,6% = 2.520.
        $r = app(LiquidacionDiariaService::class)->calcular(CarbonImmutable::parse('2026-01-19'), BigDecimal::of('30000'), BigDecimal::of('418800'));

        expect($r[Arr::first(array_keys($r))]['importe'])->toBe('1200.00');
        $porCodigo = collect($r)->mapWithKeys(fn ($x) => [$x['tributo']->codigo => $x['importe']]);
        expect($porCodigo['imp_debito'])->toBe('2520.00')->and($porCodigo['imp_credito'])->toBe('180.00');
    });

    test('la alícuota se resuelve por la fecha del movimiento: 30/06 al 4% y 01/07 al 3%', function () {
        $servicio = app(LiquidacionDiariaService::class);
        $sircreb = fn (string $f) => $servicio->calcular(CarbonImmutable::parse($f), BigDecimal::of('100000'), BigDecimal::zero());

        expect(collect($sircreb('2026-06-30'))->first()['importe'])->toBe('4000.00')
            ->and(collect($sircreb('2026-07-01'))->first()['importe'])->toBe('3000.00');
    });
});

describe('recálculo inmediato al mover el libro', function () {
    test('un pago genera los 3 tributos del día, y un segundo pago los ACTUALIZA sin duplicar', function () {
        ($this->ingreso)('2026-02-02', '48000');
        expect(($this->tributos)('2026-02-02'))->toBe(['IMP. I.B SIRCREB' => 1920.0, 'IMP.DEB/CRED P/CRED.' => 288.0, 'IMP.DEB/CRED P/DEB.' => 11.52]);

        foreach (['30000', '60000', '90000', '51500', '30000'] as $i) {
            ($this->ingreso)('2026-02-02', $i);
        }

        expect(($this->tributos)('2026-02-02'))->toBe(['IMP. I.B SIRCREB' => 12380.0, 'IMP.DEB/CRED P/CRED.' => 1857.0, 'IMP.DEB/CRED P/DEB.' => 74.28]);
        expect(Movimiento::where('es_tributo', true)->count())->toBe(3);
        expect(LiquidacionDiaria::count())->toBe(1);
    });

    test('un egreso operativo suma a la base del débito', function () {
        ($this->ingreso)('2026-01-19', '30000');
        ($this->egreso)('2026-01-19', '358000', 'Planeadores');
        ($this->egreso)('2026-01-19', '60800', 'Federación');

        expect(($this->tributos)('2026-01-19')['IMP.DEB/CRED P/DEB.'])->toBe(2520.0);
    });

    test('los movimientos de tributo no se gravan a sí mismos (sin recursión)', function () {
        ($this->ingreso)('2026-09-08', '95000');
        $antes = ($this->tributos)('2026-09-08');

        // Forzar otro recálculo: las bases no deben cambiar porque los tributos están excluidos.
        app(LiquidacionDiariaService::class)->recalcular($this->banco, CarbonImmutable::parse('2026-09-08'));

        expect(($this->tributos)('2026-09-08'))->toBe($antes)->and(Movimiento::where('es_tributo', true)->count())->toBe(3);
    });

    test('anular un pago baja los impuestos; anular todos hace desaparecer los movimientos de tributo', function () {
        $a = ($this->ingreso)('2026-02-02', '100000');
        $b = ($this->ingreso)('2026-02-02', '50000');
        expect(($this->tributos)('2026-02-02')['IMP. I.B SIRCREB'])->toBe(6000.0);

        app(AnularMovimiento::class)($a, 'error de carga');
        expect(($this->tributos)('2026-02-02')['IMP. I.B SIRCREB'])->toBe(2000.0);

        app(AnularMovimiento::class)($b, 'error de carga');
        expect(($this->tributos)('2026-02-02'))->toBe([])->and(LiquidacionDiaria::count())->toBe(0);

        // Nada se borró: quedan los originales y sus contramovimientos, y el saldo neto es cero.
        expect(Movimiento::where('es_tributo', false)->count())->toBe(4)->and($this->banco->saldoAl())->toBe('0.00');
    });

    test('cambiarle la fecha a un movimiento recalcula las DOS fechas', function () {
        $m = ($this->ingreso)('2026-03-02', '100000');
        expect(($this->tributos)('2026-03-02')['IMP. I.B SIRCREB'])->toBe(4000.0);

        $m->update(['fecha' => '2026-03-05']);

        expect(($this->tributos)('2026-03-02'))->toBe([])
            ->and(($this->tributos)('2026-03-05')['IMP. I.B SIRCREB'])->toBe(4000.0);
    });

    test('carga retroactiva: un pago del 15/07 usa la alícuota vigente ese día (3%)', function () {
        $this->travelTo('2026-09-20');
        ($this->ingreso)('2026-07-15', '100000');

        expect(($this->tributos)('2026-07-15')['IMP. I.B SIRCREB'])->toBe(3000.0);
    });

    test('los pagos en efectivo no generan ningún tributo', function () {
        ($this->ingreso)('2026-07-10', '35000', 'Kelo Nagore julio', $this->efectivo);

        expect(Movimiento::where('es_tributo', true)->count())->toBe(0)->and(LiquidacionDiaria::count())->toBe(0);
    });

    test('la apertura de la cuenta no es una acreditación: no genera impuestos', function () {
        app(AbrirCuenta::class)($this->banco, '1884843.25', CarbonImmutable::parse('2026-01-01'));

        expect(Movimiento::where('es_tributo', true)->count())->toBe(0)->and($this->banco->saldoAl())->toBe('1884843.25');
    });

    test('la apertura se carga una sola vez por cuenta', function () {
        app(AbrirCuenta::class)($this->banco, '100', CarbonImmutable::parse('2026-01-01'));

        expect(fn () => app(AbrirCuenta::class)($this->banco, '200', CarbonImmutable::parse('2026-01-01')))->toThrow(ValidationException::class);
    });
});

describe('ajuste manual y fecha de corte', function () {
    test('con ajuste manual el recálculo automático deja de tocar el día', function () {
        ($this->ingreso)('2026-02-02', '100000');
        $liq = app(LiquidacionDiariaService::class)->ajustar($this->banco, CarbonImmutable::parse('2026-02-02'), [
            'sircreb' => '3990.55', 'imp_credito' => '600.00', 'imp_debito' => '23.94',
        ], 'El banco cobró de más');

        expect($liq->ajustada_manualmente)->toBeTrue()->and($liq->motivo_ajuste)->toBe('El banco cobró de más');
        expect(($this->tributos)('2026-02-02')['IMP. I.B SIRCREB'])->toBe(3990.55);

        ($this->ingreso)('2026-02-02', '50000'); // no debe reescribir el día ajustado
        expect(($this->tributos)('2026-02-02')['IMP. I.B SIRCREB'])->toBe(3990.55);
    });

    test('restablecer devuelve el día al cálculo automático', function () {
        ($this->ingreso)('2026-02-02', '100000');
        app(LiquidacionDiariaService::class)->ajustar($this->banco, CarbonImmutable::parse('2026-02-02'), ['sircreb' => '1'], 'prueba');

        app(LiquidacionDiariaService::class)->restablecer($this->banco, CarbonImmutable::parse('2026-02-02'));

        expect(($this->tributos)('2026-02-02')['IMP. I.B SIRCREB'])->toBe(4000.0)
            ->and(LiquidacionDiaria::first()->ajustada_manualmente)->toBeFalse();
    });

    test('los días anteriores a la fecha de corte no se recalculan', function () {
        config(['aasr.tributos.corte_recalculo_automatico' => '2026-10-01']);

        ($this->ingreso)('2026-09-08', '95000');
        expect(($this->tributos)('2026-09-08'))->toBe([]);

        ($this->ingreso)('2026-10-01', '95000');
        expect(($this->tributos)('2026-10-01'))->toHaveCount(3);
    });

    test('un ajuste manual con todos los importes en cero no deja movimientos', function () {
        ($this->ingreso)('2026-02-02', '100000');
        app(LiquidacionDiariaService::class)->ajustar($this->banco, CarbonImmutable::parse('2026-02-02'), [], 'Día exento');

        expect(($this->tributos)('2026-02-02'))->toBe([]);
    });
});

describe('saldo y anulación', function () {
    test('el saldo neto de una cuenta es ingresos − egresos, incluidos los impuestos', function () {
        ($this->ingreso)('2026-09-08', '95000');

        // 95.000 − 2.850 − 570 − 17,10
        expect($this->banco->saldoAl())->toBe('91562.90');
    });

    test('no se puede anular un impuesto ni anular dos veces', function () {
        $m = ($this->ingreso)('2026-02-02', '1000');
        $tributo = Movimiento::where('es_tributo', true)->firstOrFail();

        expect(fn () => app(AnularMovimiento::class)($tributo, 'x'))->toThrow(ValidationException::class);

        app(AnularMovimiento::class)($m, 'x');
        expect(fn () => app(AnularMovimiento::class)($m->fresh(), 'x'))->toThrow(ValidationException::class);
    });

    test('importes cero o negativos y cuentas inactivas se rechazan', function () {
        expect(fn () => ($this->ingreso)('2026-02-02', '0'))->toThrow(ValidationException::class);
        expect(fn () => ($this->ingreso)('2026-02-02', '-5'))->toThrow(ValidationException::class);

        $this->banco->update(['activa' => false]);
        expect(fn () => ($this->ingreso)('2026-02-02', '100'))->toThrow(ValidationException::class);
    });
});
