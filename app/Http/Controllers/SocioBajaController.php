<?php

namespace App\Http\Controllers;

use App\Actions\Socios\DarDeBajaSocio;
use App\Actions\Socios\ReingresarSocio;
use App\Models\Socio;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocioBajaController extends Controller
{
    public function baja(Request $request, Socio $socio, DarDeBajaSocio $darDeBaja): RedirectResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'motivo' => ['required', 'string', 'max:1000'],
        ]);

        $darDeBaja($socio, CarbonImmutable::parse($datos['fecha']), $datos['motivo']);

        return to_route('socios.show', $socio)->with('success', 'Socio dado de baja.');
    }

    public function reingreso(Request $request, Socio $socio, ReingresarSocio $reingresar): RedirectResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'motivo' => ['nullable', 'string', 'max:1000'],
        ]);

        $reingresar($socio, CarbonImmutable::parse($datos['fecha']), $datos['motivo'] ?? null);

        return to_route('socios.show', $socio)->with('success', 'Reingreso registrado.');
    }
}
