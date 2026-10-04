<?php

use App\Models\Cuota;
use App\Models\GrupoFamiliar;
use App\Models\Plan;
use App\Models\Socio;
use App\Models\User;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->tesorero = User::factory()->create();
});

test('se crea un plan configurable con código válido y único', function () {
    $this->actingAs($this->tesorero)->post('/planes', ['tipo_plan' => 'socio_cadete', 'nombre' => 'Socio cadete', 'genera_cuota' => true])->assertSessionHasNoErrors();
    expect(Plan::where('tipo_plan', 'socio_cadete')->exists())->toBeTrue();

    $this->actingAs($this->tesorero)->post('/planes', ['tipo_plan' => 'socio_cadete', 'nombre' => 'Otro'])->assertSessionHasErrors('tipo_plan');
    $this->actingAs($this->tesorero)->post('/planes', ['tipo_plan' => 'Con Espacios', 'nombre' => 'X'])->assertSessionHasErrors('tipo_plan');
});

test('una nueva tarifa se sucede a la anterior y no se acepta una fecha vieja', function () {
    $aasr = Plan::where('tipo_plan', 'aasr')->firstOrFail();

    $this->actingAs($this->tesorero)->post("/planes/{$aasr->id}/tarifas", ['importe' => '40000', 'vigencia_desde' => '2026-11-01'])->assertSessionHasNoErrors();
    expect((float) $aasr->tarifas()->first()->importe)->toBe(40000.0);

    $this->actingAs($this->tesorero)->post("/planes/{$aasr->id}/tarifas", ['importe' => '45000', 'vigencia_desde' => '2026-01-01'])->assertSessionHasErrors('vigencia_desde');
    $this->actingAs($this->tesorero)->post("/planes/{$aasr->id}/tarifas", ['importe' => '-5', 'vigencia_desde' => '2027-01-01'])->assertSessionHasErrors('importe');
});

test('la asignación masiva es todo o nada', function () {
    [$a, $b] = Socio::factory()->count(2)->create();
    $aasr = Plan::where('tipo_plan', 'aasr')->firstOrFail();
    $b->planes()->create(['plan_id' => $aasr->id, 'desde' => '2026-01-01']);

    // $b ya tiene ese plan: falla y $a tampoco debe quedar asignado.
    $this->actingAs($this->tesorero)
        ->post('/planes/asignacion-masiva', ['socio_ids' => [$a->id, $b->id], 'plan_id' => $aasr->id, 'desde' => '2026-09-01'])
        ->assertSessionHasErrors('plan_id');
    expect($a->planes()->count())->toBe(0);

    $this->actingAs($this->tesorero)
        ->post('/planes/asignacion-masiva', ['socio_ids' => [$a->id], 'plan_id' => $aasr->id, 'desde' => '2026-09-01'])
        ->assertRedirect('/planes');
    expect($a->planes()->count())->toBe(1);
});

test('devengar desde la UI simula sin escribir y luego crea las cuotas', function () {
    $socio = Socio::factory()->create();
    $socio->planes()->create(['plan_id' => Plan::where('tipo_plan', 'aasr')->value('id'), 'desde' => '2026-01-01']);

    $this->actingAs($this->tesorero)->post('/cuotas/devengar', ['periodo' => '2026-09', 'simular' => true])->assertSessionHas('success');
    expect(Cuota::count())->toBe(0);

    $this->actingAs($this->tesorero)->post('/cuotas/devengar', ['periodo' => '2026-09'])->assertSessionHas('success');
    expect(Cuota::count())->toBe(1);

    $this->actingAs($this->tesorero)->post('/cuotas/devengar', ['periodo' => '2026-9'])->assertSessionHasErrors('periodo');
});

test('la grilla anual y la ficha muestran cuotas y deuda a valores de hoy', function () {
    $socio = Socio::factory()->create(['apellido' => 'Branda', 'nombre' => 'Ernesto']);
    $plan = Plan::where('tipo_plan', 'aasr')->firstOrFail();
    $socio->planes()->create(['plan_id' => $plan->id, 'desde' => '2026-01-01']);
    foreach (['2026-04-01', '2026-05-01', '2026-06-01'] as $periodo) {
        Cuota::factory()->create(['socio_id' => $socio->id, 'plan_id' => $plan->id, 'periodo' => $periodo, 'importe_devengado' => '30000.00']);
    }
    $this->travelTo('2026-09-08');

    $this->actingAs($this->tesorero)->get('/cuotas?anio=2026')
        ->assertInertia(fn ($p) => $p->component('cuotas/grilla')->where('filas.0.deuda', '105000.00')->where('filas.0.meses.3.estado', 'pendiente')->where('filas.0.meses.0', null));

    $this->actingAs($this->tesorero)->get("/socios/{$socio->id}")
        ->assertInertia(fn ($p) => $p->where('deuda_total', '105000.00')->has('cuotas', 3)->where('plan_actual.plan_id', $plan->id));
});

