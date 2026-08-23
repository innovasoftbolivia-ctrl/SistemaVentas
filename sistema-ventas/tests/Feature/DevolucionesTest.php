<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Devolucion;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Devoluciones;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class DevolucionesTest extends TestCase
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

    private function producto(string $codigo = 'P-0004'): Producto
    {
        // P-0004 se vende por unidad (UND): evita fracciones en las cuentas.
        return Producto::where('codigo', $codigo)->firstOrFail();
    }

    private function turno(?Usuario $usuario = null, float $inicial = 200): SesionCaja
    {
        return Cajas::abrir(Caja::firstOrFail(), $usuario ?? $this->admin(), $inicial);
    }

    /** Venta de dos líneas: 3 de un producto y 2 de otro. */
    private function ventaConDosLineas(SesionCaja $sesion): Venta
    {
        return Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [
                ['producto_id' => $this->producto('P-0004')->id, 'cantidad' => 3, 'precio_unitario' => 3.39],
                ['producto_id' => $this->producto('P-0009')->id, 'cantidad' => 2, 'precio_unitario' => 2.37],
            ],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    // -------------------------------------------------------------- permisos

    /** Solo el rol con `devoluciones.registrar` puede registrarlas. */
    public function test_el_cajero_no_registra_devoluciones(): void
    {
        $sesion = $this->turno($this->cajero());
        $venta = $this->ventaConDosLineas($sesion);

        $this->actingAs($this->cajero())
            ->get("/ventas/{$venta->id}/devolver")
            ->assertForbidden();

        $this->actingAs($this->cajero())
            ->post("/ventas/{$venta->id}/devolver", [
                'motivo' => 'Producto en mal estado',
                'lineas' => [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]],
            ])
            ->assertForbidden();
    }

    public function test_el_administrador_entra_a_devoluciones(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        $this->actingAs($this->admin())->get('/devoluciones')->assertOk();
        $this->actingAs($this->admin())->get("/ventas/{$venta->id}/devolver")->assertOk();
    }

    // ------------------------------------------------------------ parciales

    public function test_una_devolucion_parcial_reingresa_stock_y_deja_la_venta_parcial(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto('P-0004');
        $stockAntes = (float) $producto->fresh()->stock_actual;

        $venta = $this->ventaConDosLineas($sesion);
        $linea = $venta->detalle->firstWhere('producto_id', $producto->id);

        // La venta ya descontó 3.
        $this->assertSame($stockAntes - 3, (float) $producto->fresh()->stock_actual);

        $devolucion = Devoluciones::registrar(
            venta: $venta,
            usuario: $this->admin(),
            sesion: $sesion,
            lineas: [['venta_detalle_id' => $linea->id, 'cantidad' => 2]],
            motivo: 'Dos unidades llegaron abolladas',
        );

        // Vuelven 2 al estante.
        $this->assertSame($stockAntes - 1, (float) $producto->fresh()->stock_actual);

        $this->assertSame('PARCIAL', $devolucion->tipo);
        // 2 × 3.39 = 6.78 de base, más 1.22 de impuesto
        $this->assertSame('8.00', $devolucion->total);
        $this->assertSame('DEVUELTA_PARCIAL', $venta->fresh()->estado);
        $this->assertSame('8.00', $venta->fresh()->total_devuelto);
        $this->assertSame('2.000', $linea->fresh()->cantidad_devuelta);

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $producto->id,
            'devolucion_id' => $devolucion->id,
            'origen' => 'DEVOLUCION',
            'tipo' => 'ENTRADA',
        ]);
    }

    /** Devolver todas las líneas deja la venta en DEVUELTA y la marca TOTAL. */
    public function test_devolver_todo_deja_la_venta_devuelta(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        $devolucion = Devoluciones::registrar(
            venta: $venta,
            usuario: $this->admin(),
            sesion: $sesion,
            lineas: $venta->detalle->map(fn ($l) => [
                'venta_detalle_id' => $l->id,
                'cantidad' => (float) $l->cantidad,
            ])->all(),
            motivo: 'El cliente devolvió toda la compra',
        );

        $this->assertSame('TOTAL', $devolucion->tipo);
        $this->assertSame('DEVUELTA', $venta->fresh()->estado);
        // Devolver todo reintegra exactamente lo que la venta cobro.
        $this->assertSame($venta->fresh()->total, $devolucion->total);
    }

    /** Se puede devolver en varias veces hasta agotar la venta. */
    public function test_dos_devoluciones_sucesivas_agotan_la_venta(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        [$primera, $segunda] = [$venta->detalle[0], $venta->detalle[1]];

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $primera->id, 'cantidad' => (float) $primera->cantidad]],
            'Primera tanda');

        $this->assertSame('DEVUELTA_PARCIAL', $venta->fresh()->estado);

        $ultima = Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
            [['venta_detalle_id' => $segunda->id, 'cantidad' => (float) $segunda->cantidad]],
            'Segunda tanda');

        $this->assertSame('TOTAL', $ultima->tipo);
        $this->assertSame('DEVUELTA', $venta->fresh()->estado);
        $this->assertSame(2, $venta->fresh()->devoluciones()->count());
    }

    // ------------------------------------------------------------- límites

    public function test_no_se_devuelve_mas_de_lo_vendido(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        $linea = $venta->detalle->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sin devolver');

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $linea->id, 'cantidad' => (float) $linea->cantidad + 1]],
            'Más de lo que compró');
    }

    public function test_no_se_devuelve_dos_veces_la_misma_unidad(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        $linea = $venta->detalle->first(); // cantidad 3

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $linea->id, 'cantidad' => 3]], 'Todo de esta línea');

        $this->expectException(RuntimeException::class);

        Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
            [['venta_detalle_id' => $linea->id, 'cantidad' => 1]], 'Otra vez');
    }

    public function test_una_venta_anulada_no_admite_devolucion(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        Ventas::anular($venta, $this->admin(), 'Error de cobro');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('anulada');

        Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]], 'Tarde');
    }

    public function test_una_venta_ya_devuelta_no_admite_otra_devolucion(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            $venta->detalle->map(fn ($l) => ['venta_detalle_id' => $l->id, 'cantidad' => (float) $l->cantidad])->all(),
            'Todo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('por completo');

        Devoluciones::registrar($venta->fresh(), $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]], 'De nuevo');
    }

    public function test_una_linea_de_otra_venta_se_rechaza(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        $otra = $this->ventaConDosLineas($sesion);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no pertenece');

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $otra->detalle->first()->id, 'cantidad' => 1]], 'Línea ajena');
    }

    public function test_una_devolucion_sin_cantidades_se_rechaza(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ninguna cantidad');

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 0]], 'Nada');
    }

    /** Media unidad de jabón no vuelve al estante. */
    public function test_una_unidad_sin_decimales_rechaza_fracciones(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unidad entera');

        Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1.5]], 'Media unidad');
    }

    // ------------------------------------------------- mercadería dañada

    /**
     * Lo que llega roto se paga pero no vuelve al inventario: el dinero sale y
     * el stock se queda como estaba.
     */
    public function test_lo_que_no_reingresa_devuelve_dinero_pero_no_stock(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto('P-0004');

        $venta = $this->ventaConDosLineas($sesion);
        $linea = $venta->detalle->firstWhere('producto_id', $producto->id);
        $stockTrasVenta = (float) $producto->fresh()->stock_actual;

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $linea->id, 'cantidad' => 2, 'reingresa_stock' => false]],
            'Llegaron vencidas');

        $this->assertSame($stockTrasVenta, (float) $producto->fresh()->stock_actual);
        $this->assertSame('8.00', $devolucion->total);

        // La línea igual cuenta como devuelta para la venta.
        $this->assertSame('2.000', $linea->fresh()->cantidad_devuelta);

        $this->assertDatabaseMissing('movimientos_inventario', [
            'devolucion_id' => $devolucion->id,
            'origen' => 'DEVOLUCION',
        ]);
    }

    // ------------------------------------------------------------------ caja

    /** El dinero devuelto sale del cajón y el arqueo tiene que reflejarlo. */
    public function test_la_devolucion_baja_el_efectivo_esperado(): void
    {
        $sesion = $this->turno(inicial: 200);
        $venta = $this->ventaConDosLineas($sesion);

        $conVenta = $sesion->efectivoEsperado();

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 2]],
            'Producto en mal estado');

        $esperado = round($conVenta - (float) $devolucion->total, 2);

        $this->assertSame($esperado, $sesion->efectivoEsperado());

        // El cierre de la base tiene que llegar al mismo número.
        $cerrada = Cajas::cerrar($sesion, $this->admin(), $esperado);

        $this->assertSame(number_format($esperado, 2, '.', ''), $cerrada->monto_esperado);
        $this->assertSame('0.00', $cerrada->diferencia);
    }

    public function test_no_se_devuelve_sin_caja_abierta(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        Cajas::cerrar($sesion, $this->admin(), $sesion->efectivoEsperado());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caja abierta');

        Devoluciones::registrar($venta, $this->admin(), $sesion->fresh(),
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]], 'Sin caja');
    }

    // ------------------------------------------------------------------ HTTP

    public function test_la_devolucion_se_registra_de_punta_a_punta(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        $producto = $this->producto('P-0004');
        $stockTrasVenta = (float) $producto->fresh()->stock_actual;

        $respuesta = $this->actingAs($this->admin())->post("/ventas/{$venta->id}/devolver", [
            'motivo' => 'El cliente devolvió dos unidades por estar vencidas',
            'lineas' => [
                // El formulario manda todas las líneas; las de cantidad 0 se descartan.
                ['venta_detalle_id' => $venta->detalle[0]->id, 'cantidad' => 2, 'reingresa_stock' => 1],
                ['venta_detalle_id' => $venta->detalle[1]->id, 'cantidad' => 0, 'reingresa_stock' => 1],
            ],
        ]);

        $devolucion = Devolucion::where('venta_id', $venta->id)->firstOrFail();

        $respuesta->assertRedirect(route('devoluciones.show', $devolucion));

        $this->assertSame('8.00', $devolucion->total);
        $this->assertSame(1, $devolucion->detalle()->count());
        $this->assertSame($stockTrasVenta + 2, (float) $producto->fresh()->stock_actual);

        $this->actingAs($this->admin())->get("/devoluciones/{$devolucion->id}")->assertOk();
    }

    public function test_la_devolucion_exige_motivo(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        $this->actingAs($this->admin())
            ->post("/ventas/{$venta->id}/devolver", [
                'motivo' => '',
                'lineas' => [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('motivo');
    }

    /**
     * Se le devuelve al cliente lo que pagó: base más impuesto. La tasa sale
     * de la línea de venta original, no de la configuración de hoy.
     */
    public function test_el_total_devuelto_incluye_el_impuesto(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        $linea = $venta->detalle->first(); // 3 × 3.39, afecta al 18%

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $linea->id, 'cantidad' => 3]], 'Producto vencido');

        // Base 10.17 + impuesto 1.83 = lo que el cliente pagó por esas 3 unidades.
        $this->assertSame('12.00', $devolucion->total);
        $this->assertSame(10.17, $devolucion->base);
        $this->assertSame(1.83, $devolucion->impuesto_devuelto);

        // Coincide con lo que la venta cobró por esa línea.
        $this->assertSame($linea->fresh()->total_linea, $devolucion->total);
    }

    /** La tasa se congela al devolver: si cambia el IGV, lo ya devuelto no. */
    public function test_la_tasa_se_copia_de_la_linea_de_venta(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);
        $linea = $venta->detalle->first();

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $linea->id, 'cantidad' => 1]], 'Una unidad');

        $devuelta = $devolucion->detalle->first();

        $this->assertSame($linea->tasa_impuesto, $devuelta->tasa_impuesto);
        $this->assertTrue($devuelta->afecto_impuesto);
    }

    /** Un producto exonerado se devuelve sin impuesto encima. */
    public function test_un_producto_exonerado_se_devuelve_sin_impuesto(): void
    {
        $sesion = $this->turno();
        $producto = $this->producto('P-0011');
        $producto->update(['afecto_impuesto' => false]);

        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $this->admin(),
            lineas: [['producto_id' => $producto->id, 'cantidad' => 4, 'precio_unitario' => 1.02]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 4]], 'Galletas rotas');

        $this->assertSame('4.08', $devolucion->total); // 4 × 1.02, sin impuesto
        $this->assertSame(0.0, $devolucion->impuesto_devuelto);
    }

    public function test_queda_auditada_con_su_responsable(): void
    {
        $sesion = $this->turno();
        $venta = $this->ventaConDosLineas($sesion);

        $devolucion = Devoluciones::registrar($venta, $this->admin(), $sesion,
            [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]],
            'Producto equivocado');

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'DEVOLUCION_REGISTRADA',
            'entidad' => 'devoluciones',
            'entidad_id' => $devolucion->id,
            'usuario_id' => $this->admin()->id,
        ]);
    }
}
