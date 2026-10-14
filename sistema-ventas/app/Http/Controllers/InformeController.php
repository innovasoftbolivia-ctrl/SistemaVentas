<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportaInformes;
use App\Services\Archivador;
use App\Services\ControlDeCajeros;
use App\Support\Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informes para decidir, no para llenar carpetas (objetivo O5):
 *
 *   - MERMAS: cuánta plata se perdió en bajas y ajustes, y por qué;
 *   - PRODUCTOS SIN MOVIMIENTO: el capital dormido en el estante;
 *   - HORAS DE MÁS VENTA: cuándo hay cola y cuándo no, para decidir turnos;
 *   - CONTROL DE CAJEROS: quién se aparta de lo normal en anulaciones, descuentos,
 *     devoluciones, arqueos y accesos denegados.
 *
 * Cada uno se arma como un documento (indicadores y tablas) del que salen la
 * pantalla, el Excel y el PDF. Ver {@see ExportaInformes}.
 */
class InformeController extends Controller
{
    use ExportaInformes;

    /** Los plazos que se ofrecen para decir que un producto «no se mueve». */
    public const PLAZOS_SIN_MOVIMIENTO = [30, 60, 90, 180, 365];

    private const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    // ================================================================ pantallas

    public function mermas(Request $request): View
    {
        [$desde, $hasta] = $this->rango($request);

        return view('reportes.informe', [
            'title' => 'Mermas',
            'doc' => $this->documentoMermas($desde, $hasta),
            'desde' => $desde,
            'hasta' => $hasta,
            'accion' => route('informes.mermas'),
            'excel' => route('informes.mermas.excel'),
            'pdf' => route('informes.mermas.pdf'),
            'aviso' => $this->avisoDeArchivo($desde),
        ]);
    }

    public function sinMovimiento(Request $request): View
    {
        $dias = $this->plazo($request);

        return view('reportes.informe', [
            'title' => 'Productos sin movimiento',
            'doc' => $this->documentoSinMovimiento($dias),
            'dias' => $dias,
            'plazos' => self::PLAZOS_SIN_MOVIMIENTO,
            'accion' => route('informes.sin-movimiento'),
            'excel' => route('informes.sin-movimiento.excel', ['dias' => $dias]),
            'pdf' => route('informes.sin-movimiento.pdf', ['dias' => $dias]),
        ]);
    }

    /**
     * Las mermas salen del kardex, y lo de más de un año vive en su archivo (ver
     * {@see Archivador}): si el período empieza antes del corte, la cifra está incompleta
     * y se dice, en vez de dejar que parezca que no se perdió nada.
     */
    private function avisoDeArchivo(Carbon $desde): ?string
    {
        $corte = Archivador::archivadoHasta();

        return $corte && $desde->lt($corte)
            ? 'Lo anterior al '.$corte->format('d/m/Y').' está archivado y no entra en estas cifras. Se consulta en Almacén → Movimientos → «Ver el archivo».'
            : null;
    }

    public function horas(Request $request): View
    {
        [$desde, $hasta] = $this->rango($request);
        $celdas = $this->celdasDeHoras($desde, $hasta);

        return view('reportes.informe', [
            'title' => 'Horas de más venta',
            'doc' => $this->documentoHoras($desde, $hasta, $celdas),
            'desde' => $desde,
            'hasta' => $hasta,
            'accion' => route('informes.horas'),
            'excel' => route('informes.horas.excel'),
            'pdf' => route('informes.horas.pdf'),
            'parcial' => 'reportes.partes.mapa-horas',
            'mapa' => $this->mapaDeHoras($celdas),
        ]);
    }

    public function cajeros(Request $request): View
    {
        [$desde, $hasta] = $this->rango($request);

        return view('reportes.informe', [
            'title' => 'Control de cajeros',
            'doc' => $this->documentoCajeros($desde, $hasta),
            'desde' => $desde,
            'hasta' => $hasta,
            'accion' => route('informes.cajeros'),
            'excel' => route('informes.cajeros.excel'),
            'pdf' => route('informes.cajeros.pdf'),
        ]);
    }

