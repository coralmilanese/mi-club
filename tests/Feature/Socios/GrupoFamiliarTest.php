<?php

use App\Models\GrupoFamiliar;
use App\Models\Socio;
use App\Models\User;

beforeEach(function () {
    $this->tesorero = User::factory()->create();
});

test('se crea un grupo con titular y se agregan y quitan adherentes', function () {
    [$titular, $hijo] = Socio::factory()->count(2)->create();

    $this->actingAs($this->tesorero)->post('/grupos-familiares', ['titular_socio_id' => $titular->id])->assertSessionHasNoErrors();
    $grupo = GrupoFamiliar::firstOrFail();
    expect($grupo->titular_socio_id)->toBe($titular->id)->and($titular->fresh()->grupo_familiar_id)->toBe($grupo->id);

    $this->actingAs($this->tesorero)->post("/grupos-familiares/{$grupo->id}/miembros", ['socio_id' => $hijo->id]);
    expect($grupo->adherentes)->toHaveCount(1);

    $this->actingAs($this->tesorero)->delete("/grupos-familiares/{$grupo->id}/miembros/{$hijo->id}");
    expect($grupo->fresh()->adherentes)->toHaveCount(0);
});

test('un socio no puede estar en dos grupos ni se puede quitar al titular', function () {
    [$titular, $otro] = Socio::factory()->count(2)->create();
    $this->actingAs($this->tesorero)->post('/grupos-familiares', ['titular_socio_id' => $titular->id]);
    $grupo = GrupoFamiliar::firstOrFail();

    $this->actingAs($this->tesorero)->post('/grupos-familiares', ['titular_socio_id' => $titular->id])->assertSessionHasErrors('titular_socio_id');
    $this->actingAs($this->tesorero)->delete("/grupos-familiares/{$grupo->id}/miembros/{$titular->id}")->assertSessionHasErrors('socio_id');

    $this->actingAs($this->tesorero)->post("/grupos-familiares/{$grupo->id}/miembros", ['socio_id' => $otro->id]);
    $this->actingAs($this->tesorero)->post("/grupos-familiares/{$grupo->id}/miembros", ['socio_id' => $otro->id])->assertSessionHasErrors('socio_id');
});

test('disolver el grupo libera a todos los miembros', function () {
    $titular = Socio::factory()->create();
    $this->actingAs($this->tesorero)->post('/grupos-familiares', ['titular_socio_id' => $titular->id]);
    $grupo = GrupoFamiliar::firstOrFail();

    $this->actingAs($this->tesorero)->delete("/grupos-familiares/{$grupo->id}")->assertRedirect();

    expect(GrupoFamiliar::count())->toBe(0)->and($titular->fresh()->grupo_familiar_id)->toBeNull();
});
