<?php

namespace App\Http\Controllers;

use App\Models\Cuenta;
use App\Models\LiquidacionDiaria;
use App\Models\Tributo;
use App\Services\Libro\LiquidacionDiariaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LiquidacionDiariaController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'cuenta_id' => ['nullable', 'integer', 'exists:cuentas,id'],
            'mes' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $cuenta = isset($f['cuenta_id']) ? Cuenta::findOrFail($f['cuenta_id']) : Cuenta::where('aplica_tributos', true)->orderBy('id')->first();
        $mes = CarbonImmutable::createFromFormat('!Y-m', $f['mes'] ?? now()->format('Y-m'));

        $tributos = Tributo::where('activo', true)->orderBy('orden')->get(['id', 'nombre', 'codigo']);

        $liquidaciones = $cuenta
            ? LiquidacionDiaria::with('tributos')
                ->where('cuenta_id', $cuenta->id)
                ->whereBetween('fecha', [$mes->startOfMonth()->toDateString(), $mes->endOfMonth()->toDateString()])
                ->orderBy('fecha')->get()
                ->map(fn (LiquidacionDiaria $l) => [
                    'id' => $l->id,
                    'fecha' => $l->fecha->toDateString(),
                    'base_credito' => $l->base_credito,
                    'base_debito' => $l->base_debito_operativo,
                    'ajustada' => $l->ajustada_manualmente,
                    'motivo' => $l->motivo_ajuste,
                    'importes' => $tributos->mapWithKeys(fn ($t) => [$t->codigo => $l->tributos->keyBy('tributo_id')->has($t->id) ? $l->tributos->keyBy('tributo_id')[$t->id]->importe : '0.00'])->all(),
                ])
            : collect();

        return Inertia::render('libro-caja/liquidaciones', [
            'cuentas' => Cuenta::where('aplica_tributos', true)->get(['id', 'nombre']),
            'cuenta_id' => $cuenta?->id,
            'mes' => $mes->format('Y-m'),
            'tributos' => $tributos,
            'liquidaciones' => $liquidaciones,
            'corte' => config('aasr.tributos.corte_recalculo_automatico'),
        ]);
    }

    public function ajustar(Request $request, LiquidacionDiariaService $servicio): RedirectResponse
    {
        $d = $request->validate([
            'cuenta_id' => ['required', 'exists:cuentas,id'],
            'fecha' => ['required', 'date'],
            'motivo' => ['required', 'string', 'max:500'],
            'importes' => ['required', 'array'],
            'importes.*' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ]);

        $servicio->ajustar(
            Cuenta::findOrFail($d['cuenta_id']),
            CarbonImmutable::parse($d['fecha']),
            collect($d['importes'])->map(fn ($v) => number_format((float) ($v ?? 0), 2, '.', ''))->all(),
            $d['motivo'],
            $request->user()->id,
        );

        return back()->with('success', 'Liquidación ajustada manualmente.');
    }

    public function restablecer(Request $request, LiquidacionDiariaService $servicio): RedirectResponse
    {
        $d = $request->validate(['cuenta_id' => ['required', 'exists:cuentas,id'], 'fecha' => ['required', 'date']]);

        $servicio->restablecer(Cuenta::findOrFail($d['cuenta_id']), CarbonImmutable::parse($d['fecha']));

        return back()->with('success', 'El día volvió al cálculo automático.');
    }
}
