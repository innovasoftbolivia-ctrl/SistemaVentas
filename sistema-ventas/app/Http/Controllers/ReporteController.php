<?php

namespace App\Http\Controllers;

use App\Support\Config;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Reportes de gestión (objetivo O5).
 *
 * Los agregados salen de las vistas que ya define `docs/sql`
 * (`v_ventas_por_dia`, `v_ventas_por_metodo_pago`, `v_alertas_stock`): son la
 * definición oficial de cada cifra y no se reescriben aquí.
 */
class ReporteController extends Controller
{
    public function ventas(Request $request): View
    {
        [$desde, $hasta] = $this->rango($request);

        return view('reportes.ventas', [
            'title' => 'Reporte de ventas',
            'desde' => $desde,
            'hasta' => $hasta,
            'resumen' => $this->resumenVentas($desde, $hasta),
            'porDia' => $this->porDia($desde, $hasta),
            'porMetodo' => $this->porMetodoPago($desde, $hasta),
            'porCajero' => $this->porCajero($desde, $hasta),
        ]);
    }

    public function productos(Request $request): View
    {
        [$desde, $hasta] = $this->rango($request);

        return view('reportes.productos', [
            'title' => 'Reporte de productos e inventario',
            'desde' => $desde,
            'hasta' => $hasta,
            'masVendidos' => $this->masVendidos($desde, $hasta),
            'alertas' => DB::table('v_alertas_stock')->orderByDesc('faltante')->get(),
            'inventario' => $this->valorInventario(),
        ]);
    }

    // ------------------------------------------------------------------ rango

    /**
     * Por defecto, los últimos 30 días. El rango se toma completo: `hasta`
     * incluye todo ese día.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rango(Request $request): array
    {
        $hasta = $request->date('hasta')?->endOfDay() ?? now()->endOfDay();
        $desde = $request->date('desde')?->startOfDay() ?? $hasta->copy()->subDays(29)->startOfDay();

        // Un rango al revés no dice nada: se endereza en vez de devolver vacío.
        return $desde->gt($hasta) ? [$hasta->copy()->startOfDay(), $desde->copy()->endOfDay()] : [$desde, $hasta];
    }

    // ----------------------------------------------------------------- ventas

    /** @return array<string, float|int> */
    private function resumenVentas(Carbon $desde, Carbon $hasta): array
    {
        $ventas = DB::table('ventas')
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw("SUM(estado <> 'ANULADA') AS operaciones")
            ->selectRaw("COALESCE(SUM(IF(estado <> 'ANULADA', total, 0)), 0) AS vendido")
            ->selectRaw("COALESCE(SUM(IF(estado <> 'ANULADA', impuesto, 0)), 0) AS impuesto")
            ->selectRaw("SUM(estado = 'ANULADA') AS anuladas")
            ->first();

        $devuelto = (float) DB::table('devoluciones')
            ->whereBetween('fecha', [$desde, $hasta])
            ->sum('total');

        $operaciones = (int) $ventas->operaciones;
        $vendido = (float) $ventas->vendido;

        return [
            'operaciones' => $operaciones,
            'vendido' => $vendido,
            'impuesto' => (float) $ventas->impuesto,
            'anuladas' => (int) $ventas->anuladas,
            'devuelto' => $devuelto,
            'neto' => round($vendido - $devuelto, 2),
            'ticket' => $operaciones > 0 ? round($vendido / $operaciones, 2) : 0.0,
        ];
    }

    /** Serie diaria, con los días sin ventas rellenados en cero. */
    private function porDia(Carbon $desde, Carbon $hasta): array
    {
        $filas = DB::table('v_ventas_por_dia')
            ->whereBetween('dia', [$desde->toDateString(), $hasta->toDateString()])
            ->orderBy('dia')
            ->get()
            ->keyBy(fn ($f) => (string) $f->dia);

        $serie = [];

        // Sin rellenar los huecos, el gráfico uniría dos días lejanos con una
        // recta y aparentaría ventas que no existieron.
        for ($dia = $desde->copy()->startOfDay(); $dia->lte($hasta); $dia->addDay()) {
            $clave = $dia->toDateString();
            $fila = $filas->get($clave);

            $serie[] = [
                'dia' => $clave,
                'etiqueta' => $dia->format('d/m'),
                'ventas' => (int) ($fila->cantidad_ventas ?? 0),
                'monto' => (float) ($fila->monto_total ?? 0),
                'ticket' => (float) ($fila->ticket_promedio ?? 0),
            ];
        }

        return $serie;
    }

