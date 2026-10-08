<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\MetodoPago;
use App\Models\MovimientoCaja;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SesionCaja;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Support\Config;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Tercer bloque de la auditoría de octubre de 2026: lo que acelera el mostrador
 * en las primeras semanas y lo que evita errores de plata.
 *
 *   - aviso de conexión, código desconocido, vaciar con «Deshacer» y ventas en
 *     espera (la parte de JavaScript se comprueba en el navegador; aquí, que la
 *     pantalla traiga las piezas y que el servidor haga lo suyo);
 *   - la ficha de la venta recién cobrada, con el vuelto y «Nueva venta»;
 *   - vender por debajo del costo se confirma a propósito;
 *   - anular un movimiento de caja con un contra-asiento enlazado.
 */
class MejorasDelMostradorTest extends TestCase
{
    use DatabaseTransactions;

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function turno(?Usuario $usuario = null, float $inicial = 100): SesionCaja
    {
        $usuario ??= $this->u('cajero1');

        return Cajas::sesionDe($usuario) ?? Cajas::abrir(Caja::firstOrFail(), $usuario, $inicial);
    }

    private function efectivo(): int
    {
        return (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id');
    }

    // ============================================================ la pantalla

    public function test_el_mostrador_trae_las_piezas_nuevas(): void
    {
        $this->turno($this->u('admin'));

        $this->actingAs($this->u('admin'))->get(route('pos.index'))->assertOk()
            ->assertSee('data-sin-conexion', false)
            ->assertSee('data-sesion-vencida', false)
            ->assertSee('data-asignar-codigo', false)
            ->assertSee('data-asignar-panel', false)
            ->assertSee('data-vaciar', false)
            ->assertSee('data-vaciar-confirmar', false)
            ->assertSee('data-deshacer-vaciar', false)
            ->assertSee('data-poner-en-espera', false)
            ->assertSee('data-retomar', false)
            ->assertSee('@online.window="reintentarConexion()"', false)
            ->assertSee('@offline.window="conexionPerdida()"', false)
            ->assertSee('puedeAsignarCodigo: true', false);
    }

    public function test_quien_no_puede_editar_el_catalogo_no_ve_el_boton_de_asignar(): void
    {
        $this->turno($this->u('cajero1'));

        $this->actingAs($this->u('cajero1'))->get(route('pos.index'))->assertOk()
            ->assertSee('puedeAsignarCodigo: false', false)
            ->assertSee('Avisa al encargado', false);
    }

    public function test_las_ventas_en_espera_se_guardan_por_usuario_y_turno(): void
    {
        $turno = $this->turno($this->u('admin'));

        $this->actingAs($this->u('admin'))->get(route('pos.index'))
            ->assertSee("pos-esperas-{$this->u('admin')->id}-{$turno->id}", false);
    }

    public function test_el_mostrador_ya_no_pierde_el_carrito_en_silencio_con_un_toque(): void
    {
        $this->turno($this->u('admin'));

        // «Vaciar» ya no borra directo: pasa por pedirVaciar() (confirma si son
        // tres líneas o más) y deja «Deshacer».
        $html = $this->actingAs($this->u('admin'))->get(route('pos.index'))->getContent();

        $this->assertStringNotContainsString('@click="carrito = []"', $html);
        $this->assertStringContainsString('@click="pedirVaciar()"', $html);
        $this->assertStringContainsString('@click="deshacerVaciar()"', $html);
    }

    public function test_cobrar_sin_poder_verificar_los_precios_no_envia_la_venta(): void
    {
        $this->turno($this->u('admin'));
        $html = $this->actingAs($this->u('admin'))->get(route('pos.index'))->getContent();

        // Sin red, `refrescarPrecios` lanza y se avisa: antes fallaba en silencio.
        $this->assertStringContainsString('this.conexionPerdida();', $html);
        $this->assertStringContainsString("if (! respuesta.ok) throw new Error('http ' + respuesta.status);", $html);
        // Y sin conexión (o con la sesión vencida) no se puede cobrar.
        $this->assertStringContainsString('if (this.sinConexion || this.sesionVencida) return false;', $html);
        // Un escaneo sin respuesta no decide nada: no dice «no existe».
        $this->assertStringContainsString('if (! await this.cargar()) {', $html);
    }

    // ============================================================ asignar un código desconocido

    private function productoSinCodigo(): Producto
    {
        $producto = Producto::activos()->orderBy('id')->firstOrFail();
        $producto->forceFill(['codigo_barras' => null])->save();

        return $producto->fresh();
    }

    public function test_asigna_el_codigo_escaneado_a_un_producto_que_no_lo_tenia(): void
    {
        $this->turno($this->u('admin'));
        $producto = $this->productoSinCodigo();

        $this->actingAs($this->u('admin'))
            ->postJson(route('pos.asignar-codigo'), ['producto_id' => $producto->id, 'codigo_barras' => '7771112223334'])
            ->assertOk()
            ->assertJson(['id' => $producto->id, 'codigo_barras' => '7771112223334']);

        $this->assertSame('7771112223334', $producto->fresh()->codigo_barras);

        $registro = Auditoria::where('accion', 'CODIGO_BARRAS_ASIGNADO')->latest('id')->firstOrFail();
        $this->assertSame($producto->id, (int) $registro->entidad_id);
        $this->assertSame('mostrador', $registro->detalle['desde']);
    }

    public function test_despues_de_asignarlo_el_escaneo_encuentra_el_producto(): void
    {
        $this->turno($this->u('admin'));
        $producto = $this->productoSinCodigo();
        $admin = $this->u('admin');

        $this->actingAs($admin)->getJson(route('pos.productos', ['q' => '7771112223334']))->assertOk()->assertJsonCount(0);

        $this->actingAs($admin)->postJson(route('pos.asignar-codigo'), ['producto_id' => $producto->id, 'codigo_barras' => '7771112223334'])->assertOk();

        $this->actingAs($admin)->getJson(route('pos.productos', ['q' => '7771112223334']))
            ->assertOk()->assertJsonPath('0.id', $producto->id)->assertJsonPath('0.codigo_barras', '7771112223334');
    }

    public function test_no_pisa_el_codigo_de_un_producto_que_ya_lo_tiene(): void
    {
        $this->turno($this->u('admin'));
        $producto = Producto::activos()->orderBy('id')->firstOrFail();
        $producto->forceFill(['codigo_barras' => '7770000000001'])->save();

        $this->actingAs($this->u('admin'))
            ->postJson(route('pos.asignar-codigo'), ['producto_id' => $producto->id, 'codigo_barras' => '7771112223334'])
            ->assertStatus(422)->assertJsonValidationErrors('producto_id');

        $this->assertSame('7770000000001', $producto->fresh()->codigo_barras);
    }

    public function test_rechaza_un_codigo_que_ya_es_de_otro_producto_incluso_desactivado(): void
    {
        $this->turno($this->u('admin'));
        [$a, $b] = Producto::activos()->orderBy('id')->take(2)->get()->all();
        $b->forceFill(['codigo_barras' => null])->save();
        $a->forceFill(['codigo_barras' => '7770000000002'])->save();

        $respuesta = $this->actingAs($this->u('admin'))
            ->postJson(route('pos.asignar-codigo'), ['producto_id' => $b->id, 'codigo_barras' => '7770000000002'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo_barras');
        $this->assertStringContainsString($a->nombre, $respuesta->json('errors.codigo_barras.0'));

        $a->forceFill(['activo' => 0])->save();
        $respuesta = $this->actingAs($this->u('admin'))
            ->postJson(route('pos.asignar-codigo'), ['producto_id' => $b->id, 'codigo_barras' => '7770000000002'])
            ->assertStatus(422);
        $this->assertStringContainsString('desactivado', $respuesta->json('errors.codigo_barras.0'));
        $this->assertNull($b->fresh()->codigo_barras);
    }

    public function test_rechaza_codigos_con_letras_productos_que_no_existen_y_datos_faltantes(): void
    {
        $this->turno($this->u('admin'));
        $producto = $this->productoSinCodigo();
        $admin = $this->u('admin');

        $this->actingAs($admin)->postJson(route('pos.asignar-codigo'), ['producto_id' => $producto->id, 'codigo_barras' => '12AB'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo_barras');
        $this->actingAs($admin)->postJson(route('pos.asignar-codigo'), ['producto_id' => 99999999, 'codigo_barras' => '7771112223334'])
            ->assertStatus(422)->assertJsonValidationErrors('producto_id');
        $this->actingAs($admin)->postJson(route('pos.asignar-codigo'), [])
            ->assertStatus(422)->assertJsonValidationErrors(['producto_id', 'codigo_barras']);

        $producto->forceFill(['activo' => 0])->save();
        $this->actingAs($admin)->postJson(route('pos.asignar-codigo'), ['producto_id' => $producto->id, 'codigo_barras' => '7771112223334'])
            ->assertStatus(422)->assertJsonValidationErrors('producto_id');
    }

    public function test_el_cajero_no_puede_editar_el_catalogo_desde_el_mostrador(): void
    {
        $this->turno($this->u('cajero1'));
        $producto = $this->productoSinCodigo();

        $this->actingAs($this->u('cajero1'))
            ->postJson(route('pos.asignar-codigo'), ['producto_id' => $producto->id, 'codigo_barras' => '7771112223334'])
            ->assertForbidden();

        // El almacenero edita el catálogo pero no vende: la ruta pide las dos cosas.
        $this->actingAs($this->u('almacen'))
            ->postJson(route('pos.asignar-codigo'), ['producto_id' => $producto->id, 'codigo_barras' => '7771112223334'])
            ->assertForbidden();

        $this->assertNull($producto->fresh()->codigo_barras);
    }

    // ============================================================ la venta recién cobrada

    private function cobrar(Usuario $usuario, float $recibido): TestResponse
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        $producto->forceFill(['stock_actual' => 50])->save();

        return $this->actingAs($usuario)->followingRedirects()->post(route('pos.store'), [
            '_envio' => 'venta-'.uniqid(),
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 2]],
            'pagos' => [['metodo_pago_id' => $this->efectivo(), 'monto_recibido' => $recibido]],
        ]);
    }

    public function test_al_cobrar_la_ficha_muestra_el_vuelto_y_el_boton_de_nueva_venta(): void
    {
        $this->turno($this->u('admin'));

        $respuesta = $this->cobrar($this->u('admin'), 20)->assertOk();

        $venta = Venta::latest('id')->firstOrFail();
        $vuelto = 20 - (float) $venta->total;

        $respuesta->assertSee('data-venta-recien', false)
            ->assertSee('Venta registrada')
            ->assertSee('Entrega de vuelto')
            ->assertSee(Config::importe($vuelto))
            ->assertSee(Config::importe($venta->total))
            ->assertSee('Recibido en efectivo')
            ->assertSee('data-nueva-venta', false)
            ->assertSee('href="'.route('pos.index').'"', false);
    }

    public function test_si_no_hay_vuelto_no_se_muestra(): void
    {
        $this->turno($this->u('admin'));
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        $producto->forceFill(['stock_actual' => 50])->save();

        // Sin `monto_recibido`: el sistema cobra el importe exacto.
        $this->actingAs($this->u('admin'))->followingRedirects()->post(route('pos.store'), [
            '_envio' => 'venta-'.uniqid(),
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            'pagos' => [['metodo_pago_id' => $this->efectivo()]],
        ])->assertOk()->assertSee('data-venta-recien', false)->assertDontSee('data-vuelto-recien', false);
    }

    public function test_el_panel_es_solo_al_cobrar_no_cuando_se_vuelve_a_abrir_la_venta(): void
    {
        $this->turno($this->u('admin'));
        $this->cobrar($this->u('admin'), 20);
        $venta = Venta::latest('id')->firstOrFail();

        $this->actingAs($this->u('admin'))->get(route('ventas.show', $venta))
            ->assertOk()->assertDontSee('data-venta-recien', false);
    }

    // ============================================================ vender bajo el costo

    private function datosProducto(array $sobrescribir = []): array
    {
        return [
            'categoria_id' => Categoria::first()->id,
            'unidad_medida_id' => UnidadMedida::where('codigo', 'UND')->first()->id,
            'proveedor_id' => Proveedor::first()->id,
            'codigo' => 'P-9301',
            'codigo_barras' => '7750009999301',
            'nombre' => 'Producto bajo costo',
            'precio_compra' => '5.00',
            'precio_venta' => '3.00',
            'afecto_impuesto' => 1,
            'stock_minimo' => '1',
            'activo' => 1,
            ...$sobrescribir,
        ];
    }

    public function test_crear_un_producto_bajo_el_costo_exige_confirmarlo(): void
    {
        $antes = Producto::count();

        $this->actingAs($this->u('admin'))->post(route('productos.store'), $this->datosProducto())
            ->assertSessionHasErrors('precio_venta');

        $this->assertSame($antes, Producto::count());
        $mensaje = session('errors')->first('precio_venta');
        $this->assertStringContainsString('por debajo del costo', $mensaje);
        $this->assertStringContainsString('Vender a pérdida a propósito', $mensaje);
    }

    public function test_confirmado_a_proposito_se_crea(): void
    {
        $this->actingAs($this->u('admin'))->post(route('productos.store'), $this->datosProducto(['confirma_perdida' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Producto::where('codigo', 'P-9301')->first());
    }

    public function test_al_costo_exacto_o_con_ganancia_no_pide_nada(): void
    {
        $this->actingAs($this->u('admin'))->post(route('productos.store'), $this->datosProducto(['precio_venta' => '5.00']))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->u('admin'))->post(route('productos.store'), $this->datosProducto([
            'codigo' => 'P-9302', 'codigo_barras' => '7750009999302', 'precio_venta' => '8.00',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, Producto::whereIn('codigo', ['P-9301', 'P-9302'])->count());
    }

    public function test_el_costo_por_caja_se_compara_por_unidad(): void
    {
        // Una caja de 12 a Bs 60 son Bs 5 la unidad: venderla a 4 es pérdida...
        $caja = [
            'viene_en_empaque' => 1, 'nombre_empaque' => 'Caja', 'contenido_empaque' => 12,
            'precio_compra' => '60.00', 'precio_compra_por' => 'EMPAQUE',
        ];

        $this->actingAs($this->u('admin'))->post(route('productos.store'), $this->datosProducto($caja + ['precio_venta' => '4.00']))
            ->assertSessionHasErrors('precio_venta');

        // ...y a 6 no. Sin esta división, 6 < 60 daría una pérdida falsa.
        $this->actingAs($this->u('admin'))->post(route('productos.store'), $this->datosProducto($caja + ['precio_venta' => '6.00']))
            ->assertSessionHasNoErrors();
    }

    public function test_editar_otra_cosa_de_un_producto_que_ya_se_vendia_a_perdida_no_se_bloquea(): void
    {
        $this->actingAs($this->u('admin'))->post(route('productos.store'), $this->datosProducto(['confirma_perdida' => 1]))->assertSessionHasNoErrors();
        $producto = Producto::where('codigo', 'P-9301')->firstOrFail();

        // Mismos precios, otro nombre: no se vuelve a pedir la casilla.
        $this->actingAs($this->u('admin'))->put(route('productos.update', $producto), $this->datosProducto(['nombre' => 'Otro nombre']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Otro nombre', $producto->fresh()->nombre);

        // Pero cambiar el precio a otro precio de pérdida sí lo pide.
        $this->actingAs($this->u('admin'))->put(route('productos.update', $producto), $this->datosProducto(['precio_venta' => '2.00']))
            ->assertSessionHasErrors('precio_venta');
        $this->assertEquals(3.00, (float) $producto->fresh()->precio_venta);

        $this->actingAs($this->u('admin'))->put(route('productos.update', $producto), $this->datosProducto(['precio_venta' => '2.00', 'confirma_perdida' => 1]))
            ->assertSessionHasNoErrors();
        $this->assertEquals(2.00, (float) $producto->fresh()->precio_venta);
    }

    public function test_subir_el_costo_por_encima_del_precio_tambien_lo_pide(): void
    {
        $this->actingAs($this->u('admin'))->post(route('productos.store'), $this->datosProducto(['precio_venta' => '8.00']))->assertSessionHasNoErrors();
        $producto = Producto::where('codigo', 'P-9301')->firstOrFail();

        $this->actingAs($this->u('admin'))->put(route('productos.update', $producto), $this->datosProducto(['precio_venta' => '8.00', 'precio_compra' => '9.00']))
            ->assertSessionHasErrors('precio_venta');
    }

    public function test_el_alta_rapida_desde_compras_tambien_lo_pide(): void
    {
        $this->actingAs($this->u('admin'))->postJson(route('productos.store'), $this->datosProducto())
            ->assertStatus(422)->assertJsonValidationErrors('precio_venta');
    }

    public function test_el_formulario_trae_el_aviso_y_la_casilla(): void
    {
        $this->actingAs($this->u('admin'))->get(route('productos.create'))->assertOk()
            ->assertSee('data-perdida', false)
            ->assertSee('name="confirma_perdida"', false)
            ->assertSee('vender a pérdida a propósito', false);
    }

    // ============================================================ anular un movimiento de caja

    public function test_anular_un_egreso_escribe_el_ingreso_contrario_enlazado_y_el_efectivo_vuelve(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero, 100);
        $esperadoAntes = $turno->fresh()->efectivoEsperado();

        $egreso = Cajas::movimiento($turno, $cajero, 'EGRESO', 'Bolsas', 50);
        $this->assertEquals($esperadoAntes - 50, $turno->fresh()->efectivoEsperado());

        $anulacion = Cajas::anularMovimiento($egreso, $cajero, 'se tecleó 50 en vez de 5');

        $this->assertSame('INGRESO', $anulacion->tipo);
        $this->assertEquals(50, (float) $anulacion->monto);
        $this->assertSame($egreso->id, $anulacion->anula_a_id);
        $this->assertSame($turno->id, $anulacion->sesion_caja_id);
        $this->assertStringContainsString('ANULA #'.$egreso->id, $anulacion->concepto);
        $this->assertStringContainsString('se tecleó 50 en vez de 5', $anulacion->concepto);
        $this->assertEquals($esperadoAntes, $turno->fresh()->efectivoEsperado(), 'el efectivo esperado vuelve a lo de antes');

        // El original no se toca ni se borra.
        $egreso->refresh();
        $this->assertSame('EGRESO', $egreso->tipo);
        $this->assertEquals(50, (float) $egreso->monto);
        $this->assertSame('Bolsas', $egreso->concepto);
        $this->assertTrue($egreso->estaAnulado());
        $this->assertTrue($anulacion->esAnulacion());
        $this->assertFalse($egreso->esAnulacion());
    }

    public function test_anular_un_ingreso_escribe_el_egreso_contrario(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero, 100);
        $esperadoAntes = $turno->fresh()->efectivoEsperado();

        $ingreso = Cajas::movimiento($turno, $cajero, 'INGRESO', 'Cambio de billetes', 30);
        $anulacion = Cajas::anularMovimiento($ingreso, $cajero, 'no era de esta caja');

        $this->assertSame('EGRESO', $anulacion->tipo);
        $this->assertEquals($esperadoAntes, $turno->fresh()->efectivoEsperado());
    }

    public function test_queda_en_la_bitacora_con_el_motivo(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero);
        $egreso = Cajas::movimiento($turno, $cajero, 'EGRESO', 'Hielo', 20);

        $anulacion = Cajas::anularMovimiento($egreso, $cajero, 'lo pagó el dueño');

        $registro = Auditoria::where('accion', 'CAJA_MOVIMIENTO_ANULADO')->latest('id')->firstOrFail();
        $this->assertSame($egreso->id, (int) $registro->entidad_id);
        $this->assertSame($anulacion->id, $registro->detalle['anulacion_id']);
        $this->assertSame('lo pagó el dueño', $registro->detalle['motivo']);
        $this->assertEquals(20, $registro->detalle['monto']);
    }

    public function test_un_movimiento_se_anula_una_sola_vez_y_una_anulacion_no_se_anula(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero);
        $egreso = Cajas::movimiento($turno, $cajero, 'EGRESO', 'Bolsas', 10);
        $anulacion = Cajas::anularMovimiento($egreso, $cajero, 'error de tecleo');

        try {
            Cajas::anularMovimiento($egreso, $cajero, 'otra vez');
            $this->fail('se anuló dos veces');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ya está anulado', $e->getMessage());
        }

        try {
            Cajas::anularMovimiento($anulacion, $cajero, 'anulo la anulación');
            $this->fail('se anuló una anulación');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ya es una anulación', $e->getMessage());
        }

        $this->assertSame(1, MovimientoCaja::where('anula_a_id', $egreso->id)->count());
    }

    public function test_la_base_impide_anular_dos_veces_aunque_el_codigo_lo_permitiera(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero);
        $egreso = Cajas::movimiento($turno, $cajero, 'EGRESO', 'Bolsas', 10);
        Cajas::anularMovimiento($egreso, $cajero, 'error de tecleo');

        $this->expectException(QueryException::class);

        MovimientoCaja::create([
            'sesion_caja_id' => $turno->id, 'usuario_id' => $cajero->id, 'tipo' => 'INGRESO',
            'concepto' => 'otra anulación a mano', 'monto' => 10, 'anula_a_id' => $egreso->id, 'fecha' => now(),
        ]);
    }

    public function test_exige_un_motivo(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero);
        $egreso = Cajas::movimiento($turno, $cajero, 'EGRESO', 'Bolsas', 10);

        foreach (['', '   ', 'no'] as $motivo) {
            try {
                Cajas::anularMovimiento($egreso, $cajero, $motivo);
                $this->fail("aceptó el motivo «{$motivo}»");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('motivo', $e->getMessage());
            }
        }

        $this->assertFalse($egreso->fresh()->estaAnulado());
    }

    public function test_un_turno_cerrado_no_se_toca(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero);
        $egreso = Cajas::movimiento($turno, $cajero, 'EGRESO', 'Bolsas', 10);
        Cajas::cerrar($turno->fresh(), $this->u('admin'), (float) $turno->fresh()->efectivoEsperado());

        try {
            Cajas::anularMovimiento($egreso, $this->u('admin'), 'ya cerró pero igual');
            $this->fail('se anuló en un turno cerrado');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ya cerró', $e->getMessage());
        }

        $this->assertSame(0, MovimientoCaja::where('anula_a_id', $egreso->id)->count());
    }

