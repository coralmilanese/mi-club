<?php

namespace App\Http\Controllers;

use App\Actions\Libro\AnularMovimiento;
use App\Actions\Libro\RegistrarMovimiento;
use App\Enums\TipoMovimiento;
use App\Models\Cuenta;
use App\Models\MedioPago;
use App\Models\Movimiento;
use App\Services\Libro\ConsultaLibro;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LibroCajaController extends Controller
{
    public function index(Request $request, ConsultaLibro $consulta): Response
    {
        $f = $this->filtros($request);

        $movimientos = $consulta->query($f)->paginate(50)->withQueryString()->through(fn ($m) => [
            'id' => $m->id,
            'fecha' => substr((string) $m->fecha, 0, 10),
            'concepto' => $m->concepto,
            'ingreso' => $m->tipo === 'ingreso' ? $m->importe : null,
            'egreso' => $m->tipo === 'egreso' ? $m->importe : null,
            'saldo' => $m->saldo,
            'categoria' => $m->categoria_libro,
            'cuenta' => $m->cuenta,
            'medio' => $m->medio,
            'es_tributo' => (bool) $m->es_tributo,
            'anulado' => $m->anulado_at !== null,
        ]);

        return Inertia::render('libro-caja/index', [
            'movimientos' => $movimientos,
            'filtros' => ['cuenta_id' => (string) ($f['cuenta_id'] ?? ''), 'desde' => $f['desde'] ?? '', 'hasta' => $f['hasta'] ?? '', 'categoria' => $f['categoria'] ?? '', 'q' => $f['q'] ?? '', 'orden' => $f['orden'] ?? 'desc'],
            'totales' => $consulta->totales($f),
            'cuentas' => Cuenta::orderBy('id')->get(['id', 'nombre', 'aplica_tributos', 'activa']),
            'medios' => MedioPago::where('activo', true)->get(['id', 'nombre']),
            'categorias' => Movimiento::query()->distinct()->orderBy('categoria_libro')->pluck('categoria_libro'),
        ]);
    }

    public function store(Request $request, RegistrarMovimiento $registrar): RedirectResponse
    {
        $d = $request->validate([
            'cuenta_id' => ['required', 'exists:cuentas,id'],
            'tipo' => ['required', Rule::enum(TipoMovimiento::class)],
            'fecha' => ['required', 'date'],
            'concepto' => ['required', 'string', 'max:255'],
            'categoria' => ['required', 'string', 'max:100'],
            'importe' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'medio_pago_id' => ['nullable', 'exists:medios_pago,id'],
        ]);

        $registrar(
            cuenta: Cuenta::findOrFail($d['cuenta_id']),
            tipo: TipoMovimiento::from($d['tipo']),
            fecha: CarbonImmutable::parse($d['fecha']),
            concepto: $d['concepto'],
            importe: number_format((float) $d['importe'], 2, '.', ''),
            categoria: $d['categoria'],
            medioPagoId: $d['medio_pago_id'] ?? null,
            userId: $request->user()->id,
        );

        return back()->with('success', 'Movimiento registrado. Los impuestos del día se recalculan solos.');
    }

    public function anular(Request $request, Movimiento $movimiento, AnularMovimiento $anular): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

        $anular($movimiento, $d['motivo'], $request->user()->id);

        return back()->with('success', 'Movimiento anulado con contramovimiento.');
    }

    public function exportar(Request $request, ConsultaLibro $consulta): StreamedResponse
    {
        $f = [...$this->filtros($request), 'orden' => 'asc'];

        $wb = new Spreadsheet;
        $ws = $wb->getActiveSheet()->setTitle('Libro de Caja');
        $ws->fromArray(['FECHA', 'CONCEPTO', 'CUENTA', 'INGRESO', 'EGRESO', 'SALDO', 'MEDIO', 'CATEGORÍA'], null, 'A1');
        $ws->getStyle('A1:H1')->getFont()->setBold(true);

        $fila = 2;
        foreach ($consulta->query($f)->cursor() as $m) {
            $ws->fromArray([
                Date::PHPToExcel(CarbonImmutable::parse($m->fecha)),
                $m->concepto,
                $m->cuenta,
                $m->tipo === 'ingreso' ? (float) $m->importe : null,
                $m->tipo === 'egreso' ? (float) $m->importe : null,
                (float) $m->saldo,
                $m->medio,
                $m->categoria_libro,
            ], null, "A{$fila}");
            $fila++;
        }
        $ws->getStyle("A2:A{$fila}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        $ws->getStyle("D2:F{$fila}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (['A' => 12, 'B' => 48, 'C' => 16, 'D' => 16, 'E' => 16, 'F' => 18, 'G' => 22, 'H' => 24] as $col => $ancho) {
            $ws->getColumnDimension($col)->setWidth($ancho);
        }

        return response()->streamDownload(function () use ($wb) {
            (new Xlsx($wb))->save('php://output');
        }, 'libro-de-caja-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return array{cuenta_id?: int|string|null, desde?: string|null, hasta?: string|null, categoria?: string|null, q?: string|null, orden?: string|null} */
    private function filtros(Request $request): array
    {
        return $request->validate([
            'cuenta_id' => ['nullable', 'integer', 'exists:cuentas,id'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'categoria' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'orden' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
    }
}
