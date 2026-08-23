<?php

namespace App\Http\Controllers;

use App\Models\Devolucion;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Devoluciones;
use App\Support\Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class DevolucionController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'tipo' => $request->string('tipo')->toString(),
            'desde' => $request->date('desde')?->format('Y-m-d'),
            'hasta' => $request->date('hasta')?->format('Y-m-d'),
        ];

        $devoluciones = Devolucion::with([
            'venta:id,cliente_id,estado',
            'venta.cliente:id,nombre',
            'venta.comprobante:id,venta_id,numero_completo',
            'usuario:id,usuario',
        ])
            ->withCount('detalle')
            ->when($filtros['buscar'] !== '', function ($q) use ($filtros) {
                $texto = $filtros['buscar'];
                $q->where(function ($sub) use ($texto) {
                    $sub->where('motivo', 'like', "%{$texto}%")
                        ->orWhereHas('venta.comprobantes', fn ($c) => $c->where('numero_completo', 'like', "%{$texto}%"))
                        ->orWhereHas('venta.cliente', fn ($c) => $c->where('nombre', 'like', "%{$texto}%"));
                });
            })
            ->when($filtros['tipo'], fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when($filtros['desde'], fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($filtros['hasta'], fn ($q, $h) => $q->whereDate('fecha', '<=', $h))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('devoluciones.index', [
            'title' => 'Devoluciones',
            'devoluciones' => $devoluciones,
            'filtros' => $filtros,
            'resumen' => $this->resumen($filtros),
        ]);
    }

    /** Formulario de devolución, montado sobre las líneas de una venta. */
    public function create(Venta $venta): View
    {
        $venta->load([
            'cliente:id,nombre',
            'comprobante:id,venta_id,numero_completo',
            'detalle.producto:id,codigo,unidad_medida_id',
            'detalle.producto.unidadMedida:id,codigo,permite_decimal',
        ]);

        return view('devoluciones.create', [
            'title' => 'Devolución de la venta #'.$venta->id,
            'trail' => ['Devoluciones' => route('devoluciones.index')],
            'venta' => $venta,
            'sesion' => Cajas::sesionDe(Auth::user()),
        ]);
    }

    public function store(Request $request, Venta $venta): RedirectResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.venta_detalle_id' => [
                'required',
                Rule::exists('venta_detalle', 'id')->where('venta_id', $venta->id),
            ],
            'lineas.*.cantidad' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.reingresa_stock' => ['boolean'],
        ], [
            'motivo.required' => 'La devolución necesita un motivo: queda registrada con tu nombre.',
            'motivo.min' => 'Explica el motivo con un poco más de detalle.',
            'lineas.*.venta_detalle_id.exists' => 'Una de las líneas no pertenece a esta venta.',
        ], [
            'motivo' => 'motivo',
        ]);

        $sesion = Cajas::sesionDe(Auth::user());

        if (! $sesion) {
            return redirect()->route('caja.index')
                ->with('error', 'Abre tu caja antes de registrar una devolución: el dinero sale del cajón.');
        }

        try {
            $devolucion = Devoluciones::registrar(
                venta: $venta,
                usuario: Auth::user(),
                sesion: $sesion,
                lineas: $datos['lineas'],
                motivo: $datos['motivo'],
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('devoluciones.show', $devolucion)
            ->with('exito', 'Devolución registrada por '.Config::importe($devolucion->total).
                '. El stock que volvió al estante ya está en el kardex.');
    }

    public function show(Devolucion $devolucion): View
    {
        $devolucion->load([
            'venta.cliente:id,nombre',
            'venta.comprobante:id,venta_id,numero_completo',
            'usuario:id,usuario',
            'sesionCaja.caja:id,nombre',
            'detalle.producto:id,codigo,unidad_medida_id',
            'detalle.producto.unidadMedida:id,codigo',
            // La tasa de impuesto está congelada en la línea de la venta.
            'detalle.ventaDetalle:id,descripcion,afecto_impuesto,tasa_impuesto',
        ]);

        return view('devoluciones.show', [
            'title' => 'Devolución #'.$devolucion->id,
            'trail' => ['Devoluciones' => route('devoluciones.index')],
            'devolucion' => $devolucion,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, float|int>
     */
    private function resumen(array $filtros): array
    {
        $base = Devolucion::query()
            ->when($filtros['desde'], fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($filtros['hasta'], fn ($q, $h) => $q->whereDate('fecha', '<=', $h));

        return [
            'operaciones' => (int) (clone $base)->count(),
            'devuelto' => (float) (clone $base)->sum('total'),
            'totales' => (int) (clone $base)->where('tipo', 'TOTAL')->count(),
        ];
    }
}
