<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Devoluciones;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportesTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function almacenero(): Usuario
    {
        return Usuario::where('usuario', 'almacen')->firstOrFail();
    }

    private function turno(?Usuario $usuario = null): SesionCaja
    {
        return Cajas::abrir(Caja::firstOrFail(), $usuario ?? $this->admin(), 200);
    }

    private function vender(SesionCaja $sesion, float $cantidad = 2, string $codigo = 'P-0004'): Venta
    {
        $producto = Producto::where('codigo', $codigo)->firstOrFail();

        return Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [[
                'producto_id' => $producto->id,
                'cantidad' => $cantidad,
                'precio_unitario' => (float) $producto->precio_venta,
            ]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    private function hoy(): array
    {
        return ['desde' => now()->toDateString(), 'hasta' => now()->toDateString()];
    }

    // -------------------------------------------------------------- permisos

    /** `reportes.ver` lo tienen el administrador y el almacenero, no el cajero. */
    public function test_el_cajero_no_entra_a_los_reportes(): void
    {
        $this->actingAs($this->cajero())->get('/reportes/ventas')->assertForbidden();
        $this->actingAs($this->cajero())->get('/reportes/productos')->assertForbidden();
    }

    public function test_el_almacenero_y_el_administrador_ven_los_reportes(): void
    {
        foreach ([$this->admin(), $this->almacenero()] as $usuario) {
            $this->actingAs($usuario)->get('/reportes/ventas')->assertOk();
            $this->actingAs($usuario)->get('/reportes/productos')->assertOk();
        }
    }

    // ------------------------------------------------------ reporte de ventas

    public function test_el_resumen_cuenta_lo_vendido_del_periodo(): void
    {
        $sesion = $this->turno();
        $primera = $this->vender($sesion, 2);
        $segunda = $this->vender($sesion, 3);

        $resumen = $this->actingAs($this->admin())
            ->get(route('reportes.ventas', $this->hoy()))
            ->assertOk()
            ->viewData('resumen');

        $esperado = round((float) $primera->fresh()->total + (float) $segunda->fresh()->total, 2);

        $this->assertSame(2, $resumen['operaciones']);
        $this->assertSame($esperado, $resumen['vendido']);
        $this->assertSame(round($esperado / 2, 2), $resumen['ticket']);
    }

    public function test_una_venta_anulada_no_suma_al_vendido(): void
    {
        $sesion = $this->turno();
        $vigente = $this->vender($sesion, 2);
        $anulada = $this->vender($sesion, 3);

        Ventas::anular($anulada, $this->admin(), 'Error de cobro');

        $resumen = $this->actingAs($this->admin())
            ->get(route('reportes.ventas', $this->hoy()))
            ->viewData('resumen');

        $this->assertSame(1, $resumen['operaciones']);
        $this->assertSame(1, $resumen['anuladas']);
        $this->assertSame((float) $vigente->fresh()->total, $resumen['vendido']);
    }

    /** El neto descuenta lo devuelto: es lo que quedó en el negocio. */
    public function test_el_neto_descuenta_las_devoluciones(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, 3);

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]], 'Una unidad rota');

        $resumen = $this->actingAs($this->admin())
            ->get(route('reportes.ventas', $this->hoy()))
            ->viewData('resumen');

        $this->assertSame((float) $devolucion->total, $resumen['devuelto']);
        $this->assertSame(
            round($resumen['vendido'] - (float) $devolucion->total, 2),
            $resumen['neto'],
        );
    }

    /** Sin rellenar los huecos, el gráfico uniría días lejanos con una recta. */
    public function test_la_serie_diaria_rellena_los_dias_sin_ventas(): void
    {
        $this->turno();

        $serie = $this->actingAs($this->admin())
            ->get(route('reportes.ventas', [
                'desde' => now()->subDays(6)->toDateString(),
                'hasta' => now()->toDateString(),
            ]))
            ->viewData('porDia');

        $this->assertCount(7, $serie);
        $this->assertSame(now()->subDays(6)->toDateString(), $serie[0]['dia']);
        $this->assertSame(now()->toDateString(), $serie[6]['dia']);
    }

    public function test_el_desglose_por_metodo_de_pago_cuadra_con_lo_vendido(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, 2);

        $respuesta = $this->actingAs($this->admin())->get(route('reportes.ventas', $this->hoy()));

        $porMetodo = $respuesta->viewData('porMetodo');

        $this->assertSame('Efectivo', $porMetodo->first()->metodo_pago);
        $this->assertSame(
            (float) $venta->fresh()->total,
            round((float) $porMetodo->sum('monto'), 2),
        );
    }

    public function test_el_desglose_por_cajero_identifica_a_quien_vendio(): void
    {
        $sesion = $this->turno($this->cajero());
        $this->vender($sesion, 2);

        $porCajero = $this->actingAs($this->admin())
            ->get(route('reportes.ventas', $this->hoy()))
            ->viewData('porCajero');

        $fila = collect($porCajero)->firstWhere('usuario', 'cajero1');

        $this->assertNotNull($fila);
        $this->assertSame(1, (int) $fila->ventas);
    }

    /** Un rango al revés se endereza en vez de devolver vacío. */
    public function test_un_rango_invertido_se_endereza(): void
    {
        $respuesta = $this->actingAs($this->admin())->get(route('reportes.ventas', [
            'desde' => now()->toDateString(),
            'hasta' => now()->subDays(5)->toDateString(),
        ]))->assertOk();

        $this->assertTrue($respuesta->viewData('desde')->lte($respuesta->viewData('hasta')));
        $this->assertCount(6, $respuesta->viewData('porDia'));
    }

    public function test_sin_ventas_el_reporte_responde_en_cero(): void
    {
        $resumen = $this->actingAs($this->admin())
            ->get(route('reportes.ventas', [
                'desde' => now()->subYears(3)->toDateString(),
                'hasta' => now()->subYears(3)->addDays(2)->toDateString(),
            ]))
            ->assertOk()
            ->viewData('resumen');

        $this->assertSame(0, $resumen['operaciones']);
        $this->assertSame(0.0, $resumen['vendido']);
        $this->assertSame(0.0, $resumen['ticket']);
    }

    // --------------------------------------------------- reporte de productos

    /**
     * El ranking del reporte es `v_productos_mas_vendidos` con filtro de
     * fechas: la vista no admite rango. Esta prueba compara ambos sobre todo
     * el histórico para que las fórmulas no se separen con el tiempo.
     */
    public function test_el_ranking_coincide_con_la_vista_del_esquema(): void
    {
        $sesion = $this->turno();
        $conDevolucion = $this->vender($sesion, 3, 'P-0004');
        $this->vender($sesion, 2, 'P-0009');

        // Con una devolución de por medio: es justo donde las dos fórmulas
        // podrían separarse sin que nadie se entere.
        Devoluciones::registrar($conDevolucion, $this->admin(), $sesion,
            [['venta_detalle_id' => $conDevolucion->detalle->first()->id, 'cantidad' => 1]],
            'Una unidad rota');

        $reporte = $this->actingAs($this->admin())
            ->get(route('reportes.productos', [
                'desde' => now()->subYears(5)->toDateString(),
                'hasta' => now()->toDateString(),
            ]))
            ->viewData('masVendidos')
            ->keyBy('id');

        $vista = DB::table('v_productos_mas_vendidos')->get()->keyBy('id');

        $this->assertNotEmpty($reporte);

        foreach ($reporte as $id => $fila) {
            $this->assertArrayHasKey($id, $vista, "El producto {$id} no está en la vista.");

            $this->assertSame($vista[$id]->unidades_vendidas, $fila->unidades_vendidas);
            $this->assertSame($vista[$id]->monto_vendido, $fila->monto_vendido);
            $this->assertSame($vista[$id]->margen_estimado, $fila->margen_estimado);
        }
    }

    /** Unidades, monto y margen van netos: lo que el negocio se quedó. */
    public function test_el_ranking_descuenta_lo_devuelto(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, 5, 'P-0004');
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 2]], 'Dos rotas');

        $fila = $this->actingAs($this->admin())
            ->get(route('reportes.productos', $this->hoy()))
            ->viewData('masVendidos')
            ->firstWhere('id', $producto->id);

        // 5 vendidas − 2 devueltas
        $this->assertSame(3.0, (float) $fila->unidades_vendidas);
        $this->assertSame(2.0, (float) $fila->unidades_devueltas);

        // El monto se prorratea por lo que el cliente se quedó: 3 × precio.
        $this->assertSame(
            round(3 * (float) $producto->precio_venta, 2),
            round((float) $fila->monto_vendido, 2),
        );

        // Y el margen compara ese neto contra el costo de esas mismas 3.
        $this->assertSame(
            round(3 * ((float) $producto->precio_venta - (float) $producto->precio_compra), 2),
            round((float) $fila->margen_estimado, 2),
        );
    }

    /** Devolver todo deja el producto en cero, no con importe y sin unidades. */
    public function test_un_producto_devuelto_por_completo_queda_en_cero(): void
    {
        $sesion = $this->turno();
        $venta = $this->vender($sesion, 4, 'P-0004');
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 4]], 'Se devolvió todo');

        $fila = $this->actingAs($this->admin())
            ->get(route('reportes.productos', $this->hoy()))
            ->viewData('masVendidos')
            ->firstWhere('id', $producto->id);

        $this->assertSame(0.0, (float) $fila->unidades_vendidas);
        $this->assertSame(0.0, (float) $fila->monto_vendido);
        $this->assertSame(0.0, (float) $fila->margen_estimado);
    }

    public function test_las_alertas_salen_de_la_vista_del_esquema(): void
    {
        $producto = Producto::where('codigo', 'P-0011')->firstOrFail();
        $producto->update(['stock_minimo' => (float) $producto->stock_actual + 10]);

        $alertas = $this->actingAs($this->admin())
            ->get(route('reportes.productos', $this->hoy()))
            ->viewData('alertas');

        $alerta = collect($alertas)->firstWhere('id', $producto->id);

        $this->assertNotNull($alerta);
        $this->assertSame(10.0, (float) $alerta->faltante);
        $this->assertSame(
            DB::table('v_alertas_stock')->count(),
            collect($alertas)->count(),
        );
    }

    public function test_el_valor_del_inventario_se_calcula_sobre_el_catalogo_vigente(): void
    {
        $inventario = $this->actingAs($this->admin())
            ->get(route('reportes.productos', $this->hoy()))
            ->viewData('inventario');

        $esperado = (float) DB::table('productos')->where('activo', 1)
            ->selectRaw('COALESCE(SUM(stock_actual * precio_compra), 0) AS costo')
            ->value('costo');

        $this->assertSame($esperado, $inventario['costo']);
        $this->assertSame(
            round($inventario['venta'] - $inventario['costo'], 2),
            $inventario['margen'],
        );
    }

    // ------------------------------------------------------------- interfaz

    /** El rango se anuncia en días enteros, ambos extremos incluidos. */
    public function test_la_pantalla_anuncia_los_dias_del_rango(): void
    {
        $this->actingAs($this->admin())
            ->get(route('reportes.ventas', [
                'desde' => now()->subDays(6)->toDateString(),
                'hasta' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('(7 días)');

        // Un solo día se anuncia en singular, y sin decimales sueltos.
        $this->actingAs($this->admin())
            ->get(route('reportes.ventas', $this->hoy()))
            ->assertSee('(1 día)');
    }

    /** El gráfico se dibuja desde el JSON que deja la plantilla. */
    public function test_la_pantalla_publica_los_datos_del_grafico(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion, 2);

        $this->actingAs($this->admin())
            ->get(route('reportes.ventas', $this->hoy()))
            ->assertOk()
            ->assertSee('data-apexchart', false)
            ->assertSee('Ventas por día', false);
    }

    public function test_el_menu_ofrece_los_reportes_a_quien_puede_verlos(): void
    {
        $this->actingAs($this->admin())
            ->get('/perfil')
            ->assertSee('href="'.url('/reportes/ventas').'"', false);

        $this->actingAs($this->cajero())
            ->get('/perfil')
            ->assertDontSee('href="'.url('/reportes/ventas').'"', false);
    }
}
