<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\SesionCaja;
use App\Services\Cajas;
use App\Support\Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class CajaController extends Controller
{
    public function index(): View
    {
        $usuario = Auth::user();

        return view('caja.index', [
            'title' => 'Caja',
            'sesion' => Cajas::sesionDe($usuario)?->loadCount('ventas'),
            'cajas' => Caja::activas()->with('sesionAbierta.usuarioApertura')->orderBy('nombre')->get(),
            'historial' => SesionCaja::with(['caja:id,nombre', 'usuarioApertura:id,usuario'])
                ->withCount('ventas')
                ->orderByDesc('fecha_apertura')
                ->paginate(10),
        ]);
    }

    public function show(SesionCaja $sesion): View
    {
        $sesion->load([
            'caja:id,nombre,ubicacion',
            'usuarioApertura:id,usuario',
            'usuarioCierre:id,usuario',
            'movimientos.usuario:id,usuario',
        ]);

        return view('caja.show', [
            'title' => 'Turno de '.$sesion->caja?->nombre,
            'trail' => ['Caja' => route('caja.index')],
            'sesion' => $sesion,
            'ventas' => $sesion->ventas()
                ->with(['cliente:id,nombre', 'comprobante:id,venta_id,numero_completo'])
                ->orderByDesc('fecha')
                ->paginate(15),
            'resumen' => $this->resumen($sesion),
        ]);
    }

    public function abrir(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'caja_id' => ['required', Rule::exists('cajas', 'id')->where('activo', 1)],
            'monto_inicial' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ], [], [
            'caja_id' => 'caja',
            'monto_inicial' => 'monto inicial',
            'observacion' => 'observación',
        ]);

        try {
            Cajas::abrir(
                Caja::findOrFail($datos['caja_id']),
                Auth::user(),
                (float) $datos['monto_inicial'],
                $datos['observacion'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('caja.index')->with('exito', 'Caja abierta. Ya puedes vender.');
    }

    public function movimiento(Request $request, SesionCaja $sesion): RedirectResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(['INGRESO', 'EGRESO'])],
            'concepto' => ['required', 'string', 'max:120'],
            'monto' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
        ], [
            'monto.gt' => 'El monto debe ser mayor que cero.',
        ], [
            'tipo' => 'tipo de movimiento',
        ]);

        if ($sesion->usuario_apertura_id !== Auth::id()) {
            return back()->with('error', 'Solo quien abrió la caja puede registrar sus movimientos.');
        }

        try {
            Cajas::movimiento(
                $sesion,
                Auth::user(),
                $datos['tipo'],
                $datos['concepto'],
                (float) $datos['monto'],
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('exito', 'Movimiento registrado.');
    }

    public function cerrar(Request $request, SesionCaja $sesion): RedirectResponse
    {
        $datos = $request->validate([
            'monto_declarado' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ], [], [
            'monto_declarado' => 'efectivo contado',
            'observacion' => 'observación',
        ]);

        if ($sesion->usuario_apertura_id !== Auth::id() && ! Auth::user()->tienePermiso('reportes.ver')) {
            return back()->with('error', 'Solo quien abrió la caja puede cerrarla.');
        }

        try {
            $sesion = Cajas::cerrar(
                $sesion,
                Auth::user(),
                (float) $datos['monto_declarado'],
                $datos['observacion'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $diferencia = (float) $sesion->diferencia;

        $mensaje = match (true) {
            $diferencia === 0.0 => 'Caja cerrada y cuadrada.',
            $diferencia > 0 => 'Caja cerrada con un sobrante de '.Config::importe($diferencia).'.',
            default => 'Caja cerrada con un faltante de '.Config::importe(abs($diferencia)).'.',
        };

        return redirect()->route('caja.show', $sesion)->with('exito', $mensaje);
    }

    /** @return array<string, float|int> */
    private function resumen(SesionCaja $sesion): array
    {
        $ventas = $sesion->ventas()->where('estado', '<>', 'ANULADA');

        return [
            'ventas' => (int) $ventas->count(),
            'anuladas' => (int) $sesion->ventas()->where('estado', 'ANULADA')->count(),
            'vendido' => (float) $ventas->sum('total'),
            'ingresos' => (float) $sesion->movimientos()->where('tipo', 'INGRESO')->sum('monto'),
            'egresos' => (float) $sesion->movimientos()->where('tipo', 'EGRESO')->sum('monto'),
            'esperado' => $sesion->estaAbierta() ? $sesion->efectivoEsperado() : (float) $sesion->monto_esperado,
        ];
    }
}
