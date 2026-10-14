<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\MetodoPago;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Compras;
use App\Services\Costos;
use App\Services\Inventario;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Segunda auditoría de octubre de 2026: el costo con cuatro decimales.
 *
 * Una caja de 24 a Bs 70 son 2,9167 por unidad. Con dos decimales (2,92) la compra
 * sumaba 24 × 2,92 = 70,08 contra los 70,00 de la factura del proveedor, y la ganancia
 * de cada venta de ese producto arrastraba el mismo error.
 */
class CostoConCuatroDecimalesTest extends TestCase
{
    use DatabaseTransactions;

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    /** Un producto entero que llega en cajas de 24 y se vende suelto. */
    private function productoEnCajas(): Producto
    {
        $producto = Producto::activos()
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))
            ->firstOrFail();
        $producto->forceFill([
            'contenido_empaque' => 24, 'nombre_empaque' => 'Caja', 'precio_compra' => '4.00',
            'precio_venta' => '5.00', 'afecto_impuesto' => 0, 'controla_vencimiento' => 0,
        ])->save();

        return $producto->fresh();
    }

    private function comprar(Producto $producto, float $unidades, string $costo, bool $actualizar = false): Compra
    {
        return Compras::registrar(
            $this->u('almacen'),
            Proveedor::activos()->firstOrFail(),
            [['producto_id' => $producto->id, 'cantidad' => $unidades, 'costo_unitario' => $costo, 'actualizar_costo' => $actualizar]],
            'FAC-2026-70',
        );
    }

    // ============================================================ la compra cuadra con el papel

    public function test_la_caja_de_24_a_70_cuadra_con_la_factura_del_proveedor(): void
    {
        $producto = $this->productoEnCajas();

        // El formulario manda el costo por unidad: 70 ÷ 24 = 2,9167.
        $compra = $this->comprar($producto, 24, '2.9167');
        $linea = $compra->detalle->first();

        $this->assertSame('2.9167', $linea->costo_unitario);
        $this->assertSame('70.00', $linea->importe, 'antes: 24 × 2,92 = 70,08');
        $this->assertEquals(70.0, $compra->fresh()->total);
    }

    public function test_el_costo_por_caja_que_escribe_el_almacenero_se_divide_a_cuatro_decimales(): void
    {
        $producto = $this->productoEnCajas();

        $this->actingAs($this->u('almacen'))->post(route('inventario.ingreso'), [
            'producto_id' => $producto->id, 'empaques' => 1, 'costo_unitario' => '70.00', 'costo_por' => 'EMPAQUE',
        ])->assertSessionHasNoErrors();

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame('2.9167', $movimiento->costo_unitario);
    }

    public function test_desde_la_ficha_del_producto_tambien(): void
    {
        $producto = $this->productoEnCajas();

        $this->actingAs($this->u('almacen'))->post(route('productos.ingreso', $producto), [
            'empaques' => 1, 'costo_unitario' => '70.00', 'costo_por' => 'EMPAQUE',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2.9167', MovimientoInventario::where('producto_id', $producto->id)->orderByDesc('id')->firstOrFail()->costo_unitario);
    }

    public function test_el_alta_de_un_producto_por_caja_guarda_el_costo_unitario_con_cuatro_decimales(): void
    {
        $this->actingAs($this->u('admin'))->post(route('productos.store'), [
            'categoria_id' => Categoria::first()->id,
            'unidad_medida_id' => UnidadMedida::where('codigo', 'UND')->first()->id,
            'proveedor_id' => Proveedor::first()->id,
            'codigo' => 'P-9701', 'codigo_barras' => '7750009997011', 'nombre' => 'Producto por caja',
            'viene_en_empaque' => 1, 'nombre_empaque' => 'Caja', 'contenido_empaque' => 24,
            'precio_compra' => '70.00', 'precio_compra_por' => 'EMPAQUE', 'precio_venta' => '5.00',
            'afecto_impuesto' => 0, 'stock_minimo' => '1', 'activo' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('2.9167', Producto::where('codigo', 'P-9701')->firstOrFail()->precio_compra);
    }

    // ============================================================ el costo del producto

    public function test_actualizar_el_costo_deja_los_cuatro_decimales_en_el_producto_y_en_la_bitacora(): void
    {
        $producto = $this->productoEnCajas();

        $this->comprar($producto, 24, '2.9167', actualizar: true);

        $this->assertSame('2.9167', $producto->fresh()->precio_compra);
        $huella = Auditoria::where('accion', 'CAMBIO_COSTO')->where('entidad_id', $producto->id)->latest('id')->firstOrFail();
        $this->assertEquals(2.9167, $huella->detalle['nuevo']);
        $this->assertEquals(4.0, $huella->detalle['anterior']);
    }

    public function test_un_cambio_en_el_tercer_o_cuarto_decimal_es_un_cambio(): void
    {
        $producto = $this->productoEnCajas();
        $producto->forceFill(['precio_compra' => '2.9167'])->save();

        $this->assertNull(Costos::aplicar($producto->fresh(), 2.9167, 'prueba'), 'el mismo costo no cambia nada');

        $cambio = Costos::aplicar($producto->fresh(), 2.9166, 'prueba');
        $this->assertNotNull($cambio, 'antes, 2,9166 y 2,9167 se redondeaban los dos a 2,92 y el cambio se perdía');
        $this->assertSame('2.9166', $producto->fresh()->precio_compra);
    }

    public function test_los_costos_de_siempre_no_cambian_de_valor(): void
    {
        $producto = $this->productoEnCajas();

        $this->assertSame('4.0000', $producto->precio_compra);
        $this->assertSame('Bs 4.00', Config::costo($producto->precio_compra));
    }

    // ============================================================ la venta y la ganancia

    private function vender(Producto $producto, int $unidades)
    {
        $cajero = $this->u('cajero1');
        $caja = Caja::orderBy('id')->firstOrFail();
        $turno = Cajas::sesionDe($cajero) ?? Cajas::abrir($caja, $cajero, 100, 'Apertura de prueba');

        return Ventas::registrar(
            sesion: $turno,
            usuario: $cajero,
            lineas: [['producto_id' => $producto->id, 'cantidad' => $unidades]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    public function test_la_venta_guarda_el_costo_del_dia_con_cuatro_decimales(): void
    {
        $producto = $this->productoEnCajas();
        $this->comprar($producto, 24, '2.9167', actualizar: true);

        $venta = $this->vender($producto, 24);

        $this->assertSame('2.9167', $venta->detalle->first()->costo_unitario);
        // 24 unidades vendidas cuestan 70,00 (70,0008 sin redondear), no los 70,08 de antes.
        $costo = (float) DB::table('venta_detalle')->where('venta_id', $venta->id)->selectRaw('SUM(cantidad * costo_unitario) AS c')->value('c');
        $this->assertEqualsWithDelta(70.0008, $costo, 0.00001);
    }

    public function test_el_valor_del_inventario_se_calcula_con_el_costo_exacto(): void
    {
        $producto = $this->productoEnCajas();
        $producto->forceFill(['precio_compra' => '2.9167', 'stock_actual' => 0])->save();
        Inventario::ingreso($producto->fresh(), 24, usuarioId: $this->u('admin')->id);

        $this->assertSame(70.0, $producto->fresh()->valor_inventario);
    }

    // ============================================================ la pantalla

    public function test_las_pantallas_muestran_el_costo_con_los_decimales_que_tiene(): void
    {
        $producto = $this->productoEnCajas();
        $this->comprar($producto, 24, '2.9167', actualizar: true);
        $admin = $this->u('admin');

        $this->actingAs($admin)->get(route('productos.show', $producto))->assertOk()->assertSee('Bs 2.9167');
        $this->actingAs($admin)->get(route('productos.index', ['buscar' => $producto->codigo]))->assertOk()->assertSee('Bs 2.9167');

        $compra = Compra::latest('id')->firstOrFail();
        $this->actingAs($admin)->get(route('compras.show', $compra))->assertOk()->assertSee('Bs 2.9167');

        // Un costo al centavo se sigue leyendo al centavo.
        $otro = Producto::where('id', '<>', $producto->id)->firstOrFail();
        $otro->forceFill(['precio_compra' => '4.5000'])->save();
        $this->actingAs($admin)->get(route('productos.show', $otro))->assertOk()->assertSee('Bs 4.50')->assertDontSee('Bs 4.5000');
    }

    public function test_los_campos_de_costo_aceptan_cuatro_decimales(): void
    {
        $admin = $this->u('admin');
        $producto = $this->productoEnCajas();

        $this->actingAs($admin)->get(route('productos.create'))->assertOk()
            ->assertSee('id="precio_compra"', false)->assertSee('step="0.0001"', false);
        $this->actingAs($admin)->get(route('compras.create'))->assertOk()->assertSee('step="0.0001"', false);
        $this->actingAs($admin)->get(route('inventario.index'))->assertOk()->assertSee('step="0.0001"', false);
        $this->actingAs($admin)->get(route('productos.show', $producto))->assertOk()->assertSee('step="0.0001"', false);
    }

    public function test_el_formato_de_un_costo(): void
    {
        foreach ([
            [4.5, 'Bs 4.50'], ['4.5000', 'Bs 4.50'], [2.9167, 'Bs 2.9167'], [2.91666, 'Bs 2.9167'], [2.9100, 'Bs 2.91'],
            [1234.5, 'Bs 1,234.50'], [0, 'Bs 0.00'], ['0.0001', 'Bs 0.0001'], [10, 'Bs 10.00'], [0.125, 'Bs 0.125'], [null, 'Bs 0.00'],
        ] as [$valor, $esperado]) {
            $this->assertSame($esperado, Config::costo($valor), var_export($valor, true));
        }
    }

    // ============================================================ la base

    public function test_las_seis_columnas_de_costo_tienen_cuatro_decimales(): void
    {
        foreach ([
            ['productos', 'precio_compra'], ['compra_detalle', 'costo_unitario'], ['devolucion_compra_detalle', 'costo_unitario'],
            ['movimientos_inventario', 'costo_unitario'], ['toma_inventario_detalle', 'costo_unitario'], ['venta_detalle', 'costo_unitario'],
        ] as [$tabla, $columna]) {
            $this->assertSame(4, (int) DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $tabla)->where('COLUMN_NAME', $columna)
                ->value('NUMERIC_SCALE'), "{$tabla}.{$columna}");
        }
    }

    public function test_los_importes_de_las_lineas_siguen_al_centavo(): void
    {
        foreach ([['compra_detalle', 'importe'], ['devolucion_compra_detalle', 'importe']] as [$tabla, $columna]) {
            $this->assertSame(2, (int) DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $tabla)->where('COLUMN_NAME', $columna)
                ->value('NUMERIC_SCALE'), "{$tabla}.{$columna}: se paga y se declara al centavo");
        }
    }
}
