<?php

use App\Enums\EstadoSocio;
use App\Models\GrupoFamiliar;
use App\Models\Socio;
use App\Models\User;

beforeEach(function () {
    $this->tesorero = User::factory()->create();
});

test('dar de baja cierra el tramo vigente y no borra nada', function () {
    $socio = Socio::factory()->create();
    $socio->estados()->create(['estado' => EstadoSocio::Activo, 'desde' => '2024-01-01']);

    $this->actingAs($this->tesorero)
        ->post("/socios/{$socio->id}/baja", ['fecha' => '2026-08-01', 'motivo' => 'Se mudó'])
        ->assertRedirect();

    $socio->refresh();
    expect($socio->estado)->toBe(EstadoSocio::Baja)
        ->and($socio->fecha_baja->toDateString())->toBe('2026-08-01')
        ->and($socio->motivo_baja)->toBe('Se mudó')
        ->and($socio->estados)->toHaveCount(2)
        ->and($socio->estados->first()->hasta->toDateString())->toBe('2026-08-01')
        ->and($socio->estados->last()->hasta)->toBeNull();
});

test('el reingreso reactiva al socio y conserva la baja en el historial', function () {
    $socio = Socio::factory()->create();
    $this->actingAs($this->tesorero)->post("/socios/{$socio->id}/baja", ['fecha' => '2026-01-10', 'motivo' => 'Licencia']);
    $this->actingAs($this->tesorero)->post("/socios/{$socio->id}/reingreso", ['fecha' => '2026-06-01'])->assertRedirect();

    $socio->refresh();
    expect($socio->estado)->toBe(EstadoSocio::Activo)
        ->and($socio->fecha_baja)->toBeNull()
        ->and($socio->estados->pluck('estado')->all())->toBe([EstadoSocio::Baja, EstadoSocio::Activo]);
});

test('no se puede dar de baja dos veces ni reingresar a un vigente', function () {
    $vigente = Socio::factory()->create();
    $baja = Socio::factory()->baja()->create();

    $this->actingAs($this->tesorero)->post("/socios/{$vigente->id}/reingreso", ['fecha' => '2026-06-01'])->assertSessionHasErrors('fecha');
    $this->actingAs($this->tesorero)->post("/socios/{$baja->id}/baja", ['fecha' => '2026-06-01', 'motivo' => 'x'])->assertSessionHasErrors('fecha');
});

test('un titular con adherentes no puede darse de baja', function () {
    $titular = Socio::factory()->create();
    $adherente = Socio::factory()->create();
    $grupo = GrupoFamiliar::create(['nombre' => 'Familia', 'titular_socio_id' => $titular->id]);
    $titular->update(['grupo_familiar_id' => $grupo->id]);
    $adherente->update(['grupo_familiar_id' => $grupo->id]);

    $this->actingAs($this->tesorero)->post("/socios/{$titular->id}/baja", ['fecha' => '2026-06-01', 'motivo' => 'x'])->assertSessionHasErrors('fecha');

    expect($titular->fresh()->estado)->toBe(EstadoSocio::Activo);
});

test('la fecha de baja no puede ser futura y el motivo es obligatorio', function () {
    $socio = Socio::factory()->create();

    $this->actingAs($this->tesorero)->post("/socios/{$socio->id}/baja", ['fecha' => now()->addDay()->toDateString(), 'motivo' => ''])
        ->assertSessionHasErrors(['fecha', 'motivo']);
});
