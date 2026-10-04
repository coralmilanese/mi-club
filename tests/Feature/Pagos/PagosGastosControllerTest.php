<?php

use App\Actions\Cuotas\DevengarCuotas;
use App\Actions\Planes\AsignarPlanSocio;
use App\Enums\EstadoCuota;
use App\Models\CategoriaGasto;
use App\Models\Comprobante;
use App\Models\Cuenta;
use App\Models\Cuota;
use App\Models\Gasto;
use App\Models\MedioPago;
use App\Models\Movimiento;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\Socio;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriaGastoSeeder;
use Database\Seeders\LibroDeCajaSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('comprobantes');
    $this->seed([PlanSeeder::class, LibroDeCajaSeeder::class, CategoriaGastoSeeder::class]);
    $this->tesorero = User::factory()->create();
    $this->banco = Cuenta::where('nombre', 'Banco AASR')->firstOrFail();
    $this->fer = Cuenta::where('nombre', 'Efectivo')->firstOrFail();
    $this->transferencia = MedioPago::where('codigo', 'transferencia')->firstOrFail();
    $this->efectivo = MedioPago::where('codigo', 'efectivo')->firstOrFail();

    $this->socio = Socio::factory()->create(['apellido' => 'Gelos', 'nombre' => 'Gabriel']);
    app(AsignarPlanSocio::class)($this->socio, Plan::where('tipo_plan', 'aasr')->firstOrFail(), CarbonImmutable::parse('2026-08-01'));
    foreach (['2026-08-01', '2026-09-01'] as $m) {
        app(DevengarCuotas::class)(CarbonImmutable::parse($m));
    }
    $this->travelTo('2026-09-20');
    $this->datos = fn (array $extra = []) => [
        'socio_id' => $this->socio->id, 'fecha' => '2026-09-08', 'importe' => '70000', 'medio_pago_id' => $this->transferencia->id, 'cuenta_id' => $this->banco->id, ...$extra,
    ];
});

test('la pantalla de pago propone la imputación a la tarifa vigente de la fecha', function () {
    $this->actingAs($this->tesorero)->get("/pagos/create?socio_id={$this->socio->id}&fecha=2026-09-08&importe=70000")
        ->assertInertia(fn ($p) => $p->component('pagos/create')
            ->has('adeudadas', 2)->where('adeudadas.0.deuda', '35000.00')
            ->where('propuesta.exacto', true)->where('propuesta.sobrante', '0.00')->has('propuesta.imputaciones', 2));

    $this->actingAs($this->tesorero)->get("/pagos/create?socio_id={$this->socio->id}&importe=100000")
        ->assertInertia(fn ($p) => $p->where('propuesta.sobrante', '30000.00')->where('propuesta.exacto', false));
});

test('registra el pago con comprobante, imputa y redirige a la ficha del socio', function () {
    $this->actingAs($this->tesorero)
        ->post('/pagos', ($this->datos)(['comprobante' => UploadedFile::fake()->image('transferencia.jpg'), 'referencia_externa' => 'OP-1']))
        ->assertRedirect("/socios/{$this->socio->id}")->assertSessionHas('success');

    $pago = Pago::firstOrFail();
    expect($pago->fecha->toDateString())->toBe('2026-09-08')
        ->and($pago->comprobante)->not->toBeNull()
        ->and($pago->concepto)->toBe('Gelos Gabriel agosto, septiembre')
        ->and(Cuota::where('estado', EstadoCuota::Pagada->value)->count())->toBe(2);
    Storage::disk('comprobantes')->assertExists($pago->comprobante->archivo_path);

    $this->actingAs($this->tesorero)->get("/socios/{$this->socio->id}")
        ->assertInertia(fn ($p) => $p->has('pagos', 1)->where('pagos.0.comprobante_url', fn ($u) => str_contains($u, '/comprobantes/'))->where('deuda_total', '0.00'));
});

test('el mismo comprobante (mismo archivo) no se puede cargar dos veces', function () {
    $archivo = fn () => UploadedFile::fake()->createWithContent('t.pdf', '%PDF-1.4 mismo contenido');

    $this->actingAs($this->tesorero)->post('/pagos', ($this->datos)(['importe' => '35000', 'comprobante' => $archivo()]))->assertSessionHasNoErrors();
    $this->actingAs($this->tesorero)->post('/pagos', ($this->datos)(['importe' => '35000', 'comprobante' => $archivo()]))->assertSessionHasErrors('comprobante');

    expect(Pago::count())->toBe(1)->and(Comprobante::count())->toBe(1);
});

test('no se aceptan pagos con fecha futura ni importes inválidos', function () {
    $this->actingAs($this->tesorero)->post('/pagos', ($this->datos)(['fecha' => '2026-09-25']))->assertSessionHasErrors('fecha');
    $this->actingAs($this->tesorero)->post('/pagos', ($this->datos)(['importe' => '0']))->assertSessionHasErrors('importe');
    expect(Pago::count())->toBe(0);
});