    public function test_solo_quien_lo_registro_o_un_administrador_puede_anularlo(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero, 500);
        $delAdmin = Cajas::movimiento($turno, $this->u('admin'), 'EGRESO', 'Retiro del dueño', 100);
        $delCajero = Cajas::movimiento($turno, $cajero, 'EGRESO', 'Bolsas', 10);

        // El cajero no anula lo que registró el administrador (un retiro del dueño)...
        try {
            Cajas::anularMovimiento($delAdmin, $cajero, 'quiero taparlo');
            $this->fail('el cajero anuló un movimiento ajeno');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Solo quien registró', $e->getMessage());
        }

        // ...pero el administrador sí anula lo del cajero.
        $this->assertTrue(Cajas::anularMovimiento($delCajero, $this->u('admin'), 'lo corrige el dueño')->esAnulacion());
    }

    public function test_no_se_anula_un_ingreso_si_sacarlo_dejaria_el_cajon_en_negativo(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero, 100);
        $ingreso = Cajas::movimiento($turno, $cajero, 'INGRESO', 'Cambio', 50);
        // Con 150 en el cajón se saca casi todo: quedan 10.
        Cajas::movimiento($turno, $this->u('admin'), 'EGRESO', 'Retiro', 140);

        try {
            Cajas::anularMovimiento($ingreso, $this->u('admin'), 'quiero esconder el faltante');
            $this->fail('se anuló un ingreso que ya no está en el cajón');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sacaría', $e->getMessage());
        }

        $this->assertFalse($ingreso->fresh()->estaAnulado());
    }

    // ---- por HTTP

    public function test_anular_por_la_pantalla(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero);
        $egreso = Cajas::movimiento($turno, $cajero, 'EGRESO', 'Bolsas', 10);

        $this->actingAs($cajero)->get(route('caja.show', $turno))->assertOk()
            ->assertSee('data-anular-movimiento', false);

        $this->actingAs($cajero)
            ->post(route('caja.movimiento.anular', [$turno, $egreso]), ['_envio' => 'a-1', 'motivo' => 'se tecleó mal'])
            ->assertRedirect()->assertSessionHas('exito');

        $this->assertTrue($egreso->fresh()->estaAnulado());

        $this->actingAs($cajero)->get(route('caja.show', $turno))->assertOk()
            ->assertSee('data-movimiento-anulado', false)
            ->assertSee('data-movimiento-anulacion', false)
            ->assertSee('anula el #'.$egreso->id, false)
            ->assertDontSee('data-anular-movimiento', false);
    }

    public function test_por_la_pantalla_pide_motivo_y_no_mezcla_turnos(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero);
        $egreso = Cajas::movimiento($turno, $cajero, 'EGRESO', 'Bolsas', 10);

        $this->actingAs($cajero)
            ->post(route('caja.movimiento.anular', [$turno, $egreso]), ['_envio' => 'a-2', 'motivo' => 'no'])
            ->assertSessionHasErrors('motivo');
        $this->assertFalse($egreso->fresh()->estaAnulado());

    }

    public function test_un_movimiento_de_otro_turno_no_se_anula_con_la_url_de_este(): void
    {
        // Un turno anterior, ya cerrado, con su movimiento...
        $admin = $this->u('admin');
        $anterior = $this->turno($admin);
        $ajeno = Cajas::movimiento($anterior, $admin, 'EGRESO', 'Retiro de ayer', 10);
        Cajas::cerrar($anterior->fresh(), $admin, (float) $anterior->fresh()->efectivoEsperado());

        // ...y el turno de hoy, que es de otra persona.
        $cajero = $this->u('cajero1');
        $hoy = $this->turno($cajero);
        $this->assertNotSame($anterior->id, $hoy->id);

        $this->actingAs($cajero)
            ->post(route('caja.movimiento.anular', [$hoy, $ajeno]), ['_envio' => 'mezcla-1', 'motivo' => 'mezclando turnos'])
            ->assertNotFound();

        $this->assertFalse($ajeno->fresh()->estaAnulado());
        $this->assertSame(0, MovimientoCaja::where('sesion_caja_id', $hoy->id)->count());
    }

    public function test_cada_quien_ve_el_boton_de_anular_solo_en_lo_que_puede(): void
    {
        $cajero = $this->u('cajero1');
        $admin = $this->u('admin');
        $turno = $this->turno($cajero, 500);
        Cajas::movimiento($turno, $cajero, 'EGRESO', 'Bolsas del cajero', 10);
        Cajas::movimiento($turno, $admin, 'EGRESO', 'Retiro del dueño', 100);

        // El cajero ve «Anular» solo en lo que registró él.
        $html = $this->actingAs($cajero)->get(route('caja.show', $turno))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-anular-movimiento'));

        // El administrador, en los dos.
        $html = $this->actingAs($admin)->get(route('caja.show', $turno))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'data-anular-movimiento'));
    }

    public function test_el_cajero_sin_turno_abierto_no_puede_anular(): void
    {
        $turno = $this->turno($this->u('cajero1'));
        $egreso = Cajas::movimiento($turno, $this->u('cajero1'), 'EGRESO', 'Bolsas', 10);

        // El almacenero no tiene `caja.abrir`: la ruta lo frena.
        $this->actingAs($this->u('almacen'))
            ->post(route('caja.movimiento.anular', [$turno, $egreso]), ['motivo' => 'intento ajeno'])
            ->assertForbidden();
    }

    public function test_el_resumen_impreso_marca_lo_anulado(): void
    {
        $cajero = $this->u('cajero1');
        $turno = $this->turno($cajero);
        $egreso = Cajas::movimiento($turno, $cajero, 'EGRESO', 'Bolsas', 10);
        Cajas::anularMovimiento($egreso, $cajero, 'error de tecleo');
        Cajas::cerrar($turno->fresh(), $this->u('admin'), (float) $turno->fresh()->efectivoEsperado());

        $this->actingAs($this->u('admin'))->get(route('caja.imprimir', $turno))->assertOk()
            ->assertSee('(anulado)')
            ->assertSee('ANULA #'.$egreso->id);
    }
}
