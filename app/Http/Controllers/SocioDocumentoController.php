<?php

namespace App\Http\Controllers;

use App\Models\Socio;
use App\Models\SocioDocumento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SocioDocumentoController extends Controller
{
    private const DISCO = 'comprobantes';

    public function store(Request $request, Socio $socio): RedirectResponse
    {
        $datos = $request->validate([
            'tipo_documento_id' => ['required', 'exists:tipos_documento,id'],
            'archivo' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);

        $archivo = $request->file('archivo');
        $path = $archivo->store("socios/{$socio->id}", self::DISCO);

        $socio->documentos()->create([
            'tipo_documento_id' => $datos['tipo_documento_id'],
            'archivo_path' => $path,
            'disco' => self::DISCO,
            'nombre_original' => $archivo->getClientOriginalName(),
            'mime' => $archivo->getMimeType(),
            'tamano' => $archivo->getSize(),
            'subido_por_user_id' => $request->user()->id,
            'subido_at' => now(),
            'observaciones' => $datos['observaciones'] ?? null,
        ]);

        return back()->with('success', 'Documento cargado.');
    }

    public function show(Socio $socio, SocioDocumento $documento): StreamedResponse
    {
        abort_unless($documento->socio_id === $socio->id && $documento->tieneArchivo(), 404);

        return Storage::disk($documento->disco)->response($documento->archivo_path, $documento->nombre_original);
    }

    public function destroy(Socio $socio, SocioDocumento $documento): RedirectResponse
    {
        abort_unless($documento->socio_id === $socio->id, 404);

        if ($documento->tieneArchivo()) {
            Storage::disk($documento->disco)->delete($documento->archivo_path);
        }
        $documento->delete();

        return back()->with('success', 'Documento eliminado.');
    }
}
