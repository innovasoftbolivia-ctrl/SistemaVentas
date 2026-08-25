<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Cliente;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Comprobantes;
use App\Services\Ventas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

class VentaController extends Controller
{
    use OrdenaTablas;

    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'estado' => $request->string('estado')->toString(),
            'usuario' => $request->integer('usuario') ?: null,
            'desde' => $request->date('desde')?->format('Y-m-d'),
            'hasta' => $request->date('hasta')?->format('Y-m-d'),
        ];

        $orden = $this->orden($request, [
            'fecha' => 'fecha',
            'cliente' => Cliente::select('nombre')->whereColumn('clientes.id', 'ventas.cliente_id'),
            'cajero' => Usuario::select('usuario')->whereColumn('usuarios.id', 'ventas.usuario_id'),
            'estado' => 'estado',
            'total' => 'total',
        ], 'fecha', 'desc');

        $ventas = $this->aplicarOrden(Venta::with([
            'cliente:id,nombre',
            'usuario:id,usuario',
            'comprobante:id,venta_id,numero_completo,estado',
        ])
            ->when($filtros['buscar'] !== '', function ($q) use ($filtros) {
                $texto = $filtros['buscar'];
                $q->where(function ($sub) use ($texto) {
                    $sub->whereHas('comprobantes', fn ($c) => $c->where('numero_completo', 'like', "%{$texto}%"))
                        ->orWhereHas('cliente', fn ($c) => $c->where('nombre', 'like', "%{$texto}%"))
                        ->orWhere('id', ltrim($texto, '#'));
                });
            })
            ->when($filtros['estado'], fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['usuario'], fn ($q, $id) => $q->where('usuario_id', $id))
            ->when($filtros['desde'], fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($filtros['hasta'], fn ($q, $h) => $q->whereDate('fecha', '<=', $h)),
            $orden
        )
            ->paginate(15)
            ->withQueryString();

        return view('ventas.index', [
            'title' => 'Ventas',
            'ventas' => $ventas,
            'filtros' => $filtros,
            'estados' => Venta::ESTADOS,
            'cajeros' => Usuario::whereHas('ventas')->orderBy('usuario')->pluck('usuario', 'id'),
            'resumen' => $this->resumen($filtros),
        ]);
    }

    public function show(Venta $venta): View
    {
        $venta->load([
            'cliente',
            'usuario:id,usuario,empleado_id',
            'usuario.empleado:id,nombre_completo',
            'anuladaPor:id,usuario',
            'sesionCaja.caja:id,nombre',
            'detalle.producto:id,codigo,unidad_medida_id',
            'detalle.producto.unidadMedida:id,codigo',
            'pagos.metodoPago:id,codigo,nombre',
            'comprobantes.serie.tipo',
            'devoluciones',
        ]);

        $comprobante = $venta->comprobante;

        return view('ventas.show', [
            'title' => 'Venta #'.$venta->id,
            'trail' => ['Ventas' => route('ventas.index')],
            'venta' => $venta,
            'puedeSustituir' => $comprobante && Comprobantes::puedeSustituirse($comprobante),
            'bloqueoSustitucion' => $comprobante ? Comprobantes::motivoBloqueo($comprobante) : null,
            'venceSustitucion' => Comprobantes::venceEl($venta),
            // Para pasar de recibo a factura hay que asignar una persona jurídica.
            'clientes' => Cliente::activos()->orderBy('nombre')->get()
                ->map(fn (Cliente $c) => [
                    'id' => $c->id,
                    'etiqueta' => $c->etiqueta,
                    'juridica' => $c->esJuridica(),
                ]),
        ]);
    }

    public function anular(Request $request, Venta $venta): RedirectResponse
    {
        $datos = $request->validate([
            'motivo_anulacion' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'motivo_anulacion.required' => 'La anulación necesita un motivo: queda registrado con tu nombre.',
            'motivo_anulacion.min' => 'Explica el motivo con un poco más de detalle.',
        ], [
            'motivo_anulacion' => 'motivo',
        ]);

        try {
            Ventas::anular($venta, Auth::user(), $datos['motivo_anulacion']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('exito', 'Venta anulada. El stock volvió al inventario y el comprobante quedó anulado.');
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, float|int>
     */
    private function resumen(array $filtros): array
    {
        $base = Venta::query()
            ->when($filtros['usuario'], fn ($q, $id) => $q->where('usuario_id', $id))
            ->when($filtros['desde'], fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($filtros['hasta'], fn ($q, $h) => $q->whereDate('fecha', '<=', $h));

        $validas = (clone $base)->where('estado', '<>', 'ANULADA');

        return [
            'operaciones' => (int) (clone $validas)->count(),
            'vendido' => (float) (clone $validas)->sum('total'),
            'impuesto' => (float) (clone $validas)->sum('impuesto'),
            'anuladas' => (int) (clone $base)->where('estado', 'ANULADA')->count(),
        ];
    }
}
