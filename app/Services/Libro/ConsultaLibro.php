<?php

namespace App\Services\Libro;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Vista del libro tipo Excel: saldo corrido calculado con una window function (NO se persiste, así insertar un
 * movimiento con fecha vieja o recalcular impuestos hacia atrás no rompe nada). Los filtros se aplican DESPUÉS
 * del cálculo, para que el saldo de cada fila sea el real y no el del subconjunto filtrado.
 */
class ConsultaLibro
{
    /**
     * @param  array{cuenta_id?: int|string|null, desde?: string|null, hasta?: string|null, categoria?: string|null, q?: string|null, orden?: string|null}  $f
     */
    public function query(array $f): Builder
    {
        $inner = DB::table('movimientos as m')
            ->when($f['cuenta_id'] ?? null, fn ($q, $v) => $q->where('m.cuenta_id', $v))
            ->selectRaw("m.*, SUM(CASE WHEN m.tipo = 'ingreso' THEN m.importe ELSE -m.importe END) OVER (ORDER BY m.fecha, m.id) AS saldo");

        return DB::query()
            ->fromSub($inner, 't')
            ->join('cuentas as c', 'c.id', '=', 't.cuenta_id')
            ->leftJoin('medios_pago as mp', 'mp.id', '=', 't.medio_pago_id')
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->whereDate('t.fecha', '>=', $v))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->whereDate('t.fecha', '<=', $v))
            ->when($f['categoria'] ?? null, fn ($q, $v) => $q->where('t.categoria_libro', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where('t.concepto', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->select('t.id', 't.fecha', 't.concepto', 't.tipo', 't.importe', 't.saldo', 't.categoria_libro', 't.es_tributo', 't.anulado_at', 'c.nombre as cuenta', 'mp.nombre as medio')
            ->orderBy('t.fecha', ($f['orden'] ?? 'desc') === 'asc' ? 'asc' : 'desc')
            ->orderBy('t.id', ($f['orden'] ?? 'desc') === 'asc' ? 'asc' : 'desc');
    }

    /** @return array{ingresos: string, egresos: string, neto: string} sobre el rango filtrado (sin la ventana de saldo) */
    public function totales(array $f): array
    {
        $r = DB::table('movimientos as m')
            ->when($f['cuenta_id'] ?? null, fn ($q, $v) => $q->where('m.cuenta_id', $v))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->whereDate('m.fecha', '>=', $v))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->whereDate('m.fecha', '<=', $v))
            ->when($f['categoria'] ?? null, fn ($q, $v) => $q->where('m.categoria_libro', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where('m.concepto', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo='ingreso' THEN importe END),0) AS ingresos, COALESCE(SUM(CASE WHEN tipo='egreso' THEN importe END),0) AS egresos")
            ->first();

        return [
            'ingresos' => number_format((float) $r->ingresos, 2, '.', ''),
            'egresos' => number_format((float) $r->egresos, 2, '.', ''),
            'neto' => number_format((float) $r->ingresos - (float) $r->egresos, 2, '.', ''),
        ];
    }
}
