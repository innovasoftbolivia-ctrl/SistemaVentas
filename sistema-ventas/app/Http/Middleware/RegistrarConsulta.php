<?php

namespace App\Http\Middleware;

use App\Services\Auditor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Anota quién abrió un reporte o se llevó un Excel o un PDF.
 *
 * La bitácora sabía quién CAMBIÓ cosas, pero no quién MIRÓ: las ventas del
 * mes, el libro de ventas o el kardex completo pueden ser tan sensibles como
 * un cambio de precio, y una descarga se lleva la información fuera del sistema.
 *
 *     Route::get(...)->middleware('consulta');
 *
 * Solo se anota lo que se sirvió (una respuesta 200): una descarga que el
 * sistema rechazó —el PDF que excede el tope, por ejemplo— no salió de acá.
 */
class RegistrarConsulta
{
    /** Cómo se llama cada pantalla en la bitácora. */
    public const TITULOS = [
        'reportes.ventas' => 'Reporte de ventas',
        'reportes.productos' => 'Productos e inventario',
        'reportes.libro-ventas' => 'Libro de ventas',
        'informes.mermas' => 'Mermas',
        'informes.sin-movimiento' => 'Productos sin movimiento',
        'informes.horas' => 'Horas de más venta',
        'informes.cajeros' => 'Control de cajeros',
        'inventario.movimientos' => 'Movimientos del almacén (kardex)',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        if ($request->isMethod('GET') && $respuesta->getStatusCode() === 200 && Auth::check()) {
            $this->anotar($request);
        }

        return $respuesta;
    }

    private function anotar(Request $request): void
    {
        $nombre = (string) $request->route()?->getName();
        $formato = str_ends_with($nombre, '.excel') ? 'Excel' : (str_ends_with($nombre, '.pdf') ? 'PDF' : null);
        $base = $formato ? substr($nombre, 0, strrpos($nombre, '.')) : $nombre;

        $accion = $formato
            ? 'REPORTE_EXPORTADO'
            : (str_starts_with($base, 'inventario.') ? 'KARDEX_VISTO' : 'REPORTE_VISTO');

        $filtros = array_filter(
            $request->except(['page']),
            fn ($v) => is_scalar($v) && trim((string) $v) !== '',
        );

        try {
            Auditor::registrar($accion, 'reportes', null, array_filter([
                'reporte' => self::TITULOS[$base] ?? $base,
                'formato' => $formato,
                'filtros' => $filtros ?: null,
            ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            // Anotar una consulta no puede impedir que se lea el reporte.
            report($e);
        }
    }
}
