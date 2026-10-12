@php
    use App\Support\Config;
@endphp

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiquetas de estante — {{ $productos }} producto(s)</title>

    <style>
        /* Documento imprimible: una hoja A4 con las etiquetas en tres columnas,
           sin nada del panel. Mismo criterio que comprobantes/imprimir. */
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 16px;
            background: #f2f4f7;
            color: #101828;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        .acciones { max-width: 210mm; margin: 0 auto 12px; display: flex; gap: 8px; align-items: center; font-size: 13px; }
        .acciones button {
            padding: 8px 14px; border: 1px solid #0a5cff; background: #0a5cff; color: #fff;
            border-radius: 8px; font-size: 13px; cursor: pointer;
        }
        .acciones .tenue { color: #667085; }

        .hoja {
            margin: 0 auto;
            background: #fff;
            width: 210mm;
            padding: 8mm;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .12);
        }

        .etiqueta {
            height: 42mm;
            padding: 2.5mm 3mm;
            border: 1px dashed #98a2b3; /* la guía de corte */
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            break-inside: avoid;
        }

        .nombre {
            font-size: 11pt; font-weight: 600; line-height: 1.15;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }

        .precio { display: flex; align-items: baseline; gap: 6px; }
        .precio .valor { font-size: 20pt; font-weight: 800; line-height: 1; }
        .precio .moneda { font-size: 9pt; color: #475467; }
        .precio .lista { font-size: 10pt; color: #667085; text-decoration: line-through; }
        .oferta { font-size: 8pt; font-weight: 700; color: #b42318; text-transform: uppercase; letter-spacing: .03em; }
        .unidad { font-size: 8pt; color: #667085; margin-left: auto; }

        .barras svg { display: block; width: 100%; height: 9mm; }
        .numero { font-family: ui-monospace, "Cascadia Mono", monospace; font-size: 7.5pt; text-align: center; letter-spacing: .08em; }
        .interno { font-family: ui-monospace, "Cascadia Mono", monospace; font-size: 8pt; color: #475467; text-align: center; }

        @media print {
            body { background: #fff; padding: 0; }
            .acciones { display: none; }
            .hoja { box-shadow: none; padding: 0; width: auto; }
            @page { size: A4; margin: 8mm; }
        }
    </style>
</head>

<body>
    <div class="acciones">
        <button type="button" onclick="window.print()">Imprimir</button>
        <span class="tenue">{{ $etiquetas->count() }} etiqueta(s) de {{ $productos }} producto(s). Recórtalas por las líneas punteadas.</span>
    </div>

    <div class="hoja" data-hoja>
        @foreach ($etiquetas as $e)
            <div class="etiqueta" data-etiqueta>
                <div class="nombre">{{ $e['nombre'] }}</div>

                <div>
                    @if ($e['oferta_hasta'])
                        <div class="oferta" data-oferta>Oferta hasta el {{ $e['oferta_hasta'] }}</div>
                    @endif
                    <div class="precio">
                        <span class="moneda">{{ $moneda }}</span>
                        <span class="valor" data-precio>{{ number_format($e['precio'], 2, '.', '') }}</span>
                        @if ($e['precio_lista'])
                            <span class="lista" data-precio-lista>{{ number_format($e['precio_lista'], 2, '.', '') }}</span>
                        @endif
                        @if ($e['unidad'] && $e['unidad'] !== 'UND')
                            <span class="unidad">por {{ mb_strtolower($e['unidad']) }}</span>
                        @endif
                    </div>
                </div>

                <div>
                    @if ($e['barras'])
                        <div class="barras" data-barras>{!! $e['barras'] !!}</div>
                    @endif
                    <div class="numero">{{ $e['codigo_barras'] ?: '' }}</div>
                    <div class="interno">{{ $e['codigo'] }}</div>
                </div>
            </div>
        @endforeach
    </div>
</body>

</html>
