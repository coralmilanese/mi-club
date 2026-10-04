<?php

namespace App\Http\Controllers;

use App\Models\GrupoFamiliar;
use App\Models\Socio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GrupoFamiliarController extends Controller
{
    public function index(): Response
    {
        $grupos = GrupoFamiliar::with(['titular', 'miembros'])->orderBy('nombre')->get()->map(fn (GrupoFamiliar $g) => [
            'id' => $g->id,
            'nombre' => $g->nombre,
            'titular' => $g->titular ? ['id' => $g->titular->id, 'nombre' => $g->titular->nombre_completo] : null,
            'adherentes' => $g->miembros->filter(fn (Socio $m) => $m->id !== $g->titular_socio_id)->map(fn (Socio $m) => ['id' => $m->id, 'nombre' => $m->nombre_completo])->values(),
        ]);

        // Socios vigentes que todavía no pertenecen a ningún grupo: candidatos a titular/adherente.
        $disponibles = Socio::vigentes()->whereNull('grupo_familiar_id')->orderBy('apellido')->orderBy('nombre')->get()
            ->map(fn (Socio $s) => ['id' => $s->id, 'nombre' => $s->nombre_completo]);

        return Inertia::render('grupos-familiares/index', ['grupos' => $grupos, 'disponibles' => $disponibles]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'titular_socio_id' => ['required', 'exists:socios,id'],
            'nombre' => ['nullable', 'string', 'max:255'],
        ]);

        $titular = Socio::findOrFail($datos['titular_socio_id']);
        if ($titular->grupo_familiar_id) {
            throw ValidationException::withMessages(['titular_socio_id' => 'Ese socio ya pertenece a un grupo familiar.']);
        }

        DB::transaction(function () use ($datos, $titular) {
            $grupo = GrupoFamiliar::create([
                'nombre' => $datos['nombre'] ?? "Familia {$titular->apellido}",
                'titular_socio_id' => $titular->id,
            ]);
            $titular->update(['grupo_familiar_id' => $grupo->id]);
        });

        return back()->with('success', 'Grupo familiar creado.');
    }

    public function agregarMiembro(Request $request, GrupoFamiliar $grupo): RedirectResponse
    {
        $datos = $request->validate(['socio_id' => ['required', 'exists:socios,id']]);

        $socio = Socio::findOrFail($datos['socio_id']);
        if ($socio->grupo_familiar_id) {
            throw ValidationException::withMessages(['socio_id' => 'Ese socio ya pertenece a un grupo familiar.']);
        }

        $socio->update(['grupo_familiar_id' => $grupo->id]);

        return back()->with('success', 'Adherente agregado.');
    }

    public function quitarMiembro(GrupoFamiliar $grupo, Socio $socio): RedirectResponse
    {
        abort_unless($socio->grupo_familiar_id === $grupo->id, 404);

        if ($grupo->titular_socio_id === $socio->id) {
            throw ValidationException::withMessages(['socio_id' => 'El titular no se puede quitar: disolvé el grupo o cambiá de titular.']);
        }

        $socio->update(['grupo_familiar_id' => null]);

        return back()->with('success', 'Adherente quitado del grupo.');
    }

    public function destroy(GrupoFamiliar $grupo): RedirectResponse
    {
        DB::transaction(function () use ($grupo) {
            Socio::where('grupo_familiar_id', $grupo->id)->update(['grupo_familiar_id' => null]);
            $grupo->delete();
        });

        return back()->with('success', 'Grupo familiar disuelto.');
    }
}