test('imputación manual desde el formulario y anulación desde el listado', function () {
    $septiembre = Cuota::whereDate('periodo', '2026-09-01')->firstOrFail();

    $this->actingAs($this->tesorero)->post('/pagos', ($this->datos)(['importe' => '35000', 'imputaciones' => [$septiembre->id => '35000']]))->assertSessionHasNoErrors();
    expect($septiembre->fresh()->estado)->toBe(EstadoCuota::Pagada)
        ->and(Cuota::whereDate('periodo', '2026-08-01')->first()->estado)->toBe(EstadoCuota::Pendiente);

    $pago = Pago::firstOrFail();
    $this->actingAs($this->tesorero)->post("/pagos/{$pago->id}/anular", ['motivo' => ''])->assertSessionHasErrors('motivo');
    $this->actingAs($this->tesorero)->post("/pagos/{$pago->id}/anular", ['motivo' => 'Duplicado'])->assertSessionHasNoErrors();

    expect($septiembre->fresh()->estado)->toBe(EstadoCuota::Pendiente);
    $this->actingAs($this->tesorero)->get('/pagos?estado=anulado')->assertInertia(fn ($p) => $p->has('pagos.data', 1));
    $this->actingAs($this->tesorero)->get('/pagos?q=gelos')->assertInertia(fn ($p) => $p->has('pagos.data', 1));
});

test('en efectivo el pago va a la caja y el listado muestra el saldo a favor', function () {
    $this->actingAs($this->tesorero)->post('/pagos', ($this->datos)(['importe' => '100000', 'medio_pago_id' => $this->efectivo->id, 'cuenta_id' => $this->fer->id]))->assertSessionHasNoErrors();

    expect($this->fer->saldoAl())->toBe('100000.00')->and(Movimiento::where('es_tributo', true)->count())->toBe(0);
    $this->actingAs($this->tesorero)->get('/pagos')->assertInertia(fn ($p) => $p->where('pagos.data.0.sobrante', '30000.00'));
});

test('gastos: alta con categoría, filtro, total sin anulados y anulación', function () {
    $cpsr = CategoriaGasto::where('codigo', 'cpsr')->firstOrFail();
    $datos = ['categoria_gasto_id' => $cpsr->id, 'fecha' => '2026-09-08', 'importe' => '322000', 'descripcion' => 'Planeadores luz y socios agosto', 'medio_pago_id' => $this->transferencia->id, 'cuenta_id' => $this->banco->id];

    $this->actingAs($this->tesorero)->post('/gastos', $datos)->assertSessionHasNoErrors();
    $this->actingAs($this->tesorero)->post('/gastos', [...$datos, 'importe' => '1000', 'descripcion' => 'Otro'])->assertSessionHasNoErrors();

    $this->actingAs($this->tesorero)->get('/gastos')->assertInertia(fn ($p) => $p->has('gastos.data', 2)->where('total', '323000.00'));

    $gasto = Gasto::where('importe', 1000)->firstOrFail();
    $this->actingAs($this->tesorero)->post("/gastos/{$gasto->id}/anular", ['motivo' => 'Error'])->assertSessionHasNoErrors();
    $this->actingAs($this->tesorero)->get("/gastos?categoria_gasto_id={$cpsr->id}")->assertInertia(fn ($p) => $p->where('total', '322000.00'));
    $this->actingAs($this->tesorero)->get('/gastos')->assertInertia(fn ($p) => $p->where('total', '322000.00'));

    $this->actingAs($this->tesorero)->post('/gastos', [...$datos, 'fecha' => '2026-12-01'])->assertSessionHasErrors('fecha');
});

test('las categorías de gasto se crean con código único y se desactivan', function () {
    $this->actingAs($this->tesorero)->post('/configuracion/categorias-gasto', ['nombre' => 'Nafta tractor', 'tipo' => 'eventual'])->assertSessionHasErrors('nombre');
    $this->actingAs($this->tesorero)->post('/configuracion/categorias-gasto', ['nombre' => 'Fiesta aniversario', 'tipo' => 'eventual'])->assertSessionHasNoErrors();

    $c = CategoriaGasto::where('nombre', 'Fiesta aniversario')->firstOrFail();
    expect($c->codigo)->toBe('fiesta_aniversario');
    $this->actingAs($this->tesorero)->put("/configuracion/categorias-gasto/{$c->id}", ['nombre' => $c->nombre, 'tipo' => 'eventual', 'activa' => false]);
    expect($c->fresh()->activa)->toBeFalse();
});

test('solo lectura: ve pagos y gastos pero no puede registrar ni anular', function () {
    $lector = User::factory()->lectura()->create();
    foreach (['/pagos', '/gastos', '/configuracion/categorias-gasto'] as $url) {
        $this->actingAs($lector)->get($url)->assertOk();
    }
    $this->actingAs($lector)->get('/pagos/create')->assertForbidden();
    $this->actingAs($lector)->post('/pagos', ($this->datos)())->assertForbidden();
    $this->actingAs($lector)->post('/gastos', ['categoria_gasto_id' => 1])->assertForbidden();
});
