<?php

namespace App\Services\Telegram;

use App\Models\Cuenta;
use App\Models\Cuota;
use App\Models\IngestaTelegram;
use App\Models\Pago;
use App\Services\Cuotas\ResolverImporteExigible;
use App\Services\Socios\MatcherSocios;

/** Los comandos de consulta rápida del bot: /deudores, /socio, /saldo, /pendientes. */
class ComandosTelegram
{
    public function __construct(private MatcherSocios $matcher, private ResolverImporteExigible $resolver) {}

    public function deudores(): string
    {
        $porSocio = Cuota::with(['socio', 'plan'])->whereIn('estado', ['pendiente', 'parcial'])->get()->groupBy('socio_id');

        $filas = $porSocio->map(fn ($cuotas) => [$cuotas->first()->socio->nombre_completo, $this->resolver->deudaTotal($cuotas)])
            ->filter(fn ($f) => (float) $f[1] > 0)
            ->sortByDesc(fn ($f) => (float) $f[1])
            ->values();

        if ($filas->isEmpty()) {
            return 'No hay deudores. 🎉';
        }

        $lineas = $filas->map(fn ($f) => '• '.e($f[0]).': $ '.number_format((float) $f[1], 2, ',', '.'))->all();
        $total = $filas->sum(fn ($f) => (float) $f[1]);

        return "<b>Deudores</b> (a tarifa de hoy)\n".implode("\n", $lineas)."\n\nTotal: $ ".number_format($total, 2, ',', '.');
    }

    public function socio(string $nombre): string
    {
        $candidato = $this->matcher->candidatos($nombre)->first();
        if (! $candidato) {
            return 'No encontré a nadie parecido a "'.e($nombre).'".';
        }
        $socio = $candidato['socio'];

        $cuotas = Cuota::where(fn ($q) => $q->where('socio_id', $socio->id)->whereNull('socio_pagador_id')->orWhere('socio_pagador_id', $socio->id))
            ->whereIn('estado', ['pendiente', 'parcial'])->get();
        $deuda = $this->resolver->deudaTotal($cuotas);

        $lineas = ['<b>'.e($socio->nombre_completo).'</b> ('.e($socio->estado->label()).')'];
        $lineas[] = (float) $deuda > 0 ? 'Debe: $ '.number_format((float) $deuda, 2, ',', '.') : 'Al día ✅';

        $pagos = Pago::where('socio_id', $socio->id)->where('estado', 'confirmado')->orderByDesc('fecha')->limit(3)->get();
        if ($pagos->isNotEmpty()) {
            $lineas[] = 'Últimos pagos:';
            foreach ($pagos as $p) {
                $lineas[] = '• '.$p->fecha->format('d/m/Y').': $ '.number_format((float) $p->importe_bruto, 2, ',', '.');
            }
        }

        return implode("\n", $lineas);
    }

    public function saldo(): string
    {
        $cuentas = Cuenta::where('activa', true)->orderBy('id')->get();
        $lineas = $cuentas->map(fn (Cuenta $c) => '• '.e($c->nombre).': $ '.number_format((float) $c->saldoAl(), 2, ',', '.'))->all();
        $total = $cuentas->sum(fn (Cuenta $c) => (float) $c->saldoAl());

        return "<b>Saldo</b>\n".implode("\n", $lineas)."\n\nTotal: $ ".number_format($total, 2, ',', '.');
    }

    public function pendientes(): string
    {
        $n = IngestaTelegram::whereIn('estado', ['esperando_confirmacion', 'esperando_socio', 'esperando_fecha', 'error'])->count();

        return $n === 0 ? 'No hay nada pendiente de revisar. 👍' : "Hay {$n} cosa(s) pendiente(s) de revisar en ".route('telegram.pendientes.index');
    }
}
