<?php

namespace App\Http\Controllers\Configuracion;

use App\Actions\Tributos\RegistrarAlicuota;
use App\Http\Controllers\Controller;
use App\Models\AlicuotaTributo;
use App\Models\Tributo;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TributoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('configuracion/tributos', [
            'tributos' => Tributo::with('alicuotas')->orderBy('orden')->get()->map(fn (Tributo $t) => [
                'id' => $t->id,
                'nombre' => $t->nombre,
                'codigo' => $t->codigo,
                'base' => $t->base->label(),
                'es_retencion' => $t->es_retencion,
                'incluye_retenciones_en_base' => $t->incluye_retenciones_en_base,
                'concepto' => $t->concepto_libro ?? $t->categoria_libro,
                'activo' => $t->activo,
                'alicuotas' => $t->alicuotas->map(fn (AlicuotaTributo $a) => [
                    'id' => $a->id,
                    'alicuota' => $a->alicuota,
                    'vigencia_desde' => $a->vigencia_desde->toDateString(),
                    'vigencia_hasta' => $a->vigencia_hasta?->toDateString(),
                ])->values(),
            ]),
        ]);
    }

    public function update(Request $request, Tributo $tributo): RedirectResponse
    {
        $tributo->update($request->validate(['activo' => ['required', 'boolean']]));

        return back()->with('success', 'Tributo actualizado.');
    }

    public function storeAlicuota(Request $request, Tributo $tributo, RegistrarAlicuota $registrar): RedirectResponse
    {
        $d = $request->validate([
            'alicuota' => ['required', 'numeric', 'gte:0', 'lte:100'],
            'vigencia_desde' => ['required', 'date'],
        ]);

        $registrar($tributo, number_format((float) $d['alicuota'], 5, '.', ''), CarbonImmutable::parse($d['vigencia_desde']));

        return back()->with('success', 'Nueva alícuota registrada. Los días ya liquidados conservan la que tenían.');
    }
}
