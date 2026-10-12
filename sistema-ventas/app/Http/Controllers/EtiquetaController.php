<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Producto;
use App\Support\CodigoDeBarras;
use App\Support\Config;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Las etiquetas de estante: nombre, precio y código de barras de cada producto,
 * en hojas listas para imprimir y recortar.
 *
 * Se eligen por lo que las hace falta: una categoría entera, o solo los
 * productos cuyo precio cambió en los últimos días (el motivo número uno para
 * reimprimir) o que están en oferta. El precio que lleva la etiqueta es el que
 * se cobra HOY: el de oferta mientras rige, con el de siempre tachado al lado.
 */
class EtiquetaController extends Controller
{
    /** Cuántos productos se listan para elegir: una hoja de etiquetas no pasa de ahí. */
    private const LIMITE = 300;

    /** Los plazos del filtro «precio cambiado». */
    public const PLAZOS_CAMBIO = [1, 7, 30];

    /** Cuántas copias de cada etiqueta se pueden pedir. */
    private const MAX_COPIAS = 20;

    public function index(Request $request): View
    {
        $filtros = $this->filtros($request);
        $productos = $this->productos($filtros)->limit(self::LIMITE + 1)->get();
        $recortado = $productos->count() > self::LIMITE;

        return view('etiquetas.index', [
            'filtros' => $filtros,
            'productos' => $productos->take(self::LIMITE),
            'recortado' => $recortado,
            'limite' => self::LIMITE,
            'categorias' => Categoria::orderBy('nombre')->pluck('nombre', 'id')->all(),
            'plazos' => self::PLAZOS_CAMBIO,
            'maxCopias' => self::MAX_COPIAS,
        ]);
    }

    /** La hoja para imprimir: una etiqueta por producto elegido, por cada copia. */
    public function hoja(Request $request): View
    {
        $datos = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::LIMITE],
            'ids.*' => ['integer'],
            'copias' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_COPIAS],
        ], [
            'ids.required' => 'Elige al menos un producto para imprimir su etiqueta.',
            'ids.min' => 'Elige al menos un producto para imprimir su etiqueta.',
            'ids.max' => 'Son demasiados productos para una sola impresión: imprímelos por categoría.',
        ]);

        $copias = (int) ($datos['copias'] ?? 1);

        $productos = Producto::activos()
            ->with('unidadMedida:id,codigo,nombre')
            ->whereIn('id', $datos['ids'])
            ->orderBy('nombre')
            ->get();

        $etiquetas = collect();

        foreach ($productos as $producto) {
            $etiqueta = [
                'nombre' => $producto->nombre,
                'codigo' => $producto->codigo,
                'codigo_barras' => $producto->codigo_barras,
                'barras' => CodigoDeBarras::svg($producto->codigo_barras),
                'precio' => $producto->precio_estante_vigente,
                'precio_lista' => $producto->en_oferta ? $producto->precio_estante : null,
                'oferta_hasta' => $producto->en_oferta ? $producto->oferta_hasta->format('d/m/Y') : null,
                'unidad' => $producto->unidadMedida?->codigo,
            ];

            for ($i = 0; $i < $copias; $i++) {
                $etiquetas->push($etiqueta);
            }
        }

        return view('etiquetas.hoja', [
            'etiquetas' => $etiquetas,
            'moneda' => Config::moneda(),
            'productos' => $productos->count(),
        ]);
    }

    /**
     * @return array{buscar: string, categoria: ?int, cambio: ?int, oferta: bool}
     */
    private function filtros(Request $request): array
    {
        $cambio = $request->integer('cambio') ?: null;

        return [
            'buscar' => $request->string('buscar')->toString(),
            'categoria' => $request->integer('categoria') ?: null,
            'cambio' => in_array($cambio, self::PLAZOS_CAMBIO, true) ? $cambio : null,
            'oferta' => $request->boolean('oferta'),
        ];
    }

    /** @param  array{buscar: string, categoria: ?int, cambio: ?int, oferta: bool}  $filtros */
    private function productos(array $filtros)
    {
        return Producto::activos()
            ->with('unidadMedida:id,codigo,nombre', 'categoria:id,nombre')
            ->buscar($filtros['buscar'])
            ->when($filtros['categoria'], fn ($q, $id) => $q->where('categoria_id', $id))
            ->when($filtros['cambio'], function ($q, $dias) {
                // Los que tuvieron un CAMBIO_PRECIO en los últimos N días (hoy cuenta).
                $q->whereIn('id', Auditoria::where('accion', 'CAMBIO_PRECIO')
                    ->where('entidad', 'productos')
                    ->where('fecha', '>=', now()->subDays($dias - 1)->startOfDay())
                    ->select('entidad_id'));
            })
            ->when($filtros['oferta'], function ($q) {
                $hoy = now()->toDateString();
                $q->whereNotNull('precio_oferta')
                    ->where('oferta_desde', '<=', $hoy)
                    ->where('oferta_hasta', '>=', $hoy);
            })
            ->orderBy('nombre');
    }
}
