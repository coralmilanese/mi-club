<?php

namespace App\Http\Controllers;

use App\Actions\Comprobantes\GuardarComprobante;
use App\Actions\Gastos\AnularGasto;
use App\Actions\Gastos\RegistrarGasto;
use App\Models\CategoriaGasto;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\MedioPago;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GastoController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'categoria_gasto_id' => ['nullable', 'integer', 'exists:categorias_gasto,id'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $query = Gasto::query()
            ->with(['categoria', 'cuenta', 'medioPago'])
            ->when($f['categoria_gasto_id'] ?? null, fn ($q, $v) => $q->where('categoria_gasto_id', $v))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '>=', $v))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '<=', $v));

        $total = (string) $query->clone()->whereNull('anulado_at')->sum('importe');

        $gastos = $query->orderByDesc('fecha')->orderByDesc('id')->paginate(30)->withQueryString()->through(fn (Gasto $g) => [
            'id' => $g->id,
            'fecha' => $g->fecha->toDateString(),
            'categoria' => $g->categoria->nombre,
            'descripcion' => $g->descripcion,
            'proveedor' => $g->proveedor,
            'importe' => $g->importe,
            'cuenta' => $g->cuenta->nombre,
            'medio' => $g->medioPago?->nombre,
            'anulado' => $g->anulado_at !== null,
        ]);

        return Inertia::render('gastos/index', [
            'gastos' => $gastos,
            'total' => number_format((float) $total, 2, '.', ''),
            'filtros' => ['categoria_gasto_id' => (string) ($f['categoria_gasto_id'] ?? ''), 'desde' => $f['desde'] ?? '', 'hasta' => $f['hasta'] ?? ''],
            'categorias' => CategoriaGasto::orderBy('nombre')->get(['id', 'nombre', 'activa']),
            'medios' => MedioPago::where('activo', true)->get(['id', 'nombre', 'cuenta_default_id']),
            'cuentas' => Cuenta::where('activa', true)->orderBy('id')->get(['id', 'nombre']),
        ]);
    }

    public function store(Request $request, RegistrarGasto $registrar, GuardarComprobante $guardar): RedirectResponse
    {
        $d = $request->validate([
            'categoria_gasto_id' => ['required', 'exists:categorias_gasto,id'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'importe' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'descripcion' => ['required', 'string', 'max:255'],
            'proveedor' => ['nullable', 'string', 'max:255'],
            'medio_pago_id' => ['required', 'exists:medios_pago,id'],
            'cuenta_id' => ['required', 'exists:cuentas,id'],
            'periodo_cubierto_desde' => ['nullable', 'date'],
            'periodo_cubierto_hasta' => ['nullable', 'date', 'after_or_equal:periodo_cubierto_desde'],
            'comprobante' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        $registrar(
            categoria: CategoriaGasto::findOrFail($d['categoria_gasto_id']),
            fecha: CarbonImmutable::parse($d['fecha']),
            importe: number_format((float) $d['importe'], 2, '.', ''),
            descripcion: $d['descripcion'],
            cuenta: Cuenta::findOrFail($d['cuenta_id']),
            medio: MedioPago::findOrFail($d['medio_pago_id']),
            proveedor: $d['proveedor'] ?? null,
            periodoDesde: isset($d['periodo_cubierto_desde']) ? CarbonImmutable::parse($d['periodo_cubierto_desde']) : null,
            periodoHasta: isset($d['periodo_cubierto_hasta']) ? CarbonImmutable::parse($d['periodo_cubierto_hasta']) : null,
            comprobante: $request->file('comprobante') ? $guardar->desdeUpload($request->file('comprobante')) : null,
            userId: $request->user()->id,
        );

        return back()->with('success', 'Gasto registrado.');
    }

    public function anular(Request $request, Gasto $gasto, AnularGasto $anular): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

        $anular($gasto, $d['motivo'], $request->user()->id);

        return back()->with('success', 'Gasto anulado.');
    }
}
