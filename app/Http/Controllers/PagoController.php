<?php

namespace App\Http\Controllers;

use App\Actions\Comprobantes\GuardarComprobante;
use App\Actions\Pagos\AnularPago;
use App\Actions\Pagos\RegistrarPago;
use App\Enums\EstadoPago;
use App\Models\Cuenta;
use App\Models\MedioPago;
use App\Models\Pago;
use App\Models\Socio;
use App\Services\Cuotas\ResolverImporteExigible;
use App\Services\Pagos\ImputadorDePagos;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PagoController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::enum(EstadoPago::class)],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $pagos = Pago::query()
            ->with(['socio', 'cuenta', 'medioPago', 'imputaciones.cuota'])
            ->when($f['q'] ?? null, fn ($q, $v) => $q->whereHas('socio', fn ($s) => $s->buscar($v)))
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '>=', $v))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '<=', $v))
            ->orderByDesc('fecha')->orderByDesc('id')
            ->paginate(30)->withQueryString()
            ->through(fn (Pago $p) => [
                'id' => $p->id,
                'fecha' => $p->fecha->toDateString(),
                'socio' => $p->socio->nombre_completo,
                'socio_id' => $p->socio_id,
                'importe' => $p->importe_bruto,
                'medio' => $p->medioPago?->nombre,
                'cuenta' => $p->cuenta->nombre,
                'concepto' => $p->concepto,
                'estado' => $p->estado->value,
                'estado_label' => $p->estado->label(),
                'origen' => $p->origen->label(),
                'periodos' => $p->imputaciones->map(fn ($i) => $i->cuota->periodo->locale('es')->translatedFormat('M y'))->join(', '),
                'sobrante' => $p->sobrante(),
            ]);

        return Inertia::render('pagos/index', [
            'pagos' => $pagos,
            'filtros' => ['q' => $f['q'] ?? '', 'estado' => $f['estado'] ?? '', 'desde' => $f['desde'] ?? '', 'hasta' => $f['hasta'] ?? ''],
        ]);
    }

    public function create(Request $request, ImputadorDePagos $imputador): Response
    {
        $d = $request->validate([
            'socio_id' => ['nullable', 'integer', 'exists:socios,id'],
            'fecha' => ['nullable', 'date'],
            'importe' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $fecha = CarbonImmutable::parse($d['fecha'] ?? now());
        $socio = isset($d['socio_id']) ? Socio::find($d['socio_id']) : null;

        $adeudadas = [];
        $propuesta = null;
        if ($socio) {
            $resolver = app(ResolverImporteExigible::class);
            $adeudadas = $imputador->cuotasAdeudadas($socio)->map(fn ($c) => [
                'id' => $c->id,
                'periodo' => $c->periodo->toDateString(),
                'plan' => $c->plan->nombre,
                'socio' => $c->socio->nombre_completo,
                'deuda' => $resolver->deuda($c, $fecha),
                'devengado' => $c->importe_devengado,
            ])->values();

            if (isset($d['importe'])) {
                $p = $imputador->proponer($socio, number_format((float) $d['importe'], 2, '.', ''), $fecha);
                $propuesta = [
                    'imputaciones' => array_map(fn ($i) => ['cuota_id' => $i['cuota']->id, 'importe' => $i['importe'], 'completa' => $i['completa']], $p['imputaciones']),
                    'sobrante' => $p['sobrante'],
                    'exacto' => $p['exacto'],
                ];
            }
        }

        return Inertia::render('pagos/create', [
            'socios' => Socio::vigentes()->orderBy('apellido')->orderBy('nombre')->get()->map(fn (Socio $s) => ['id' => $s->id, 'nombre' => $s->nombre_completo]),
            'medios' => MedioPago::where('activo', true)->get(['id', 'nombre', 'codigo', 'cuenta_default_id']),
            'cuentas' => Cuenta::where('activa', true)->orderBy('id')->get(['id', 'nombre', 'tipo']),
            'seleccion' => ['socio_id' => $socio?->id, 'fecha' => $fecha->toDateString(), 'importe' => $d['importe'] ?? null],
            'adeudadas' => $adeudadas,
            'propuesta' => $propuesta,
            'saldo_a_favor' => $socio ? $imputador->saldoAFavor($socio) : '0.00',
        ]);
    }

    public function store(Request $request, RegistrarPago $registrar, GuardarComprobante $guardar): RedirectResponse
    {
        $d = $request->validate([
            'socio_id' => ['required', 'exists:socios,id'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'importe' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'medio_pago_id' => ['required', 'exists:medios_pago,id'],
            'cuenta_id' => ['required', 'exists:cuentas,id'],
            'concepto' => ['nullable', 'string', 'max:255'],
            'referencia_externa' => ['nullable', 'string', 'max:100'],
            'imputaciones' => ['nullable', 'array'],
            'imputaciones.*' => ['nullable', 'numeric', 'min:0'],
            'comprobante' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        $comprobante = $request->file('comprobante') ? $guardar->desdeUpload($request->file('comprobante')) : null;

        $pago = $registrar(
            pagador: Socio::findOrFail($d['socio_id']),
            fecha: CarbonImmutable::parse($d['fecha']),
            importe: number_format((float) $d['importe'], 2, '.', ''),
            cuenta: Cuenta::findOrFail($d['cuenta_id']),
            medio: MedioPago::findOrFail($d['medio_pago_id']),
            concepto: $d['concepto'] ?? null,
            imputacionesManuales: isset($d['imputaciones']) ? array_filter($d['imputaciones'], fn ($v) => $v !== null && (float) $v > 0) : null,
            comprobante: $comprobante,
            referenciaExterna: $d['referencia_externa'] ?? null,
            userId: $request->user()->id,
        );

        $sobrante = (float) $pago->sobrante();

        return to_route('socios.show', $pago->socio_id)->with('success', 'Pago registrado.'.($sobrante > 0 ? ' Quedó $'.number_format($sobrante, 2, ',', '.').' como saldo a favor.' : ''));
    }

    public function anular(Request $request, Pago $pago, AnularPago $anular): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

        $anular($pago, $d['motivo'], $request->user()->id);

        return back()->with('success', 'Pago anulado: las cuotas volvieron a estar pendientes.');
    }
}
