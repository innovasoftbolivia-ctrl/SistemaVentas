<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Support\Menu;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * La portada. Cada bloque se arma solo si el rol puede verlo:
 *
 *   - el turno propio y las ventas propias los ve cualquiera que venda,
 *     porque son su trabajo, no información de gestión;
 *   - el resumen del negocio y el gráfico piden `reportes.ver`;
 *   - las alertas de reposición, `productos.gestionar` o `reportes.ver`.
 *
 * Un cajero entra al mostrador, no aquí (ver {@see Menu::inicio()}), pero
 * puede abrir la portada para ver cómo va su turno.
 */
class DashboardController extends Controller
{
    private const DIAS_GRAFICO = 14;

    public function __invoke(): View
    {
        /** @var Usuario $usuario */
        $usuario = Auth::user();

        $gestion = $usuario->tienePermiso('reportes.ver');
        $vende = $usuario->tienePermiso('ventas.registrar');
        $catalogo = $usuario->tienePermiso('productos.gestionar');

        return view('dashboard', [
            'title' => 'Inicio',
            'usuario' => $usuario,
            'sesion' => Cajas::sesionDe($usuario)?->loadCount('ventas'),
            'mias' => $vende ? $this->ventasPropias($usuario) : null,
            'hoy' => $gestion ? $this->comparativaDelDia() : null,
            'serie' => $gestion ? $this->serie() : null,
            'alertas' => ($gestion || $catalogo) ? $this->alertas() : null,
            'ultimas' => $gestion ? $this->ultimasVentas() : null,
            'gestion' => $gestion,
        ]);
    }

    /** Lo que lleva vendido hoy quien mira: es su propio trabajo. */
    private function ventasPropias(Usuario $usuario): array
    {
        $fila = DB::table('ventas')
            ->where('usuario_id', $usuario->id)
            ->whereDate('fecha', now()->toDateString())
            ->where('estado', '<>', 'ANULADA')
            ->selectRaw('COUNT(*) AS operaciones, COALESCE(SUM(total), 0) AS monto')
            ->first();

        return [
            'operaciones' => (int) $fila->operaciones,
            'monto' => (float) $fila->monto,
        ];
    }

    /** Hoy frente a ayer: una cifra sola no dice si va bien o mal. */
    private function comparativaDelDia(): array
    {
        $hoy = $this->totalesDe(now());
        $ayer = $this->totalesDe(now()->subDay());

        return [
            'hoy' => $hoy,
            'ayer' => $ayer,
            'variacion' => $ayer['monto'] > 0
                ? round(($hoy['monto'] - $ayer['monto']) / $ayer['monto'] * 100, 1)
                : null,
        ];
    }

    /** @return array<string, float|int> */
    private function totalesDe(Carbon $dia): array
    {
        $fila = DB::table('ventas')
            ->whereDate('fecha', $dia->toDateString())
            ->where('estado', '<>', 'ANULADA')
            ->selectRaw('COUNT(*) AS operaciones, COALESCE(SUM(total), 0) AS monto')
            ->first();

        $operaciones = (int) $fila->operaciones;
        $monto = (float) $fila->monto;

        return [
            'operaciones' => $operaciones,
            'monto' => $monto,
            'ticket' => $operaciones > 0 ? round($monto / $operaciones, 2) : 0.0,
        ];
    }

    /**
     * Serie de las últimas dos semanas, con los días sin ventas en cero para
     * que el gráfico no una días lejanos con una recta.
     */
    private function serie(): array
    {
        $desde = now()->subDays(self::DIAS_GRAFICO - 1)->startOfDay();

        $filas = DB::table('v_ventas_por_dia')
            ->whereBetween('dia', [$desde->toDateString(), now()->toDateString()])
            ->get()
            ->keyBy(fn ($f) => (string) $f->dia);

        $serie = [];

        for ($dia = $desde->copy(); $dia->lte(now()); $dia->addDay()) {
            $fila = $filas->get($dia->toDateString());

            $serie[] = [
                'etiqueta' => $dia->format('d/m'),
                'monto' => (float) ($fila->monto_total ?? 0),
            ];
        }

        return $serie;
    }

    private function alertas(): Collection
    {
        return DB::table('v_alertas_stock')->orderByDesc('faltante')->limit(6)->get();
    }

    private function ultimasVentas(): Collection
    {
        return Venta::with(['cliente:id,nombre', 'usuario:id,usuario', 'comprobante:id,venta_id,numero_completo'])
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }
}
