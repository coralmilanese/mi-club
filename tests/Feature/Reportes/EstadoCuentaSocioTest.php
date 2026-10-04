<?php

use App\Enums\EstadoCuota;
use App\Models\Cuota;
use App\Models\Plan;
use App\Models\Socio;
use App\Models\User;
use App\Services\Reportes\EstadoCuentaSocio;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->tesorero = User::factory()->create();
    $this->travelTo('2026-09-20'); // tarifa de hoy: 35.000
});

test('los 12 meses aparecen, marcados pagada/parcial/pendiente/sin devengar según corresponda', function () {
    $socio = Socio::factory()->create();
    $plan = Plan::where('tipo_plan', 'aasr')->firstOrFail();

    Cuota::factory()->create(['socio_id' => $socio->id, 'plan_id' => $plan->id, 'periodo' => '2026-01-01', 'importe_devengado' => '30000.00', 'estado' => EstadoCuota::Pagada->value, 'importe_cobrado' => '30000.00', 'importe_imputado' => '30000.00']);
    Cuota::factory()->create(['socio_id' => $socio->id, 'plan_id' => $plan->id, 'periodo' => '2026-02-01', 'importe_devengado' => '30000.00', 'estado' => EstadoCuota::Parcial->value, 'importe_imputado' => '10000.00']);
    Cuota::factory()->create(['socio_id' => $socio->id, 'plan_id' => $plan->id, 'periodo' => '2026-03-01', 'importe_devengado' => '30000.00']);
    // Abril a diciembre: sin cuota (socio se asoció en enero y no se sigue devengando en este test).

    $datos = app(EstadoCuentaSocio::class)->datos($socio, 2026);

    expect($datos['meses'])->toHaveCount(12)
        ->and($datos['meses'][0])->toMatchArray(['mes' => 'Enero', 'estado' => 'pagada', 'importe' => '30000.00', 'debe' => '0.00'])
        ->and($datos['meses'][1])->toMatchArray(['mes' => 'Febrero', 'estado' => 'parcial', 'importe' => '35000.00', 'debe' => '25000.00'])
        ->and($datos['meses'][2])->toMatchArray(['mes' => 'Marzo', 'estado' => 'pendiente', 'importe' => '35000.00', 'debe' => '35000.00'])
        ->and($datos['meses'][3])->toMatchArray(['mes' => 'Abril', 'estado' => null, 'estado_label' => 'Sin devengar', 'importe' => null, 'debe' => '0.00']);

    expect($datos['total_pagado'])->toBe('40000.00')->and($datos['total_deuda'])->toBe('60000.00');
});

test('marca cuando la cuota la paga el titular del grupo familiar', function () {
    $titular = Socio::factory()->create();
    $adherente = Socio::factory()->create();
    Cuota::factory()->create(['socio_id' => $adherente->id, 'plan_id' => Plan::where('tipo_plan', 'adicional_familiar')->value('id'), 'periodo' => '2026-05-01', 'importe_devengado' => '22500.00', 'socio_pagador_id' => $titular->id]);

    $datos = app(EstadoCuentaSocio::class)->datos($adherente, 2026);

    expect($datos['meses'][4]['pagador'])->toBeTrue();
});

test('genera un PDF real y descargable desde la ficha del socio, visible para cualquier rol', function () {
    $socio = Socio::factory()->create();
    $lector = User::factory()->lectura()->create();

    foreach ([$this->tesorero, $lector] as $user) {
        $r = $this->actingAs($user)->get("/socios/{$socio->id}/estado-cuenta?anio=2026");
        $r->assertOk()->assertHeader('content-type', 'application/pdf');
        expect(substr($r->getContent(), 0, 4))->toBe('%PDF');
    }
});

test('los invitados no pueden descargar el estado de cuenta', function () {
    $socio = Socio::factory()->create();
    $this->get("/socios/{$socio->id}/estado-cuenta")->assertRedirect('/login');
});
