<?php

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'tesorero'])->get('/_solo-tesorero', fn () => 'ok');
});

test('el tesorero accede a rutas protegidas por rol', function () {
    $this->actingAs(User::factory()->create())->get('/_solo-tesorero')->assertOk();
});

test('el usuario de solo lectura recibe 403', function () {
    $user = User::factory()->lectura()->create();

    expect($user->rol)->toBe(Rol::Lectura);
    $this->actingAs($user)->get('/_solo-tesorero')->assertForbidden();
});

test('no existe registro público', function () {
    $this->get('/register')->assertNotFound();
});

test('la raíz redirige al panel', function () {
    $this->get('/')->assertRedirect('/dashboard');
});