    private function porMetodoPago(Carbon $desde, Carbon $hasta): Collection
    {
        return DB::table('v_ventas_por_metodo_pago')
            ->whereBetween('dia', [$desde->toDateString(), $hasta->toDateString()])
            ->groupBy('metodo_pago')
            ->selectRaw('metodo_pago, SUM(cantidad_ventas) AS ventas, SUM(monto) AS monto')
            ->orderByDesc('monto')
            ->get();
    }

    /** No hay vista para esto: el desglose por vendedor es propio del reporte. */
    private function porCajero(Carbon $desde, Carbon $hasta): Collection
    {
        return DB::table('ventas as v')
            ->join('usuarios as u', 'u.id', '=', 'v.usuario_id')
            ->join('empleados as e', 'e.id', '=', 'u.empleado_id')
            ->whereBetween('v.fecha', [$desde, $hasta])
            ->where('v.estado', '<>', 'ANULADA')
            ->groupBy('u.id', 'u.usuario', 'e.nombre_completo')
            ->selectRaw('u.usuario, e.nombre_completo AS empleado')
            ->selectRaw('COUNT(*) AS ventas, SUM(v.total) AS monto, ROUND(AVG(v.total), 2) AS ticket')
            ->orderByDesc('monto')
            ->get();
    }

    // -------------------------------------------------------------- productos

    /**
     * Ranking del período. Es `v_productos_mas_vendidos` con un filtro de
     * fechas: la vista agrega todo el histórico y no admite rango, así que la
     * consulta se repite aquí con **las mismas fórmulas**. Una prueba compara
     * ambas sin filtro para que no se separen con el tiempo.
     */
    private function masVendidos(Carbon $desde, Carbon $hasta): Collection
    {
        // Neto de devoluciones: el importe de cada línea se prorratea por la
        // fracción que el cliente se quedó, igual que hace la vista.
        $neto = 'ROUND(d.importe * IF(d.cantidad > 0, (d.cantidad - d.cantidad_devuelta) / d.cantidad, 0), 2)';
        $unidades = '(d.cantidad - d.cantidad_devuelta)';

        return DB::table('venta_detalle as d')
            ->join('ventas as v', function ($join) {
                $join->on('v.id', '=', 'd.venta_id')->where('v.estado', '<>', 'ANULADA');
            })
            ->join('productos as p', 'p.id', '=', 'd.producto_id')
            ->join('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->whereBetween('v.fecha', [$desde, $hasta])
            ->groupBy('p.id', 'p.codigo', 'p.nombre', 'c.nombre')
            ->selectRaw('p.id, p.codigo, p.nombre, c.nombre AS categoria')
            ->selectRaw("SUM({$unidades}) AS unidades_vendidas")
            ->selectRaw("SUM({$neto}) AS monto_vendido")
            ->selectRaw("SUM({$neto} - ROUND({$unidades} * p.precio_compra, 2)) AS margen_estimado")
            // Columna añadida, fuera de la vista: deja ver cuánto se devolvió
            // de un producto sin tener que abrir su ficha.
            ->selectRaw('SUM(d.cantidad_devuelta) AS unidades_devueltas')
            ->orderByDesc('monto_vendido')
            ->limit(20)
            ->get();
    }

    /** @return array<string, float|int> */
    private function valorInventario(): array
    {
        $totales = DB::table('productos')
            ->where('activo', 1)
            ->selectRaw('COUNT(*) AS productos')
            ->selectRaw('COALESCE(SUM(stock_actual * precio_compra), 0) AS costo')
            ->selectRaw('COALESCE(SUM(stock_actual * precio_venta), 0) AS venta')
            ->first();

        return [
            'productos' => (int) $totales->productos,
            'costo' => (float) $totales->costo,
            'venta' => (float) $totales->venta,
            'margen' => round((float) $totales->venta - (float) $totales->costo, 2),
            'moneda' => Config::moneda(),
        ];
    }
}
