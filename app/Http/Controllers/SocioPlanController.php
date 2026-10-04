<?php

namespace App\Http\Controllers;

use App\Actions\Planes\AsignarPlanSocio;
use App\Models\Plan;
use App\Models\Socio;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SocioPlanController extends Controller
{
    public function store(Request $request, Socio $socio, AsignarPlanSocio $asignar): RedirectResponse
    {
        $datos = $request->validate([
            'plan_id' => ['required', 'exists:planes,id'],
            'desde' => ['required', 'date'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $asignar($socio, Plan::findOrFail($datos['plan_id']), CarbonImmutable::parse($datos['desde']), $datos['motivo'] ?? null);

        return back()->with('success', 'Plan asignado.');
    }

    public function masiva(): Response
    {
        $socios = Socio::vigentes()
            ->with(['planes' => fn ($q) => $q->whereNull('hasta')->with('plan')])
            ->orderBy('apellido')->orderBy('nombre')->get()
            ->map(fn (Socio $s) => [
                'id' => $s->id,
                'nombre' => $s->nombre_completo,
                'categoria' => $s->categoria->label(),
                'sede' => $s->sede->label(),
                'plan_actual' => $s->planes->first()?->plan->nombre,
                'plan_actual_id' => $s->planes->first()?->plan_id,
            ]);

        return Inertia::render('planes/asignacion-masiva', [
            'socios' => $socios,
            'planes' => Plan::where('activo', true)->orderBy('orden')->get(['id', 'nombre']),
        ]);
    }

    public function masivaStore(Request $request, AsignarPlanSocio $asignar): RedirectResponse
    {
        $datos = $request->validate([
            'socio_ids' => ['required', 'array', 'min:1'],
            'socio_ids.*' => ['integer', 'exists:socios,id'],
            'plan_id' => ['required', 'exists:planes,id'],
            'desde' => ['required', 'date'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $plan = Plan::findOrFail($datos['plan_id']);
        $desde = CarbonImmutable::parse($datos['desde']);

        // Todo o nada: si un socio no se puede asignar (ya tiene ese plan, fecha inconsistente), no se toca a ninguno.
        DB::transaction(function () use ($datos, $plan, $desde, $asignar) {
            foreach (Socio::whereIn('id', $datos['socio_ids'])->get() as $socio) {
                $asignar($socio, $plan, $desde, $datos['motivo'] ?? null);
            }
        });

        return to_route('planes.index')->with('success', count($datos['socio_ids']).' socios asignados al plan '.$plan->nombre.'.');
    }
}
