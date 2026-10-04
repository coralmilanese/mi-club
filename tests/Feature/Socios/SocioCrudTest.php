<?php

use App\Enums\EstadoSocio;
use App\Models\Socio;
use App\Models\User;

beforeEach(function () {
    $this->tesorero = User::factory()->create();
});

function datosSocio(array $extra = []): array
{
    return [
        'apellido' => 'Gelos', 'nombre' => 'Gabriel', 'dni' => '22926248', 'categoria' => 'activo', 'sede' => 'planeadores',
        'email' => 'gabriel@example.com', 'fecha_asociacion' => '2024-03-01', ...$extra,
    ];
}

test('el listado muestra solo vigentes por defecto y filtra por estado y búsqueda', function () {
    Socio::factory()->create(['apellido' => 'Vigente', 'nombre' => 'Uno']);
    Socio::factory()->baja()->create(['apellido' => 'Ausente', 'nombre' => 'Dos']);

    $this->actingAs($this->tesorero)->get('/socios')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('socios/index')->has('socios.data', 1)->where('socios.data.0.nombre_completo', 'Vigente Uno'));

    $this->actingAs($this->tesorero)->get('/socios?estado=baja')
        ->assertInertia(fn ($p) => $p->has('socios.data', 1)->where('socios.data.0.nombre_completo', 'Ausente Dos'));

    $this->actingAs($this->tesorero)->get('/socios?estado=todos&q=ausente')
        ->assertInertia(fn ($p) => $p->has('socios.data', 1));
});

test('el tesorero da de alta un socio y queda su estado inicial en el historial', function () {
    $this->actingAs($this->tesorero)->post('/socios', datosSocio())->assertRedirect();

    $socio = Socio::where('dni', '22926248')->firstOrFail();
    expect($socio->estado)->toBe(EstadoSocio::Alta)
        ->and($socio->estados)->toHaveCount(1)
        ->and($socio->estados->first()->estado)->toBe(EstadoSocio::Alta);
});

test('el DNI no puede repetirse y se valida el formato', function () {
    Socio::factory()->create(['dni' => '22926248']);

    $this->actingAs($this->tesorero)->post('/socios', datosSocio())->assertSessionHasErrors('dni');
    $this->actingAs($this->tesorero)->post('/socios', datosSocio(['dni' => 'abc']))->assertSessionHasErrors('dni');
});

test('un socio puede conservar su propio DNI al editarse', function () {
    $socio = Socio::factory()->create(['dni' => '22926248']);

    $this->actingAs($this->tesorero)->put("/socios/{$socio->id}", datosSocio(['nombre' => 'Gabriel Omar']))->assertSessionHasNoErrors();

    expect($socio->fresh()->nombre)->toBe('Gabriel Omar');
});

test('el usuario de solo lectura ve pero no puede escribir', function () {
    $lector = User::factory()->lectura()->create();
    $socio = Socio::factory()->create();

    $this->actingAs($lector)->get('/socios')->assertOk();
    $this->actingAs($lector)->get("/socios/{$socio->id}")->assertOk();
    $this->actingAs($lector)->post('/socios', datosSocio())->assertForbidden();
    $this->actingAs($lector)->put("/socios/{$socio->id}", datosSocio())->assertForbidden();
    $this->actingAs($lector)->post("/socios/{$socio->id}/baja", ['fecha' => '2026-01-01', 'motivo' => 'x'])->assertForbidden();
});

test('los invitados son redirigidos al login', function () {
    $this->get('/socios')->assertRedirect('/login');
});
