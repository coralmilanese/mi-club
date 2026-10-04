<?php

namespace App\Services\Reportes;

use App\Enums\EstadoCuota;
use App\Models\Cuota;
use App\Models\Socio;
use App\Services\Cuotas\ResolverImporteExigible;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Carbon\CarbonImmutable;

/** Estado de cuenta anual de un socio: los 12 meses del año con su estado (pagada/parcial/pendiente/sin devengar). */
class EstadoCuentaSocio
{
    private const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public function __construct(private ResolverImporteExigible $resolver) {}

    /** @return array{socio: Socio, anio: int, meses: list<array<string, mixed>>, total_pagado: string, total_deuda: string, generado: CarbonImmutable} */
    public function datos(Socio $socio, int $anio): array
    {
        $cuotas = Cuota::with('plan')
            ->where('socio_id', $socio->id)
            ->whereYear('periodo', $anio)
            ->get()
            ->keyBy(fn (Cuota $c) => $c->periodo->month);

        $meses = [];
        foreach (self::MESES as $i => $nombre) {
            $cuota = $cuotas->get($i + 1);
            $meses[] = ['mes' => $nombre, ...$this->celda($cuota)];
        }

        return [
            'socio' => $socio,
            'anio' => $anio,
            'meses' => $meses,
            'total_pagado' => number_format($cuotas->sum(fn (Cuota $c) => (float) $c->importe_imputado), 2, '.', ''),
            'total_deuda' => $this->resolver->deudaTotal($cuotas),
            'generado' => CarbonImmutable::now(),
        ];
    }

    public function pdf(Socio $socio, int $anio): PdfDocument
    {
        return Pdf::loadView('pdf.estado-cuenta', $this->datos($socio, $anio))->setPaper('a4');
    }

    public function nombreArchivo(Socio $socio, int $anio): string
    {
        return 'estado-cuenta-'.str($socio->apellido.'-'.$socio->nombre)->slug()."-{$anio}.pdf";
    }

    /** @return array{estado: string|null, estado_label: string, importe: string|null, debe: string, pagador: bool} */
    private function celda(?Cuota $cuota): array
    {
        if (! $cuota) {
            return ['estado' => null, 'estado_label' => 'Sin devengar', 'importe' => null, 'debe' => '0.00', 'pagador' => false];
        }

        $pagador = $cuota->socio_pagador_id !== null;

        if (in_array($cuota->estado, [EstadoCuota::Exenta, EstadoCuota::Anulada], true)) {
            return ['estado' => $cuota->estado->value, 'estado_label' => $cuota->estado->label(), 'importe' => '0.00', 'debe' => '0.00', 'pagador' => $pagador];
        }

        if ($cuota->estado === EstadoCuota::Pagada) {
            return ['estado' => 'pagada', 'estado_label' => 'Pagada', 'importe' => $cuota->importe_cobrado, 'debe' => '0.00', 'pagador' => $pagador];
        }

        // Pendiente o parcial: valuada a la tarifa vigente hoy, igual que en el resto del sistema.
        return [
            'estado' => $cuota->estado->value,
            'estado_label' => $cuota->estado->label(),
            'importe' => $this->resolver->importe($cuota, CarbonImmutable::now()),
            'debe' => $this->resolver->deuda($cuota),
            'pagador' => $pagador,
        ];
    }
}
