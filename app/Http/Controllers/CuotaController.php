<?php

namespace App\Http\Controllers;

use App\Actions\Cuotas\DevengarCuotas;
use App\Enums\EstadoCuota;
use App\Models\Cuota;
use App\Services\Cuotas\ResolverImporteExigible;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CuotaController extends Controller
{
    /** Grilla anual: réplica de la hoja `Cuotas` del Excel (socio × mes). */
    public function grilla(Request $request, ResolverImporteExigible $resolver): Response
    {
        $request->validate(['anio' => ['nullable', 'integer', 'between:2000,2100']]);
        $anio = $request->integer('anio') ?: now()->year;

        $cuotas = Cuota::with(['socio.grupoFamiliar.titular', 'plan'])
            ->whereYear('periodo', $anio)
            ->get()
            ->groupBy('socio_id');

        $filas = $cuotas->map(function ($delSocio) use ($resolver) {
            $socio = $delSocio->first()->socio;
            $meses = array_fill(1, 12, null);
            foreach ($delSocio as $c) {
                $celda = ['estado' => $c->estado->value, 'pagador' => $c->socio_pagador_id !== null];

                if ($c->estado === EstadoCuota::Parcial) {
                    // La celda muestra lo que todavía le falta pagar, no lo ya devengado; el tooltip explica el resto.
                    $celda['importe'] = $celda['falta'] = $resolver->deuda($c);
                    $celda['imputado'] = (string) $c->importe_imputado;
                    $celda['exigible'] = $resolver->importe($c, now());
                } else {
                    $celda['importe'] = $c->estado === EstadoCuota::Pagada ? $c->importe_cobrado : $c->importe_devengado;
                }

                $meses[$c->periodo->month] = $celda;
            }

            // Los grupos familiares quedan juntos (titular primero, adherentes detrás), igual que en la hoja Cuotas del Excel.
            $grupo = $socio->grupoFamiliar;
            $esAdherente = $grupo && $grupo->titular_socio_id !== $socio->id;
            $titular = $grupo?->titular;
            $ordenGrupo = mb_strtolower($titular !== null ? $titular->nombre_completo : $socio->nombre_completo);

            return [
                'socio_id' => $socio->id,
                'socio' => $socio->nombre_completo,
                'meses' => array_values($meses),
                'entregado' => (string) $delSocio->sum(fn (Cuota $c) => (float) $c->importe_imputado),
                'deuda' => $resolver->deudaTotal($delSocio),
                '_orden' => [$ordenGrupo, $esAdherente ? 1 : 0, mb_strtolower($socio->nombre_completo)],
            ];
        })->sort(fn ($a, $b) => $a['_orden'] <=> $b['_orden'])->values()->map(function ($f) {
            unset($f['_orden']);

            return $f;
        });

        return Inertia::render('cuotas/grilla', [
            'anio' => $anio,
            'filas' => $filas,
            'totales' => [
                'deuda' => (string) $filas->sum(fn ($f) => (float) $f['deuda']),
                'entregado' => (string) $filas->sum(fn ($f) => (float) $f['entregado']),
            ],
        ]);
    }

    public function devengar(Request $request, DevengarCuotas $devengar): RedirectResponse
    {
        $datos = $request->validate([
            'periodo' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'simular' => ['boolean'],
        ]);

        $simular = (bool) ($datos['simular'] ?? false);
        $r = $devengar(CarbonImmutable::createFromFormat('!Y-m', $datos['periodo']), $simular);

        $mensaje = ($simular ? 'Simulación: se crearían ' : 'Devengamiento listo: ').$r['creadas'].' cuotas nuevas ('.$r['existentes'].' ya existían).';
        if ($r['advertencias']) {
            $mensaje .= ' Avisos: '.implode(' ', array_slice($r['advertencias'], 0, 3));
        }

        return back()->with('success', $mensaje);
    }
}
