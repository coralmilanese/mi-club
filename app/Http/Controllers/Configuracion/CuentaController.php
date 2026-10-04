<?php

namespace App\Http\Controllers\Configuracion;

use App\Actions\Libro\AbrirCuenta;
use App\Enums\TipoAsiento;
use App\Enums\TipoCuenta;
use App\Http\Controllers\Controller;
use App\Models\Cuenta;
use App\Models\MedioPago;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CuentaController extends Controller
{
    public function index(): Response
    {
        $cuentas = Cuenta::orderBy('id')->get()->map(fn (Cuenta $c) => [
            'id' => $c->id,
            'nombre' => $c->nombre,
            'tipo' => $c->tipo->value,
            'tipo_label' => $c->tipo->label(),
            'titular' => $c->titular,
            'aplica_tributos' => $c->aplica_tributos,
            'activa' => $c->activa,
            'saldo' => $c->saldoAl(),
            'con_apertura' => $c->movimientos()->whereHas('asiento', fn ($q) => $q->where('tipo', TipoAsiento::Apertura->value))->exists(),
        ]);

        return Inertia::render('configuracion/cuentas', [
            'cuentas' => $cuentas,
            'tipos' => TipoCuenta::opciones(),
            'medios' => MedioPago::with('cuentaDefault')->orderBy('id')->get()->map(fn (MedioPago $m) => [
                'id' => $m->id,
                'nombre' => $m->nombre,
                'activo' => $m->activo,
                'cuenta_default_id' => $m->cuenta_default_id,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Cuenta::create($request->validate([
            'nombre' => ['required', 'string', 'max:255', 'unique:cuentas,nombre'],
            'tipo' => ['required', Rule::enum(TipoCuenta::class)],
            'titular' => ['nullable', 'string', 'max:255'],
            'aplica_tributos' => ['boolean'],
        ]));

        return back()->with('success', 'Cuenta creada.');
    }

    public function update(Request $request, Cuenta $cuenta): RedirectResponse
    {
        $cuenta->update($request->validate([
            'nombre' => ['required', 'string', 'max:255', Rule::unique('cuentas', 'nombre')->ignore($cuenta->id)],
            'titular' => ['nullable', 'string', 'max:255'],
            'aplica_tributos' => ['boolean'],
            'activa' => ['boolean'],
        ]));

        return back()->with('success', 'Cuenta actualizada.');
    }

    public function apertura(Request $request, Cuenta $cuenta, AbrirCuenta $abrir): RedirectResponse
    {
        $d = $request->validate([
            'saldo' => ['required', 'numeric', 'gte:0', 'max:999999999999'],
            'fecha' => ['required', 'date'],
        ]);

        $abrir($cuenta, number_format((float) $d['saldo'], 2, '.', ''), CarbonImmutable::parse($d['fecha']), $request->user()->id);

        return back()->with('success', "Apertura de {$cuenta->nombre} registrada.");
    }

    public function medio(Request $request, MedioPago $medio): RedirectResponse
    {
        $medio->update($request->validate([
            'cuenta_default_id' => ['nullable', 'exists:cuentas,id'],
            'activo' => ['boolean'],
        ]));

        return back()->with('success', 'Medio de pago actualizado.');
    }
}
