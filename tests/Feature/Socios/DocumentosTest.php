<?php

use App\Models\Socio;
use App\Models\TipoDocumento;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('comprobantes');
    $this->tesorero = User::factory()->create();
    $this->socio = Socio::factory()->create();
    $this->foto = TipoDocumento::create(['codigo' => 'foto', 'nombre' => 'Foto', 'obligatorio' => true]);
    $this->planilla = TipoDocumento::create(['codigo' => 'planilla', 'nombre' => 'Planilla', 'obligatorio' => true]);
    TipoDocumento::create(['codigo' => 'dni', 'nombre' => 'DNI', 'obligatorio' => false]);
});

test('el checklist se calcula contra los tipos y marca lo entregado', function () {
    $this->socio->documentos()->create(['tipo_documento_id' => $this->foto->id]);

    $this->actingAs($this->tesorero)->get("/socios/{$this->socio->id}")
        ->assertInertia(fn ($p) => $p
            ->has('checklist', 3)
            ->where('checklist', fn ($c) => collect($c)->firstWhere('nombre', 'Foto')['entregado'] === true
                && collect($c)->firstWhere('nombre', 'Planilla')['entregado'] === false));
});

test('se sube un documento al disco de comprobantes', function () {
    $archivo = UploadedFile::fake()->image('foto.jpg');

    $this->actingAs($this->tesorero)
        ->post("/socios/{$this->socio->id}/documentos", ['tipo_documento_id' => $this->foto->id, 'archivo' => $archivo])
        ->assertSessionHasNoErrors();

    $doc = $this->socio->documentos()->firstOrFail();
    expect($doc->disco)->toBe('comprobantes')->and($doc->nombre_original)->toBe('foto.jpg');
    Storage::disk('comprobantes')->assertExists($doc->archivo_path);
});

test('se rechazan archivos que no son imagen o pdf', function () {
    $this->actingAs($this->tesorero)
        ->post("/socios/{$this->socio->id}/documentos", ['tipo_documento_id' => $this->foto->id, 'archivo' => UploadedFile::fake()->create('virus.exe', 10)])
        ->assertSessionHasErrors('archivo');
});

test('eliminar el documento borra también el archivo', function () {
    $this->actingAs($this->tesorero)->post("/socios/{$this->socio->id}/documentos", ['tipo_documento_id' => $this->foto->id, 'archivo' => UploadedFile::fake()->image('f.png')]);
    $doc = $this->socio->documentos()->firstOrFail();

    $this->actingAs($this->tesorero)->delete("/socios/{$this->socio->id}/documentos/{$doc->id}")->assertRedirect();

    expect($this->socio->documentos()->count())->toBe(0);
    Storage::disk('comprobantes')->assertMissing($doc->archivo_path);
});

test('no se puede bajar el documento de otro socio', function () {
    $this->actingAs($this->tesorero)->post("/socios/{$this->socio->id}/documentos", ['tipo_documento_id' => $this->foto->id, 'archivo' => UploadedFile::fake()->image('f.png')]);
    $doc = $this->socio->documentos()->firstOrFail();
    $otro = Socio::factory()->create();

    $this->actingAs($this->tesorero)->get("/socios/{$otro->id}/documentos/{$doc->id}")->assertNotFound();
    $this->actingAs($this->tesorero)->get("/socios/{$this->socio->id}/documentos/{$doc->id}")->assertOk();
});

test('un tipo con documentos cargados no se puede borrar', function () {
    $this->socio->documentos()->create(['tipo_documento_id' => $this->foto->id]);

    $this->actingAs($this->tesorero)->delete("/configuracion/tipos-documento/{$this->foto->id}")->assertSessionHasErrors('tipo');
    expect(TipoDocumento::whereKey($this->foto->id)->exists())->toBeTrue();
});
