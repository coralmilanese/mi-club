<?php

use App\Actions\Cuotas\DevengarCuotas;
use App\Actions\Planes\AsignarPlanSocio;
use App\Actions\Socios\DarDeBajaSocio;
use App\Enums\CategoriaSocio;
use App\Models\Cuota;
use App\Models\GrupoFamiliar;
use App\Models\Plan;
use App\Models\Socio;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->plan = fn (string $tipo) => Plan::where('tipo_plan', $tipo)->firstOrFail();
    $this->asignar = fn (Socio $s, string $tipo, string $desde = '2026-01-01') => app(AsignarPlanSocio::class)($s, ($this->plan)($tipo), CarbonImmutable::parse($desde));
    $this->devengar = fn (string $periodo, bool $dry = false) => app(DevengarCuotas::class)(CarbonImmutable::parse($periodo), $dry);
});

test('un titular con un adherente paga 57.500 y con dos, 80.000 (septiembre 2026)', function () {
    $cepeda = Socio::factory()->create(['apellido' => 'Cepeda', 'nombre' => 'Agustin']);
    $milo = Socio::factory()->create(['apellido' => 'Cepeda', 'nombre' => 'Milo']);
    $ezequiel = Socio::factory()->create(['apellido' => 'Giuliani', 'nombre' => 'Ezequiel']);
    $polo = Socio::factory()->create(['apellido' => 'Giuliani', 'nombre' => 'Polo']);
    $anabela = Socio::factory()->create(['apellido' => 'Gonzalez', 'nombre' => 'Anabela']);

    foreach ([[$cepeda, [$milo]], [$ezequiel, [$polo, $anabela]]] as [$titular, $adherentes]) {
        $grupo = GrupoFamiliar::create(['nombre' => 'Familia', 'titular_socio_id' => $titular->id]);
        $titular->update(['grupo_familiar_id' => $grupo->id]);
        ($this->asignar)($titular, 'aasr');
        foreach ($adherentes as $a) {
            $a->update(['grupo_familiar_id' => $grupo->id]);
            ($this->asignar)($a, 'adicional_familiar');
        }
    }

    $r = ($this->devengar)('2026-09-01');

    expect($r['creadas'])->toBe(5);
    $totalPorPagador = fn (Socio $t) => Cuota::where(fn ($q) => $q->where('socio_id', $t->id)->whereNull('socio_pagador_id')->orWhere('socio_pagador_id', $t->id))->sum('importe_devengado');
    expect((float) $totalPorPagador($cepeda))->toBe(57500.0)
        ->and((float) $totalPorPagador($ezequiel))->toBe(80000.0);

    expect(Cuota::where('socio_id', $milo->id)->firstOrFail()->socio_pagador_id)->toBe($cepeda->id);
});

test('el honorario no genera cuota y el vitalicio paga su tarifa reducida', function () {
    $ripa = Socio::factory()->categoria(CategoriaSocio::Honorario)->create();
    $musso = Socio::factory()->categoria(CategoriaSocio::Vitalicio)->create();
    ($this->asignar)($ripa, 'honorario');
    ($this->asignar)($musso, 'vitalicio');

    $r = ($this->devengar)('2026-09-01');

    expect($r['creadas'])->toBe(1)
        ->and(Cuota::where('socio_id', $ripa->id)->exists())->toBeFalse()
        ->and((float) Cuota::where('socio_id', $musso->id)->value('importe_devengado'))->toBe(22500.0);
});

test('la tarifa devengada es la vigente al primer día del periodo', function () {
    $socio = Socio::factory()->create();
    ($this->asignar)($socio, 'aasr');

    ($this->devengar)('2026-06-01'); // la nueva tarifa arranca el 26/06
    ($this->devengar)('2026-07-01');

    $importes = Cuota::where('socio_id', $socio->id)->orderBy('periodo')->pluck('importe_devengado')->map(fn ($i) => (float) $i)->all();
    expect($importes)->toBe([30000.0, 35000.0]);
});

test('es idempotente y el dry-run no escribe', function () {
    $socio = Socio::factory()->create();
    ($this->asignar)($socio, 'aasr');

    $sim = ($this->devengar)('2026-09-01', true);
    expect($sim['creadas'])->toBe(1)->and(Cuota::count())->toBe(0);

    ($this->devengar)('2026-09-01');
    $segunda = ($this->devengar)('2026-09-01');

    expect(Cuota::count())->toBe(1)->and($segunda['creadas'])->toBe(0)->and($segunda['existentes'])->toBe(1);
});

test('no devenga antes del alta del plan ni después de la baja', function () {
    $socio = Socio::factory()->create();
    ($this->asignar)($socio, 'aasr', '2026-03-10');

    expect(($this->devengar)('2026-02-01')['creadas'])->toBe(0);
    expect(($this->devengar)('2026-03-01')['creadas'])->toBe(1); // entra a mitad de mes: paga marzo

    app(DarDeBajaSocio::class)($socio, CarbonImmutable::parse('2026-06-01'), 'Se fue');

    expect(($this->devengar)('2026-05-01')['creadas'])->toBe(1);
    expect(($this->devengar)('2026-06-01')['creadas'])->toBe(0);
    expect(($this->devengar)('2026-07-01')['creadas'])->toBe(0);
});

test('un plan sin tarifa vigente no se devenga y avisa', function () {
    $socio = Socio::factory()->create();
    ($this->asignar)($socio, 'aasr', '2024-01-01');

    $r = ($this->devengar)('2025-01-01'); // antes de la primera vigencia (26/09/2025)

    expect($r['creadas'])->toBe(0)->and($r['advertencias'])->toHaveCount(1);
});

test('el comando soporta devengamiento retroactivo y valida el formato', function () {
    $socio = Socio::factory()->create();
    ($this->asignar)($socio, 'aasr', '2026-01-01');

    $this->artisan('cuotas:devengar', ['--desde' => '2026-01', '--hasta' => '2026-09'])->assertSuccessful();
    expect(Cuota::where('socio_id', $socio->id)->count())->toBe(9);

    $this->artisan('cuotas:devengar', ['--periodo' => '2026-13'])->assertFailed();
    $this->artisan('cuotas:devengar', ['--desde' => '2026-05', '--hasta' => '2026-01'])->assertFailed();
});

test('asignar un plan cierra el tramo anterior y rechaza fechas inconsistentes', function () {
    $socio = Socio::factory()->create();
    ($this->asignar)($socio, 'aasr', '2026-01-01');
    ($this->asignar)($socio, 'planeadores', '2026-05-01');

    $tramos = $socio->planes()->orderBy('desde')->get();
    expect($tramos)->toHaveCount(2)->and($tramos[0]->hasta->toDateString())->toBe('2026-04-30')->and($tramos[1]->hasta)->toBeNull();

    expect(fn () => ($this->asignar)($socio, 'aasr', '2026-05-01'))->toThrow(ValidationException::class);
    expect(fn () => ($this->asignar)($socio, 'planeadores', '2026-08-01'))->toThrow(ValidationException::class);
});
