<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 17px; margin: 0 0 2px; }
        .sub { color: #555; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; }
        .datos td { border: none; padding: 2px 8px 2px 0; }
        .grilla { margin-top: 10px; }
        .grilla th, .grilla td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        .grilla th { background: #f0f0f0; }
        .right { text-align: right; }
        .pagada { background: #e8f5ea; }
        .parcial { background: #fdf3df; }
        .pendiente { background: #fbe9e9; }
        .muted { color: #888; }
        tfoot td { font-weight: bold; border-top: 2px solid #999; }
        .footer { margin-top: 20px; font-size: 10px; color: #888; }
    </style>
</head>
<body>
    <h1>Asociación Aeromodelista Santa Rosa</h1>
    <div class="sub">Estado de cuenta — {{ $anio }}</div>

    <table class="datos">
        <tr><td><strong>Socio</strong></td><td>{{ $socio->nombre_completo }}</td></tr>
        @if ($socio->nro_socio)
            <tr><td><strong>N&ordm; de socio</strong></td><td>{{ $socio->nro_socio }}</td></tr>
        @endif
        <tr><td><strong>Categor&iacute;a</strong></td><td>{{ $socio->categoria->label() }}</td></tr>
        <tr><td><strong>Estado</strong></td><td>{{ $socio->estado->label() }}</td></tr>
    </table>

    <table class="grilla">
        <thead>
            <tr>
                <th>Mes</th>
                <th>Estado</th>
                <th class="right">Importe</th>
                <th class="right">Debe hoy</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($meses as $m)
                <tr class="{{ $m['estado'] ?? '' }}">
                    <td>{{ $m['mes'] }}</td>
                    <td>
                        {{ $m['estado_label'] }}
                        @if ($m['pagador'])
                            <span class="muted">(paga el titular)</span>
                        @endif
                    </td>
                    <td class="right">{{ $m['importe'] !== null ? '$ '.number_format((float) $m['importe'], 2, ',', '.') : '—' }}</td>
                    <td class="right">{{ (float) $m['debe'] > 0 ? '$ '.number_format((float) $m['debe'], 2, ',', '.') : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Totales</td>
                <td class="right">$ {{ number_format((float) $total_pagado, 2, ',', '.') }}</td>
                <td class="right">$ {{ number_format((float) $total_deuda, 2, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">Generado el {{ $generado->format('d/m/Y H:i') }} &mdash; Mi Club AASR</div>
</body>
</html>
