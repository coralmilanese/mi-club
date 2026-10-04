<?php

namespace App\Http\Controllers;

use App\Models\ArqueoCuenta;
use App\Models\Cuenta;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** El bloque de conciliación del pie del Excel, pero automático: saldo calculado vs. saldo declarado por cuenta. */
class ConciliacionController extends Controller
{
    public function index(): Response
    {
        $hoy = CarbonImmutable::now();

        $cuentas = Cuenta::where('activa', true)->orderBy('id')->get()->map(function (Cuenta $c) use ($hoy) {
            $ultimo = ArqueoCuenta::where('cuenta_id', $c->id)->latest('fecha')->latest('id')->first();

            return [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'tipo' => $c->tipo->label(),
                'saldo_calculado' => $c->saldoAl($hoy),
                'ultimo_arqueo' => $ultimo ? [
                    'fecha' => $ultimo->fecha->toDateString(),
                    'declarado' => $ultimo->saldo_declarado,
                    'calculado' => $ultimo->saldo_calculado,
                    'diferencia' => $ultimo->diferencia,
                ] : null,
            ];
        });

        return Inertia::render('conciliacion/index', [
            'fecha' => $hoy->toDateString(),
            'cuentas' => $cuentas,
            'total_calculado' => number_format((float) $cuentas->sum(fn ($c) => (float) $c['saldo_calculado']), 2, '.', ''),
            'arqueos' => ArqueoCuenta::with('cuenta')->latest('fecha')->latest('id')->limit(20)->get()->map(fn (ArqueoCuenta $a) => [
                'id' => $a->id,
                'fecha' => $a->fecha->toDateString(),
                'cuenta' => $a->cuenta->nombre,
                'declarado' => $a->saldo_declarado,
                'calculado' => $a->saldo_calculado,
                'diferencia' => $a->diferencia,
                'observaciones' => $a->observaciones,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'saldos' => ['required', 'array', 'min:1'],
            'saldos.*' => ['nullable', 'numeric', 'max:999999999999'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);

        $fecha = CarbonImmutable::parse($d['fecha']);
        $guardados = 0;

        foreach ($d['saldos'] as $cuentaId => $declarado) {
            if ($declarado === null || $declarado === '') {
                continue; // solo se concilian las cuentas que el tesorero completó
            }
            $cuenta = Cuenta::findOrFail($cuentaId);
            $calculado = $cuenta->saldoAl($fecha);
            $declarado = number_format((float) $declarado, 2, '.', '');

            ArqueoCuenta::create([
                'cuenta_id' => $cuenta->id,
                'fecha' => $fecha->toDateString(),
                'saldo_declarado' => $declarado,
                'saldo_calculado' => $calculado,
                'diferencia' => (string) BigDecimal::of($declarado)->minus($calculado),
                'observaciones' => $d['observaciones'] ?? null,
                'user_id' => $request->user()->id,
            ]);
            $guardados++;
        }

        return back()->with('success', $guardados ? "Arqueo guardado para {$guardados} cuenta(s)." : 'No completaste ningún saldo.');
    }
}