    // ================================================================ descargas

    public function cajerosExcel(Request $request): StreamedResponse|RedirectResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return $this->excel($this->documentoCajeros($desde, $hasta), $this->nombreFichero('cajeros', $desde, $hasta, 'xlsx'));
    }

    public function cajerosPdf(Request $request): Response|RedirectResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return $this->pdf($this->documentoCajeros($desde, $hasta), $this->nombreFichero('cajeros', $desde, $hasta, 'pdf'));
    }

    public function mermasExcel(Request $request): StreamedResponse|RedirectResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return $this->excel($this->documentoMermas($desde, $hasta), $this->nombreFichero('mermas', $desde, $hasta, 'xlsx'));
    }

    public function mermasPdf(Request $request): Response|RedirectResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return $this->pdf($this->documentoMermas($desde, $hasta), $this->nombreFichero('mermas', $desde, $hasta, 'pdf'));
    }

    public function sinMovimientoExcel(Request $request): StreamedResponse|RedirectResponse
    {
        $dias = $this->plazo($request);

        return $this->excel($this->documentoSinMovimiento($dias), "reporte-sin-movimiento_{$dias}-dias_".now()->format('Y-m-d').'.xlsx');
    }

    public function sinMovimientoPdf(Request $request): Response|RedirectResponse
    {
        $dias = $this->plazo($request);

        return $this->pdf($this->documentoSinMovimiento($dias), "reporte-sin-movimiento_{$dias}-dias_".now()->format('Y-m-d').'.pdf');
    }

    public function horasExcel(Request $request): StreamedResponse|RedirectResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return $this->excel($this->documentoHoras($desde, $hasta, $this->celdasDeHoras($desde, $hasta)), $this->nombreFichero('horas', $desde, $hasta, 'xlsx'));
    }

    public function horasPdf(Request $request): Response|RedirectResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return $this->pdf($this->documentoHoras($desde, $hasta, $this->celdasDeHoras($desde, $hasta)), $this->nombreFichero('horas', $desde, $hasta, 'pdf'));
    }

    // ================================================================ mermas

    /**
     * Cómo se lee el motivo de un ajuste. Los motivos los arma el servidor para las
     * bajas por vencimiento y las tomas de inventario; el resto lo escribió una
     * persona.
     */
    public static function claseDeMerma(?string $motivo): string
    {
        $motivo = (string) $motivo;

        return match (true) {
            str_starts_with($motivo, 'Baja por vencimiento') => 'Vencimiento',
            str_starts_with($motivo, 'Toma de inventario') => 'Conteo de inventario',
            default => 'Ajuste manual',
        };
    }

    /**
     * Los ajustes del período, uno por fila, con su valor al costo actual del producto.
     *
     * El kardex no guarda el costo de un ajuste, y el del producto es la mejor cifra
     * que hay: se dice en el informe. `perdida` es verdadero cuando el ajuste sacó
     * stock; falso cuando lo agregó (un sobrante que apareció en el conteo).
     *
     * @return Collection<int, object>
     */
    private function ajustes(Carbon $desde, Carbon $hasta): Collection
    {
        return DB::table('movimientos_inventario as m')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->join('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'm.usuario_id')
            ->where('m.origen', 'AJUSTE')
            ->whereBetween('m.fecha', [$desde, $hasta])
            ->orderBy('m.fecha')
            ->get([
                'm.id', 'm.fecha', 'm.cantidad', 'm.stock_anterior', 'm.stock_resultante', 'm.motivo',
                'p.codigo', 'p.nombre', 'p.precio_compra', 'c.nombre as categoria', 'u.usuario',
            ])
            ->map(function ($a) {
                $a->perdida = (float) $a->stock_resultante < (float) $a->stock_anterior;
                $a->valor = round((float) $a->cantidad * (float) $a->precio_compra, 2);
                $a->clase = self::claseDeMerma($a->motivo);

                return $a;
            });
    }

    /** @return array<string, mixed> */
    private function documentoMermas(Carbon $desde, Carbon $hasta): array
    {
        $todos = $this->ajustes($desde, $hasta);
        $perdidas = $todos->where('perdida', true)->values();
        $sobrantes = $todos->where('perdida', false)->values();

        $valorPerdido = round((float) $perdidas->sum('valor'), 2);
        $valorSobrante = round((float) $sobrantes->sum('valor'), 2);

        $agrupar = function (Collection $filas, callable $clave) use ($valorPerdido): array {
            return $filas->groupBy($clave)->map(fn (Collection $g, $nombre) => [
                (string) $nombre,
                $g->count(),
                (float) $g->sum('cantidad'),
                round((float) $g->sum('valor'), 2),
                $valorPerdido > 0 ? round((float) $g->sum('valor'), 2) / $valorPerdido : 0,
            ])->sortByDesc(fn ($f) => $f[3])->values()->all();
        };

        $cabeceras = fn (string $primera) => [$primera, 'Movimientos', 'Unidades', 'Valor perdido', '% de lo perdido'];
        $formatos = [null, 'entero', 'decimal', 'moneda', 'porcentaje'];
        $alineacion = ['izq', 'der', 'der', 'der', 'der'];
        $total = ['Total', $perdidas->count(), (float) $perdidas->sum('cantidad'), $valorPerdido, $valorPerdido > 0 ? 1 : 0];

        $indicadores = [
            ['etiqueta' => 'Perdiste', 'valor' => $valorPerdido, 'formato' => 'moneda', 'nota' => 'bajas y ajustes que sacaron stock, al costo', 'destacar' => true],
            ['etiqueta' => 'Unidades perdidas', 'valor' => (float) $perdidas->sum('cantidad'), 'formato' => 'decimal', 'nota' => "en {$perdidas->count()} movimiento(s)"],
            ['etiqueta' => 'Sobrantes', 'valor' => $valorSobrante, 'formato' => 'moneda', 'nota' => 'stock que apareció en un conteo'],
            ['etiqueta' => 'Resultado neto', 'valor' => round($valorSobrante - $valorPerdido, 2), 'formato' => 'moneda', 'nota' => 'sobrantes menos pérdidas'],
        ];

        $doc = $this->envoltorio('Mermas', $this->periodoDe($desde, $hasta), $indicadores, [
            [
                'nombre' => 'Por motivo',
                'nota' => 'Vencimiento: bajas de lotes vencidos. Conteo de inventario: lo que la toma corrigió hacia abajo. Ajuste manual: lo demás. Se valora al costo actual de cada producto (el kardex no guarda el costo de un ajuste).',
                'cabeceras' => $cabeceras('Motivo'),
                'formatos' => $formatos,
                'alineacion' => $alineacion,
                'filas' => $agrupar($perdidas, fn ($a) => $a->clase),
                'totales' => $total,
                'vacia' => 'No se perdió nada en el período.',
            ],
            [
                'nombre' => 'Por categoría',
                'cabeceras' => $cabeceras('Categoría'),
                'formatos' => $formatos,
                'alineacion' => $alineacion,
                'filas' => $agrupar($perdidas, fn ($a) => $a->categoria),
                'totales' => $total,
                'vacia' => 'No se perdió nada en el período.',
            ],
            [
                'nombre' => 'Por mes',
                'cabeceras' => $cabeceras('Mes'),
                'formatos' => $formatos,
                'alineacion' => $alineacion,
                'filas' => collect($agrupar($perdidas, fn ($a) => Carbon::parse($a->fecha)->format('Y-m')))
                    ->sortBy(fn ($f) => $f[0])->values()->all(),
                'totales' => $total,
                'vacia' => 'No se perdió nada en el período.',
            ],
            [
                'nombre' => 'Las mayores pérdidas',
                'nota' => 'Las treinta de más valor. Cada una está en el kardex del producto, con su responsable.',
                'cabeceras' => ['Fecha', 'Producto', 'Categoría', 'Unidades', 'Valor', 'Motivo', 'Quién'],
                'formatos' => [null, null, null, 'decimal', 'moneda', null, null],
                'alineacion' => ['izq', 'izq', 'izq', 'der', 'der', 'izq', 'izq'],
                'filas' => $perdidas->sortByDesc('valor')->take(30)->map(fn ($a) => [
                    Carbon::parse($a->fecha)->format('d/m/Y H:i'),
                    $a->nombre,
                    $a->categoria,
                    (float) $a->cantidad,
                    $a->valor,
                    (string) $a->motivo,
                    (string) $a->usuario,
                ])->values()->all(),
                'vacia' => 'No se perdió nada en el período.',
            ],
        ]);

        $doc['orientacion'] = 'landscape';

        return $doc;
    }

    // ================================================================ sin movimiento

    private function plazo(Request $request): int
    {
        $dias = $request->integer('dias', 60);

        return in_array($dias, self::PLAZOS_SIN_MOVIMIENTO, true) ? $dias : 60;
    }

    /**
     * Lo que no se vende: stock que lleva en el estante más de `$dias` días sin una sola
     * venta y sin haber llegado hace poco. Es plata parada.
     *
     * Un producto que llegó ayer no es «dormido» aunque nunca se haya vendido: el plazo
     * corre desde lo último que pasó, la última venta o el último ingreso.
     *
     * @return array<string, mixed>
     */
    private function documentoSinMovimiento(int $dias): array
    {
        $corte = now()->subDays($dias)->startOfDay();

        $activos = DB::table('productos as p')
            ->join('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->where('p.activo', 1)
            ->where('p.stock_actual', '>', 0)
            ->selectRaw('p.id, p.codigo, p.nombre, c.nombre AS categoria, p.stock_actual, p.precio_compra')
            ->selectRaw('ROUND(p.stock_actual * p.precio_compra, 2) AS valor')
            ->selectRaw("(SELECT MAX(v.fecha) FROM venta_detalle d JOIN ventas v ON v.id = d.venta_id
                           WHERE d.producto_id = p.id AND v.estado <> 'ANULADA') AS ultima_venta")
            ->selectRaw("(SELECT MAX(m.fecha) FROM movimientos_inventario m
                           WHERE m.producto_id = p.id AND m.tipo = 'ENTRADA' AND m.origen IN ('COMPRA', 'INICIAL')) AS ultimo_ingreso")
            ->get();

        $enInventario = round((float) $activos->sum('valor'), 2);

        $dormidos = $activos
            ->filter(fn ($f) => ($f->ultima_venta === null || Carbon::parse($f->ultima_venta)->lt($corte))
                && ($f->ultimo_ingreso === null || Carbon::parse($f->ultimo_ingreso)->lt($corte)))
            ->sortByDesc(fn ($f) => (float) $f->valor)
            ->values();

        $parado = round((float) $dormidos->sum('valor'), 2);
        $nunca = $dormidos->whereNull('ultima_venta')->count();

        $indicadores = [
            ['etiqueta' => 'Productos sin moverse', 'valor' => $dormidos->count(), 'formato' => 'entero', 'nota' => "sin una venta en {$dias} días"],
            ['etiqueta' => 'Plata parada', 'valor' => $parado, 'formato' => 'moneda', 'nota' => 'lo que costó lo que no se mueve', 'destacar' => true],
            ['etiqueta' => 'Del total invertido', 'valor' => $enInventario > 0 ? $parado / $enInventario : 0, 'formato' => 'porcentaje', 'nota' => 'parte del inventario a costo'],
            ['etiqueta' => 'Nunca vendidos', 'valor' => $nunca, 'formato' => 'entero', 'nota' => 'ni una sola venta desde que existen'],
        ];

        $doc = $this->envoltorio(
            'Productos sin movimiento',
            "Al {$corte->copy()->addDays($dias)->format('d/m/Y')} · sin ventas ni ingresos hace {$dias} días o más",
            $indicadores,
            [[
                'nombre' => 'Lo que no se vende',
                'nota' => 'Ordenado por la plata que tiene parada. Antes de pedir más de esto: liquidarlo (una oferta), devolverlo al proveedor o dejar de comprarlo.',
                'cabeceras' => ['Producto', 'Categoría', 'Stock', 'Costo', 'Plata parada', 'Última venta', 'Días sin vender', 'Último ingreso'],
                'formatos' => [null, null, 'decimal', 'moneda', 'moneda', null, 'entero', null],
                'alineacion' => ['izq', 'izq', 'der', 'der', 'der', 'izq', 'der', 'izq'],
                'filas' => $dormidos->map(fn ($f) => [
                    $f->nombre,
                    $f->categoria,
                    (float) $f->stock_actual,
                    (float) $f->precio_compra,
                    (float) $f->valor,
                    $f->ultima_venta ? Carbon::parse($f->ultima_venta)->format('d/m/Y') : 'nunca',
                    $f->ultima_venta ? (int) Carbon::parse($f->ultima_venta)->startOfDay()->diffInDays(now()->startOfDay()) : null,
                    $f->ultimo_ingreso ? Carbon::parse($f->ultimo_ingreso)->format('d/m/Y') : '—',
                ])->all(),
                'totales' => ['Total', null, null, null, $parado, null, null, null],
                'vacia' => "Todo lo que tienes se movió en los últimos {$dias} días.",
            ]],
        );

        $doc['orientacion'] = 'landscape';

        return $doc;
    }

    // ================================================================ control de cajeros

    /** @return array<string, mixed> */
    private function documentoCajeros(Carbon $desde, Carbon $hasta): array
    {
        $r = ControlDeCajeros::analizar($desde, $hasta);
        $filas = collect($r['filas']);
        $etiquetas = [
            'anulaciones' => 'Anulaciones', 'descuentos' => 'Descuentos', 'devoluciones' => 'Devoluciones',
            'arqueos' => 'Arqueo de caja', 'accesos' => 'Accesos denegados',
        ];

        $indicadores = [
            ['etiqueta' => 'Cajeros con actividad', 'valor' => $filas->count(), 'formato' => 'entero'],
            ['etiqueta' => 'Para mirar', 'valor' => count($r['alertas']), 'formato' => 'entero', 'destacar' => count($r['alertas']) > 0,
                'nota' => count($r['alertas']) > 0 ? 'una marca es un motivo para revisar, no una acusación' : 'nadie se aparta de lo normal'],
            ['etiqueta' => 'Ventas', 'valor' => $filas->sum('ventas'), 'formato' => 'entero', 'nota' => 'con las anuladas'],
            ['etiqueta' => 'Anuladas', 'valor' => $filas->sum('anuladas'), 'formato' => 'entero'],
        ];

        return $this->envoltorio('Control de cajeros', $this->periodoDe($desde, $hasta), $indicadores, [
            [
                'nombre' => 'Para mirar',
                'nota' => 'Quien se aparta del resto del equipo: al menos '.ControlDeCajeros::VECES.' veces lo normal y con '
                    .ControlDeCajeros::MIN_VENTAS.' ventas o más. Puede tener una explicación; es el motivo para preguntar.',
                'cabeceras' => ['Cajero', 'Qué', 'Detalle'],
                'formatos' => [null, null, null],
                'alineacion' => ['izq', 'izq', 'izq'],
                'filas' => array_map(fn ($a) => [$a['usuario'], $etiquetas[$a['tipo']] ?? $a['tipo'], $a['texto']], $r['alertas']),
                'vacia' => 'Nadie se aparta de lo normal en el período.',
            ],
            [
                'nombre' => 'Cajero por cajero',
                'nota' => 'Las anulaciones y los descuentos se miden sobre sus propias ventas; las devoluciones, sobre lo que vendió.',
                'cabeceras' => ['Cajero', 'Ventas', 'Vendido', 'Anuladas', '% anuladas', 'Descuentos', '% descuento', 'Devuelto', '% devuelto', 'Egresos de caja', 'Turnos con diferencia', 'Diferencia neta', 'Accesos denegados'],
                'formatos' => [null, 'entero', 'moneda', 'entero', 'porcentaje', 'moneda', 'porcentaje', 'moneda', 'porcentaje', 'moneda', 'entero', 'moneda', 'entero'],
                'alineacion' => ['izq', 'der', 'der', 'der', 'der', 'der', 'der', 'der', 'der', 'der', 'der', 'der', 'der'],
                'filas' => $filas->map(fn ($f) => [
                    $f['nombre'], $f['ventas'], $f['vendido'], $f['anuladas'], $f['pct_anuladas'], $f['descuento'], $f['pct_descuento'],
                    $f['devuelto'], $f['pct_devuelto'], $f['egresos'], $f['turnos_con_diferencia'], $f['diferencia'], $f['denegados'],
                ])->all(),
                'vacia' => 'No hubo actividad de cajeros en el período.',
            ],
        ]);
    }

    // ================================================================ horas

    /**
     * Ventas y monto por día de la semana (1 = lunes) y hora.
     *
     * @return Collection<int, object{dia: int, hora: int, ventas: int, monto: float}>
     */
    private function celdasDeHoras(Carbon $desde, Carbon $hasta): Collection
    {
        return DB::table('ventas')
            ->whereBetween('fecha', [$desde, $hasta])
            ->where('estado', '<>', 'ANULADA')
            // WEEKDAY: 0 = lunes. Se pasa a 1..7 para que el lunes sea el primero.
            ->selectRaw('WEEKDAY(fecha) + 1 AS dia, HOUR(fecha) AS hora, COUNT(*) AS ventas, SUM(total) AS monto')
            ->groupByRaw('WEEKDAY(fecha) + 1, HOUR(fecha)')
            ->get()
            ->map(fn ($c) => (object) ['dia' => (int) $c->dia, 'hora' => (int) $c->hora, 'ventas' => (int) $c->ventas, 'monto' => (float) $c->monto]);
    }

    /** @param  Collection<int, object>  $celdas */
    private function documentoHoras(Carbon $desde, Carbon $hasta, Collection $celdas): array
    {
        $ventas = (int) $celdas->sum('ventas');
        $monto = round((float) $celdas->sum('monto'), 2);

        $porDia = $celdas->groupBy('dia');
        $porHora = $celdas->groupBy('hora');

        $filasDia = collect(self::DIAS)->map(function ($nombre, $dia) use ($porDia, $monto) {
            $g = $porDia->get($dia, collect());
            $m = round((float) $g->sum('monto'), 2);
            $n = (int) $g->sum('ventas');

            return [$nombre, $n, $m, $n > 0 ? round($m / $n, 2) : 0, $monto > 0 ? $m / $monto : 0];
        })->values();

        $filasHora = $porHora->map(function (Collection $g, $hora) use ($monto) {
            $m = round((float) $g->sum('monto'), 2);
            $n = (int) $g->sum('ventas');

            return [sprintf('%02d:00 a %02d:59', $hora, $hora), $n, $m, $n > 0 ? round($m / $n, 2) : 0, $monto > 0 ? $m / $monto : 0, (int) $hora];
        })->sortBy(fn ($f) => $f[5])->map(fn ($f) => array_slice($f, 0, 5))->values();

        $mejorDia = $filasDia->sortByDesc(fn ($f) => $f[2])->first();
        $mejorHora = $filasHora->sortByDesc(fn ($f) => $f[2])->first();

        $indicadores = [
            ['etiqueta' => 'Ventas del período', 'valor' => $ventas, 'formato' => 'entero', 'nota' => 'sin contar las anuladas'],
            ['etiqueta' => 'Día más fuerte', 'valor' => $mejorDia && $mejorDia[2] > 0 ? $mejorDia[0] : '—', 'formato' => null, 'nota' => $mejorDia && $mejorDia[2] > 0 ? Config::importe($mejorDia[2]).' vendidos' : null, 'destacar' => true],
            ['etiqueta' => 'Hora más fuerte', 'valor' => $mejorHora ? substr($mejorHora[0], 0, 5).' h' : '—', 'formato' => null, 'nota' => $mejorHora ? Config::importe($mejorHora[2]).' vendidos' : null, 'destacar' => true],
        ];

        $cabeceras = fn (string $primera) => [$primera, 'Ventas', 'Vendido', 'Ticket promedio', '% del total'];
        $formatos = [null, 'entero', 'moneda', 'moneda', 'porcentaje'];
        $alineacion = ['izq', 'der', 'der', 'der', 'der'];

        return $this->envoltorio('Horas de más venta', $this->periodoDe($desde, $hasta), $indicadores, [
            [
                'nombre' => 'Por día de la semana',
                'nota' => 'Para decidir quién trabaja qué día.',
                'cabeceras' => $cabeceras('Día'),
                'formatos' => $formatos,
                'alineacion' => $alineacion,
                'filas' => $filasDia->all(),
                'totales' => ['Total', $ventas, $monto, $ventas > 0 ? round($monto / $ventas, 2) : 0, $monto > 0 ? 1 : 0],
                'vacia' => 'No hubo ventas en el período.',
            ],
            [
                'nombre' => 'Por hora del día',
                'nota' => 'Para saber cuándo hay cola y cuándo el local está vacío.',
                'cabeceras' => $cabeceras('Hora'),
                'formatos' => $formatos,
                'alineacion' => $alineacion,
                'filas' => $filasHora->all(),
                'totales' => ['Total', $ventas, $monto, $ventas > 0 ? round($monto / $ventas, 2) : 0, $monto > 0 ? 1 : 0],
                'vacia' => 'No hubo ventas en el período.',
            ],
        ]);
    }

    /**
     * El mapa de calor de la pantalla: días en las filas, horas en las columnas, tan
     * oscuro como vendido.
     *
     * @param  Collection<int, object>  $celdas
     * @return array{horas: list<int>, filas: list<array{dia: string, celdas: list<array{ventas: int, monto: float, intensidad: float}>}>, maximo: float}
     */
    private function mapaDeHoras(Collection $celdas): array
    {
        if ($celdas->isEmpty()) {
            return ['horas' => [], 'filas' => [], 'maximo' => 0.0];
        }

        $horas = range((int) $celdas->min('hora'), (int) $celdas->max('hora'));
        $maximo = (float) $celdas->max('monto');
        $porCelda = $celdas->keyBy(fn ($c) => $c->dia.'-'.$c->hora);

        $filas = collect(self::DIAS)->map(function ($nombre, $dia) use ($horas, $porCelda, $maximo) {
            return [
                'dia' => $nombre,
                'celdas' => array_map(function ($hora) use ($dia, $porCelda, $maximo) {
                    $c = $porCelda->get($dia.'-'.$hora);

                    return [
                        'ventas' => $c->ventas ?? 0,
                        'monto' => $c->monto ?? 0.0,
                        'intensidad' => $c && $maximo > 0 ? round($c->monto / $maximo, 2) : 0.0,
                    ];
                }, $horas),
            ];
        })->values()->all();

        return ['horas' => $horas, 'filas' => $filas, 'maximo' => $maximo];
    }
}