test('una celda parcial de la grilla trae el detalle de por qué, y las demás no', function () {
    $socio = Socio::factory()->create();
    $plan = Plan::where('tipo_plan', 'aasr')->firstOrFail();
    $socio->planes()->create(['plan_id' => $plan->id, 'desde' => '2026-01-01']);
    Cuota::factory()->create(['socio_id' => $socio->id, 'plan_id' => $plan->id, 'periodo' => '2026-04-01', 'importe_devengado' => '30000.00', 'estado' => 'parcial', 'importe_imputado' => '10000.00']);
    Cuota::factory()->create(['socio_id' => $socio->id, 'plan_id' => $plan->id, 'periodo' => '2026-05-01', 'importe_devengado' => '30000.00']);
    $this->travelTo('2026-09-08'); // tarifa de hoy: 35.000

    $this->actingAs($this->tesorero)->get('/cuotas?anio=2026')->assertInertia(fn ($p) => $p
        ->where('filas.0.meses.3.imputado', '10000.00')
        ->where('filas.0.meses.3.exigible', '35000.00')
        ->where('filas.0.meses.3.falta', '25000.00')
        // La celda muestra lo que falta pagar (25.000), no lo devengado (30.000).
        ->where('filas.0.meses.3.importe', '25000.00')
        ->where('filas.0.meses.4.estado', 'pendiente')
        ->where('filas.0.meses.4.importe', '30000.00')
        ->missing('filas.0.meses.4.imputado'));
});

test('la grilla mantiene juntos a los grupos familiares (titular y después sus adherentes), como en el Excel', function () {
    $plan = Plan::where('tipo_plan', 'aasr')->firstOrFail();
    $adicional = Plan::where('tipo_plan', 'adicional_familiar')->firstOrFail();

    // El adherente "Zuloaga" ordenaría alfabéticamente último; acá tiene que quedar pegado a su titular "Alfajeme" (primero).
    $alfajeme = Socio::factory()->create(['apellido' => 'Alfajeme', 'nombre' => 'Matias']);
    $zuloagaAdherente = Socio::factory()->create(['apellido' => 'Zuloaga', 'nombre' => 'Hijo']);
    $grupo = GrupoFamiliar::create(['nombre' => 'Familia Alfajeme', 'titular_socio_id' => $alfajeme->id]);
    $alfajeme->update(['grupo_familiar_id' => $grupo->id]);
    $zuloagaAdherente->update(['grupo_familiar_id' => $grupo->id]);

    // El adherente "Bautista" ordenaría alfabéticamente segundo; acá tiene que quedar pegado a su titular "Giuliani".
    $ezequiel = Socio::factory()->create(['apellido' => 'Giuliani', 'nombre' => 'Ezequiel']);
    $bautistaAdherente = Socio::factory()->create(['apellido' => 'Bautista', 'nombre' => 'Hijo']);
    $grupo2 = GrupoFamiliar::create(['nombre' => 'Familia Giuliani', 'titular_socio_id' => $ezequiel->id]);
    $ezequiel->update(['grupo_familiar_id' => $grupo2->id]);
    $bautistaAdherente->update(['grupo_familiar_id' => $grupo2->id]);

    $sinGrupo = Socio::factory()->create(['apellido' => 'Pereyra', 'nombre' => 'Jose']);

    foreach ([[$alfajeme, $plan], [$zuloagaAdherente, $adicional], [$ezequiel, $plan], [$bautistaAdherente, $adicional], [$sinGrupo, $plan]] as [$s, $p]) {
        Cuota::factory()->create(['socio_id' => $s->id, 'plan_id' => $p->id, 'periodo' => '2026-01-01']);
    }

    // Orden esperado: Alfajeme (titular) + Zuloaga (su adherente) van primero; Giuliani + Bautista (su adherente) después; Pereyra al final.
    $this->actingAs($this->tesorero)->get('/cuotas?anio=2026')->assertInertia(fn ($p) => $p
        ->where('filas.0.socio', 'Alfajeme Matias')
        ->where('filas.1.socio', 'Zuloaga Hijo')
        ->where('filas.2.socio', 'Giuliani Ezequiel')
        ->where('filas.3.socio', 'Bautista Hijo')
        ->where('filas.4.socio', 'Pereyra Jose'));
});

test('el usuario de solo lectura puede ver planes y cuotas pero no modificar', function () {
    $lector = User::factory()->lectura()->create();
    $aasr = Plan::where('tipo_plan', 'aasr')->firstOrFail();

    $this->actingAs($lector)->get('/planes')->assertOk();
    $this->actingAs($lector)->get('/cuotas')->assertOk();
    $this->actingAs($lector)->post("/planes/{$aasr->id}/tarifas", ['importe' => '1', 'vigencia_desde' => '2030-01-01'])->assertForbidden();
    $this->actingAs($lector)->post('/cuotas/devengar', ['periodo' => '2026-09'])->assertForbidden();
});
