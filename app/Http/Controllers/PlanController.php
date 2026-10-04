<?php

namespace App\Http\Controllers;

use App\Actions\Planes\RegistrarTarifaPlan;
use App\Models\Plan;
use App\Models\TarifaPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(): Response
    {
        $hoy = CarbonImmutable::now();

        $planes = Plan::with('tarifas')->withCount(['asignaciones as socios_count' => fn ($q) => $q->whereNull('hasta')])->orderBy('orden')->orderBy('nombre')->get()
            ->map(fn (Plan $p) => [
                'id' => $p->id,
                'tipo_plan' => $p->tipo_plan,
                'nombre' => $p->nombre,
                'descripcion' => $p->descripcion,
                'genera_cuota' => $p->genera_cuota,
                'es_adicional_familiar' => $p->es_adicional_familiar,
                'activo' => $p->activo,
                'socios_count' => $p->getAttribute('socios_count'),
                'importe_vigente' => $p->tarifas->first(fn (TarifaPlan $t) => $t->vigencia_desde->lte($hoy) && ($t->vigencia_hasta === null || $t->vigencia_hasta->gte($hoy)))?->importe,
                'tarifas' => $p->tarifas->map(fn (TarifaPlan $t) => [
                    'id' => $t->id,
                    'importe' => $t->importe,
                    'vigencia_desde' => $t->vigencia_desde->toDateString(),
                    'vigencia_hasta' => $t->vigencia_hasta?->toDateString(),
                ])->values(),
            ]);

        return Inertia::render('planes/index', ['planes' => $planes]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'tipo_plan' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', 'unique:planes,tipo_plan'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'genera_cuota' => ['boolean'],
            'es_adicional_familiar' => ['boolean'],
        ], ['tipo_plan.regex' => 'Usá solo minúsculas, números y guión bajo (ej.: socio_cadete).']);

        Plan::create([...$datos, 'orden' => (int) Plan::max('orden') + 1]);

        return back()->with('success', 'Plan creado. Cargale una tarifa para poder devengar cuotas.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'genera_cuota' => ['boolean'],
            'es_adicional_familiar' => ['boolean'],
            'activo' => ['boolean'],
        ]));

        return back()->with('success', 'Plan actualizado.');
    }

    public function storeTarifa(Request $request, Plan $plan, RegistrarTarifaPlan $registrar): RedirectResponse
    {
        $datos = $request->validate([
            'importe' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'vigencia_desde' => ['required', 'date'],
        ]);

        $registrar($plan, number_format((float) $datos['importe'], 2, '.', ''), CarbonImmutable::parse($datos['vigencia_desde']), $request->user()->id);

        return back()->with('success', 'Nueva tarifa registrada.');
    }
}
