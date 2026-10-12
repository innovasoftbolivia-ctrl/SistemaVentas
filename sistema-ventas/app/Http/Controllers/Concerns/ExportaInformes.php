<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Hojas;
use App\Support\Config;
use App\Support\TopeExcel;
use App\Support\TopePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lo común de los informes de gestión: el rango de fechas, el envoltorio del
 * documento y las descargas.
 *
 * Un informe se escribe UNA vez, como un «documento» (indicadores y tablas), y de
 * ese mismo documento salen la pantalla, el Excel y el PDF: las tres no se pueden
 * separar con el tiempo.
 */
trait ExportaInformes
{
    protected function excel(array $documento, string $nombreFichero): StreamedResponse|RedirectResponse
    {
        if ($aviso = TopeExcel::excedido($documento)) {
            return back()->with('error', $aviso);
        }

        return (new Hojas($documento))->descargar($nombreFichero);
    }

    /**
     * El PDF sale de la misma estructura que el Excel, así que las dos
     * descargas no se pueden separar con el tiempo.
     */
    protected function pdf(array $documento, string $nombreFichero): Response|RedirectResponse
    {
        if ($aviso = TopePdf::excedido($documento)) {
            return back()->with('error', $aviso);
        }

        return Pdf::loadView('reportes.pdf', ['doc' => $documento])
            ->setPaper('a4', $documento['orientacion'] ?? 'portrait')
            ->download($nombreFichero);
    }

    /**
     * Envoltorio común: cabecera del negocio, período y moneda.
     *
     * @param  array<int, array<string, mixed>>  $indicadores
     * @param  array<int, array<string, mixed>>  $tablas
     * @return array<string, mixed>
     */
    protected function envoltorio(string $titulo, string $periodo, array $indicadores, array $tablas): array
    {
        return [
            'titulo' => $titulo,
            'negocio' => [
                'nombre' => Config::negocio(),
                'documento' => Config::get('negocio_documento'),
                'direccion' => Config::get('negocio_direccion'),
                'telefono' => Config::get('negocio_telefono'),
            ],
            'moneda' => Config::moneda(),
            'periodo' => $periodo,
            'generado' => now()->format('d/m/Y H:i'),
            'orientacion' => 'portrait',
            'indicadores' => $indicadores,
            'tablas' => $tablas,
        ];
    }

    protected function periodoDe(Carbon $desde, Carbon $hasta): string
    {
        return 'Período del '.$desde->format('d/m/Y').' al '.$hasta->format('d/m/Y');
    }

    /** Nombre con el rango dentro, para que dos descargas no se pisen. */
    protected function nombreFichero(string $reporte, Carbon $desde, Carbon $hasta, string $extension): string
    {
        return sprintf(
            'reporte-%s_%s_%s.%s',
            $reporte,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
            $extension
        );
    }

    /**
     * Por defecto, los últimos 30 días. El rango se toma completo: `hasta`
     * incluye todo ese día.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function rango(Request $request): array
    {
        $hasta = $request->date('hasta')?->endOfDay() ?? now()->endOfDay();
        $desde = $request->date('desde')?->startOfDay() ?? $hasta->copy()->subDays(29)->startOfDay();

        // Un rango al revés no dice nada: se endereza en vez de devolver vacío.
        return $desde->gt($hasta) ? [$hasta->copy()->startOfDay(), $desde->copy()->endOfDay()] : [$desde, $hasta];
    }
}
