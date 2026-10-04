<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\TipoDocumento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TipoDocumentoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('configuracion/tipos-documento', [
            'tipos' => TipoDocumento::withCount('documentos')->orderBy('nombre')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'obligatorio' => ['boolean'],
        ]);

        TipoDocumento::create([
            'nombre' => $datos['nombre'],
            'codigo' => $this->codigoUnico($datos['nombre']),
            'obligatorio' => $datos['obligatorio'] ?? false,
        ]);

        return back()->with('success', 'Tipo de documento creado.');
    }

    public function update(Request $request, TipoDocumento $tipo): RedirectResponse
    {
        $tipo->update($request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'obligatorio' => ['boolean'],
            'activo' => ['boolean'],
        ]));

        return back()->with('success', 'Tipo de documento actualizado.');
    }

    public function destroy(TipoDocumento $tipo): RedirectResponse
    {
        if ($tipo->documentos()->exists()) {
            return back()->withErrors(['tipo' => 'Ya hay documentos cargados de este tipo: desactivalo en lugar de borrarlo.']);
        }
        $tipo->delete();

        return back()->with('success', 'Tipo de documento eliminado.');
    }

    private function codigoUnico(string $nombre): string
    {
        $base = Str::slug($nombre, '_');
        $codigo = $base;
        for ($i = 2; TipoDocumento::where('codigo', $codigo)->exists(); $i++) {
            $codigo = "{$base}_{$i}";
        }

        return $codigo;
    }
}
