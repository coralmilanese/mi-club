<?php

namespace App\Http\Controllers;

use App\Actions\Socios\AltaSocio;
use App\Enums\CategoriaSocio;
use App\Enums\EstadoSocio;
use App\Enums\SedeSocio;
use App\Http\Requests\Socios\SocioRequest;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\Socio;
use App\Models\TipoDocumento;
use App\Services\Cuotas\ResolverImporteExigible;
use App\Services\Pagos\ImputadorDePagos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SocioController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', 'string'],
            'estado' => ['nullable', 'string'],
            'sede' => ['nullable', 'string'],
        ]);

        // Por defecto, el padrón vigente: las bajas se piden explícitamente con estado=baja.
        $estado = $filtros['estado'] ?? 'vigentes';

        $socios = Socio::query()
            ->buscar($filtros['q'] ?? null)
            ->when($estado === 'vigentes', fn ($q) => $q->vigentes())
            ->when($estado !== 'vigentes' && $estado !== 'todos', fn ($q) => $q->where('estado', $estado))
            ->when($filtros['categoria'] ?? null, fn ($q, $v) => $q->where('categoria', $v))
            ->when($filtros['sede'] ?? null, fn ($q, $v) => $q->where('sede', $v))
            ->orderBy('apellido')->orderBy('nombre')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Socio $s) => [
                'id' => $s->id,
                'nro_socio' => $s->nro_socio,
                'nombre_completo' => $s->nombre_completo,
                'dni' => $s->dni,
                'email' => $s->email,
                'telefono' => $s->telefono,
                'categoria' => $s->categoria->label(),
                'sede' => $s->sede->label(),
                'estado' => $s->estado->value,
                'estado_label' => $s->estado->label(),
            ]);

        return Inertia::render('socios/index', [
            'socios' => $socios,
            'filtros' => ['q' => $filtros['q'] ?? '', 'categoria' => $filtros['categoria'] ?? '', 'estado' => $estado, 'sede' => $filtros['sede'] ?? ''],
            'opciones' => $this->opciones(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('socios/form', [
            'socio' => null,
            'opciones' => $this->opciones(),
        ]);
    }

    public function store(SocioRequest $request, AltaSocio $alta): RedirectResponse
    {
        $socio = $alta($request->validated());

        return to_route('socios.show', $socio)->with('success', 'Socio creado.');
    }

    public function show(Socio $socio, ResolverImporteExigible $resolver, ImputadorDePagos $imputador): Response
    {
        $socio->load(['estados', 'documentos.tipo', 'grupoFamiliar.titular', 'grupoFamiliar.miembros', 'planes.plan']);

        // Cuotas propias más las adicionales de sus adherentes (que paga este socio como titular).
        $cuotas = Cuota::with(['plan', 'socio'])
            ->where(fn ($q) => $q->where('socio_id', $socio->id)->orWhere('socio_pagador_id', $socio->id))
            ->orderByDesc('periodo')->get();
        $aCargo = $cuotas->filter(fn (Cuota $c) => $c->socio_pagador_id === null || $c->socio_pagador_id === $socio->id);

        $tiposActivos = TipoDocumento::where('activo', true)->orderBy('nombre')->get();
        $entregados = $socio->documentos->pluck('tipo_documento_id')->unique();

        return Inertia::render('socios/show', [
            'socio' => [
                ...$socio->only([
                    'id', 'nro_socio', 'apellido', 'nombre', 'genero', 'nacionalidad', 'dni', 'direccion', 'ciudad',
                    'provincia', 'email', 'telefono', 'profesion', 'motivo_baja', 'observaciones',
                ]),
                'nombre_completo' => $socio->nombre_completo,
                'fecha_nacimiento' => $socio->fecha_nacimiento?->toDateString(),
                'fecha_asociacion' => $socio->fecha_asociacion?->toDateString(),
                'fecha_inicio_actividad' => $socio->fecha_inicio_actividad?->toDateString(),
                'fecha_baja' => $socio->fecha_baja?->toDateString(),
                'categoria' => $socio->categoria->label(),
                'sede' => $socio->sede->label(),
                'estado' => $socio->estado->value,
                'estado_label' => $socio->estado->label(),
            ],
            'estados' => $socio->estados->map(fn ($e) => [
                'id' => $e->id,
                'estado' => $e->estado->label(),
                'desde' => $e->desde?->toDateString(),
                'hasta' => $e->hasta?->toDateString(),
                'motivo' => $e->motivo,
            ]),
            'grupo' => $socio->grupoFamiliar ? [
                'id' => $socio->grupoFamiliar->id,
                'nombre' => $socio->grupoFamiliar->nombre,
                'es_titular' => $socio->grupoFamiliar->titular_socio_id === $socio->id,
                'titular' => $socio->grupoFamiliar->titular ? ['id' => $socio->grupoFamiliar->titular->id, 'nombre' => $socio->grupoFamiliar->titular->nombre_completo] : null,
                'miembros' => $socio->grupoFamiliar->miembros->where('id', '!=', $socio->id)->map(fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre_completo])->values(),
            ] : null,
            'documentos' => $socio->documentos->map(fn ($d) => [
                'id' => $d->id,
                'tipo' => $d->tipo->nombre,
                'nombre_original' => $d->nombre_original,
                'tiene_archivo' => $d->tieneArchivo(),
                'subido_at' => $d->subido_at?->toDateString(),
                'observaciones' => $d->observaciones,
            ]),
            // Checklist calculado contra los tipos obligatorios: nada de booleanos tiene_foto / tiene_planilla.
            'checklist' => $tiposActivos->map(fn ($t) => [
                'tipo_id' => $t->id,
                'nombre' => $t->nombre,
                'obligatorio' => $t->obligatorio,
                'entregado' => $entregados->contains($t->id),
            ])->values(),
            'puede_reingresar' => $socio->estaDeBaja(),
            'plan_actual' => $socio->planes->whereNull('hasta')->first()?->only(['id', 'plan_id', 'desde']),
            'planes_historial' => $socio->planes->sortByDesc('desde')->map(fn ($sp) => [
                'id' => $sp->id,
                'plan' => $sp->plan->nombre,
                'desde' => $sp->desde->toDateString(),
                'hasta' => $sp->hasta?->toDateString(),
                'motivo' => $sp->motivo,
            ])->values(),
            'planes_disponibles' => Plan::where('activo', true)->orderBy('orden')->get(['id', 'nombre']),
            'cuotas' => $cuotas->map(fn (Cuota $c) => [
                'id' => $c->id,
                'periodo' => $c->periodo->toDateString(),
                'plan' => $c->plan->nombre,
                'de_adherente' => $c->socio_id !== $socio->id ? $c->socio->nombre_completo : null,
                'cubierta_por_titular' => $c->socio_pagador_id !== null && $c->socio_pagador_id !== $socio->id,
                'estado' => $c->estado->value,
                'estado_label' => $c->estado->label(),
                'devengado' => $c->importe_devengado,
                'cobrado' => $c->importe_cobrado,
                'imputado' => $c->importe_imputado,
                'deuda' => $resolver->deuda($c),
            ])->values(),
            'deuda_total' => $resolver->deudaTotal($aCargo),
            'saldo_a_favor' => $imputador->saldoAFavor($socio),
            'pagos' => Pago::where('socio_id', $socio->id)->with(['cuenta', 'medioPago', 'comprobante', 'imputaciones.cuota'])
                ->orderByDesc('fecha')->orderByDesc('id')->limit(50)->get()->map(fn (Pago $p) => [
                    'id' => $p->id,
                    'fecha' => $p->fecha->toDateString(),
                    'importe' => $p->importe_bruto,
                    'medio' => $p->medioPago?->nombre,
                    'cuenta' => $p->cuenta->nombre,
                    'estado' => $p->estado->value,
                    'estado_label' => $p->estado->label(),
                    'concepto' => $p->concepto,
                    'periodos' => $p->imputaciones->map(fn ($i) => $i->cuota->periodo->locale('es')->translatedFormat('M y'))->join(', '),
                    'comprobante_url' => $p->comprobante ? route('comprobantes.show', $p->comprobante) : null,
                ])->values(),
        ]);
    }

    public function edit(Socio $socio): Response
    {
        return Inertia::render('socios/form', [
            'socio' => [
                ...$socio->only([
                    'id', 'nro_socio', 'apellido', 'nombre', 'genero', 'nacionalidad', 'dni', 'direccion', 'ciudad',
                    'provincia', 'email', 'telefono', 'profesion', 'observaciones',
                ]),
                'fecha_nacimiento' => $socio->fecha_nacimiento?->toDateString(),
                'fecha_asociacion' => $socio->fecha_asociacion?->toDateString(),
                'fecha_inicio_actividad' => $socio->fecha_inicio_actividad?->toDateString(),
                'categoria' => $socio->categoria->value,
                'sede' => $socio->sede->value,
            ],
            'opciones' => $this->opciones(),
        ]);
    }

    public function update(SocioRequest $request, Socio $socio): RedirectResponse
    {
        $socio->update($request->validated());

        return to_route('socios.show', $socio)->with('success', 'Datos guardados.');
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    private function opciones(): array
    {
        return [
            'categorias' => CategoriaSocio::opciones(),
            'estados' => EstadoSocio::opciones(),
            'sedes' => SedeSocio::opciones(),
        ];
    }
}
