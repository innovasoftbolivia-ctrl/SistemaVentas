<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SesionCaja;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Inventario;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Quinto bloque de la auditoría de octubre de 2026: ofertas con fechas.
 *
 * Una oferta es un precio rebajado que rige entre dos días (los dos incluidos) y
 * se apaga sola; el precio de lista no se toca. Lo que se cobra en el mostrador
 * y lo que se guarda en la venta es siempre el precio vigente de HOY.
 */
class OfertasConFechasTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    /** Un producto de precio de lista 10,00 y costo 4,00, exonerado y con stock. */
    private function producto(array $oferta = []): Producto
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        $producto->forceFill([
            'precio_compra' => 4.00,
            'precio_venta' => 10.00,
            'afecto_impuesto' => 0,
            'precio_oferta' => null, 'oferta_desde' => null, 'oferta_hasta' => null,
            ...$oferta,
        ])->save();

        Inventario::ingreso($producto->fresh(), 20, usuarioId: $this->u('admin')->id);

        return $producto->fresh();
    }

    /** Una oferta de 8,00 que rige desde ayer hasta mañana. */
    private function enOferta(): Producto
    {
        return $this->producto([
            'precio_oferta' => 8.00,
            'oferta_desde' => now()->subDay()->toDateString(),
            'oferta_hasta' => now()->addDay()->toDateString(),
        ]);
    }

    private function turno(Usuario $usuario): SesionCaja
    {
        return Cajas::sesionDe($usuario) ?? Cajas::abrir(Caja::firstOrFail(), $usuario, 100);
    }

    private function efectivo(): int
    {
        return (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id');
    }

    // ============================================================ cuándo rige

    public function test_rige_desde_el_primer_dia_hasta_el_ultimo_inclusive(): void
    {
        $producto = $this->producto(['precio_oferta' => 8.00, 'oferta_desde' => '2026-10-10', 'oferta_hasta' => '2026-10-12']);

        foreach ([
            ['2026-10-09 23:59:59', false, '10.00'],
            ['2026-10-10 00:00:00', true, '8.00'],
            ['2026-10-11 12:00:00', true, '8.00'],
            ['2026-10-12 23:59:59', true, '8.00'],
            ['2026-10-13 00:00:00', false, '10.00'],
        ] as [$momento, $rige, $precio]) {
            Carbon::setTestNow($momento);
            $p = $producto->fresh();

            $this->assertSame($rige, $p->en_oferta, "a las {$momento}");
            $this->assertSame($precio, $p->precio_vigente, "a las {$momento}");
        }
    }

    public function test_una_oferta_que_todavia_no_empieza_esta_programada_pero_no_rige(): void
    {
        $producto = $this->producto(['precio_oferta' => 8.00, 'oferta_desde' => '2026-11-01', 'oferta_hasta' => '2026-11-05']);
        Carbon::setTestNow('2026-10-12 10:00:00');

        $this->assertFalse($producto->fresh()->en_oferta);
        $this->assertTrue($producto->fresh()->oferta_programada);
        $this->assertSame('10.00', $producto->fresh()->precio_vigente);
    }

    public function test_sin_oferta_el_precio_vigente_es_el_de_lista(): void
    {
        $p = $this->producto();

        $this->assertFalse($p->en_oferta);
        $this->assertFalse($p->oferta_programada);
        $this->assertSame('10.00', $p->precio_vigente);
        $this->assertNull($p->margen_oferta);
    }

    public function test_con_impuesto_el_estante_vigente_lleva_el_iva_sobre_el_precio_de_oferta(): void
    {
        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => '0.1300']);
        DB::table('configuracion')->updateOrInsert(['clave' => 'precios_incluyen_impuesto'], ['valor' => '0']);
        Config::olvidar();

        $p = $this->enOferta();
        $p->forceFill(['afecto_impuesto' => 1])->save();
        $p = $p->fresh();

        $this->assertSame(11.30, $p->precio_estante, 'el de lista: 10,00 + 13 %');
        $this->assertSame(9.04, $p->precio_estante_vigente, 'el de oferta: 8,00 + 13 %');
    }

    public function test_el_margen_de_la_oferta_se_mide_sobre_el_costo(): void
    {
        $this->assertSame(4.00, $this->enOferta()->margen_oferta, '8,00 − 4,00');
    }

    // ============================================================ la base lo exige

    public function test_la_base_rechaza_una_oferta_a_medias_o_con_las_fechas_al_reves(): void
    {
        $id = $this->producto()->id;

        foreach ([
            'solo el precio' => ['precio_oferta' => 8],
            'sin fecha final' => ['precio_oferta' => 8, 'oferta_desde' => '2026-10-10'],
            'solo fechas' => ['oferta_desde' => '2026-10-10', 'oferta_hasta' => '2026-10-12'],
            'fechas al reves' => ['precio_oferta' => 8, 'oferta_desde' => '2026-10-12', 'oferta_hasta' => '2026-10-10'],
            'precio negativo' => ['precio_oferta' => -1, 'oferta_desde' => '2026-10-10', 'oferta_hasta' => '2026-10-12'],
        ] as $caso => $datos) {
            try {
                DB::transaction(fn () => DB::table('productos')->where('id', $id)->update($datos));
                $this->fail("la base aceptó una oferta inválida: {$caso}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('ck_productos_oferta', $e->getMessage(), $caso);
            }
        }

        // Un solo día es una oferta válida.
        DB::table('productos')->where('id', $id)->update(['precio_oferta' => 8, 'oferta_desde' => '2026-10-10', 'oferta_hasta' => '2026-10-10']);
        $this->assertSame('8.00', DB::table('productos')->where('id', $id)->value('precio_oferta'));
    }

    // ============================================================ el mostrador

    public function test_el_mostrador_ofrece_el_precio_de_oferta_y_el_de_lista_para_tacharlo(): void
    {
        $producto = $this->enOferta();
        $this->turno($this->u('cajero1'));

        $fila = $this->actingAs($this->u('cajero1'))
            ->getJson('/pos/productos?q='.$producto->codigo)->assertOk()->json('0');

        $this->assertEquals(8.00, $fila['precio']);
        $this->assertEquals(8.00, $fila['precio_estante']);
        $this->assertEquals(10.00, $fila['precio_lista']);
        $this->assertSame($producto->oferta_hasta->format('d/m'), $fila['oferta_hasta']);
    }

    public function test_sin_oferta_el_mostrador_no_manda_precio_de_lista(): void
    {
        $producto = $this->producto();
        $this->turno($this->u('cajero1'));

        $fila = $this->actingAs($this->u('cajero1'))
            ->getJson('/pos/productos?q='.$producto->codigo)->assertOk()->json('0');

        $this->assertEquals(10.00, $fila['precio']);
        $this->assertNull($fila['precio_lista']);
        $this->assertNull($fila['oferta_hasta']);
    }

    public function test_un_producto_cuya_oferta_ya_termino_se_ofrece_a_precio_de_lista(): void
    {
        $producto = $this->producto([
            'precio_oferta' => 8.00,
            'oferta_desde' => now()->subDays(5)->toDateString(),
            'oferta_hasta' => now()->subDay()->toDateString(),
        ]);
        $this->turno($this->u('cajero1'));

        $fila = $this->actingAs($this->u('cajero1'))
            ->getJson('/pos/productos?q='.$producto->codigo)->assertOk()->json('0');

        $this->assertEquals(10.00, $fila['precio']);
        $this->assertNull($fila['precio_lista']);
    }

    public function test_el_refresco_del_carrito_trae_el_precio_vigente_de_ahora(): void
    {
        $producto = $this->enOferta();
        $this->turno($this->u('cajero1'));

        $fila = $this->actingAs($this->u('cajero1'))
            ->getJson(route('pos.precios', ['ids' => $producto->id]))->assertOk()->json('0');

        $this->assertEquals(8.00, $fila['precio']);
        $this->assertEquals(8.00, $fila['precio_estante']);
        $this->assertEquals(10.00, $fila['precio_lista']);
    }

    // ============================================================ la venta

    public function test_la_venta_se_registra_al_precio_de_oferta_y_lo_guarda_como_historia(): void
    {
        $producto = $this->enOferta();
        $this->turno($this->u('cajero1'));

        $this->actingAs($this->u('cajero1'))->post('/pos', [
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 3]],
            'pagos' => [['metodo_pago_id' => $this->efectivo()]],
        ])->assertSessionHasNoErrors();

        $venta = Venta::latest('id')->firstOrFail();
        $linea = $venta->detalle->first();

        $this->assertSame('8.00', $linea->precio_unitario);
        $this->assertSame('24.00', $venta->total);

        // Cuando la oferta termina el precio histórico de la venta no se mueve.
        $producto->forceFill(['oferta_hasta' => now()->subDay()->toDateString(), 'oferta_desde' => now()->subDays(3)->toDateString()])->save();
        $this->assertSame('8.00', $linea->fresh()->precio_unitario);
    }

    public function test_la_venta_vuelve_al_precio_de_lista_cuando_la_oferta_termina(): void
    {
        $producto = $this->enOferta();
        $producto->forceFill(['oferta_desde' => now()->subDays(4)->toDateString(), 'oferta_hasta' => now()->subDay()->toDateString()])->save();
        $cajero = $this->u('cajero1');

        $venta = Ventas::registrar(
            sesion: $this->turno($cajero),
            usuario: $cajero,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => $this->efectivo(), 'monto' => null]],
        );

        $this->assertSame('10.00', $venta->detalle->first()->precio_unitario);
    }

    /**
     * El tope de descuento del cajero se mide contra lo que se cobraría de
     * verdad: Bs 0,90 son el 9 % de 10,00 pero el 11,25 % de 8,00.
     */
    public function test_el_tope_de_descuento_se_mide_contra_el_precio_vigente(): void
    {
        DB::table('configuracion')->updateOrInsert(['clave' => 'descuento_max_cajero'], ['valor' => '10']);
        Config::olvidar();

        $cajero = $this->u('cajero1');
        $this->turno($cajero);
        $pedido = fn (Producto $p) => [
            'lineas' => [['producto_id' => $p->id, 'cantidad' => 1]],
            'pagos' => [['metodo_pago_id' => $this->efectivo()]],
            'descuento' => '0.90',
        ];

        // Sin oferta: 0,90 sobre 10,00 es el 9 %, dentro del tope.
        $sin = $this->producto();
        $this->actingAs($cajero)->post('/pos', $pedido($sin))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(1, Venta::where('usuario_id', $cajero->id)->count());

        // Con la oferta andando: el mismo descuento es el 11,25 % de 8,00 y se frena.
        $con = $this->enOferta();
        $this->actingAs($cajero)->post('/pos', $pedido($con))->assertSessionHas('error');
        $this->assertSame(1, Venta::where('usuario_id', $cajero->id)->count());
    }

    // ============================================================ el formulario del producto

    private function datos(array $sobrescribir = []): array
    {
        return [
            'categoria_id' => Categoria::first()->id,
            'unidad_medida_id' => UnidadMedida::where('codigo', 'UND')->first()->id,
            'proveedor_id' => Proveedor::first()->id,
            'codigo' => 'P-9501',
            'codigo_barras' => '7750009999501',
            'nombre' => 'Producto con oferta',
            'precio_compra' => '4.00',
            'precio_venta' => '10.00',
            'afecto_impuesto' => 0,
            'stock_minimo' => '1',
            'activo' => 1,
            ...$sobrescribir,
        ];
    }

    private function oferta(array $sobrescribir = []): array
    {
        return [
            'precio_oferta' => '8.00',
            'oferta_desde' => '2026-10-10',
            'oferta_hasta' => '2026-10-12',
            ...$sobrescribir,
        ];
    }

    public function test_se_crea_un_producto_con_su_oferta(): void
    {
        $this->actingAs($this->u('admin'))->post(route('productos.store'), $this->datos($this->oferta()))
            ->assertSessionHasNoErrors();

        $producto = Producto::where('codigo', 'P-9501')->firstOrFail();
        $this->assertSame('8.00', $producto->precio_oferta);
        $this->assertSame('2026-10-10', $producto->oferta_desde->format('Y-m-d'));
        $this->assertSame('2026-10-12', $producto->oferta_hasta->format('Y-m-d'));
    }

    public function test_la_oferta_va_completa_o_no_va(): void
    {
        $admin = $this->u('admin');

        $this->actingAs($admin)->post(route('productos.store'), $this->datos(['precio_oferta' => '8.00']))
            ->assertSessionHasErrors(['oferta_desde', 'oferta_hasta']);
        $this->actingAs($admin)->post(route('productos.store'), $this->datos(['oferta_desde' => '2026-10-10', 'oferta_hasta' => '2026-10-12']))
            ->assertSessionHasErrors('precio_oferta');
        $this->actingAs($admin)->post(route('productos.store'), $this->datos(['precio_oferta' => '8.00', 'oferta_desde' => '2026-10-10']))
            ->assertSessionHasErrors('oferta_hasta');

        $this->assertNull(Producto::where('codigo', 'P-9501')->first());
    }

    public function test_la_oferta_no_puede_terminar_antes_de_empezar_ni_costar_lo_mismo_o_mas(): void
    {
        $admin = $this->u('admin');

        $this->actingAs($admin)->post(route('productos.store'), $this->datos($this->oferta(['oferta_desde' => '2026-10-12', 'oferta_hasta' => '2026-10-10'])))
            ->assertSessionHasErrors('oferta_hasta');
        $this->actingAs($admin)->post(route('productos.store'), $this->datos($this->oferta(['precio_oferta' => '10.00'])))
            ->assertSessionHasErrors('precio_oferta');
        $this->actingAs($admin)->post(route('productos.store'), $this->datos($this->oferta(['precio_oferta' => '12.00'])))
            ->assertSessionHasErrors('precio_oferta');

        $this->assertNull(Producto::where('codigo', 'P-9501')->first());
    }

    public function test_una_oferta_bajo_el_costo_exige_confirmarla(): void
    {
        $admin = $this->u('admin');

        $this->actingAs($admin)->post(route('productos.store'), $this->datos($this->oferta(['precio_oferta' => '3.00'])))
            ->assertSessionHasErrors('precio_oferta');
        $this->assertStringContainsString('precio de oferta', session('errors')->first('precio_oferta') ?: 'oferta');
        $this->assertNull(Producto::where('codigo', 'P-9501')->first());

        $this->actingAs($admin)->post(route('productos.store'), $this->datos($this->oferta(['precio_oferta' => '3.00', 'confirma_perdida' => 1])))
            ->assertSessionHasNoErrors();
        $this->assertSame('3.00', Producto::where('codigo', 'P-9501')->value('precio_oferta'));
    }

    public function test_editar_la_oferta_deja_huella_y_quitarla_la_borra(): void
    {
        $admin = $this->u('admin');
        $this->actingAs($admin)->post(route('productos.store'), $this->datos())->assertSessionHasNoErrors();
        $producto = Producto::where('codigo', 'P-9501')->firstOrFail();

        $this->actingAs($admin)->put(route('productos.update', $producto), $this->datos($this->oferta()))
            ->assertSessionHasNoErrors();

        $huella = Auditoria::where('accion', 'OFERTA_ACTUALIZADA')->where('entidad_id', $producto->id)->latest('id')->firstOrFail();
        $this->assertNull($huella->detalle['anterior']);
        $this->assertSame('8.00 · 2026-10-10 → 2026-10-12', $huella->detalle['nueva']);

        // Cambiar otra cosa no toca la oferta ni deja huella nueva.
        $this->actingAs($admin)->put(route('productos.update', $producto), $this->datos($this->oferta(['nombre' => 'Otro nombre'])))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Auditoria::where('accion', 'OFERTA_ACTUALIZADA')->where('entidad_id', $producto->id)->count());

        // El formulario con los tres campos en blanco quita la oferta.
        $this->actingAs($admin)->put(route('productos.update', $producto), $this->datos(['precio_oferta' => '', 'oferta_desde' => '', 'oferta_hasta' => '']))
            ->assertSessionHasNoErrors();

        $producto = $producto->fresh();
        $this->assertNull($producto->precio_oferta);
        $this->assertNull($producto->oferta_desde);
        $this->assertNull($producto->oferta_hasta);

        $quitada = Auditoria::where('accion', 'OFERTA_ACTUALIZADA')->where('entidad_id', $producto->id)->latest('id')->firstOrFail();
        $this->assertSame('8.00 · 2026-10-10 → 2026-10-12', $quitada->detalle['anterior']);
        $this->assertNull($quitada->detalle['nueva']);
    }

    public function test_una_edicion_que_no_menciona_la_oferta_la_deja_como_esta(): void
    {
        $admin = $this->u('admin');
        $this->actingAs($admin)->post(route('productos.store'), $this->datos($this->oferta()))->assertSessionHasNoErrors();
        $producto = Producto::where('codigo', 'P-9501')->firstOrFail();

        // Un cliente que no manda los campos (la alta rápida de compras, por ejemplo).
        $this->actingAs($admin)->put(route('productos.update', $producto), $this->datos(['nombre' => 'Renombrado']))
            ->assertSessionHasNoErrors();

        $this->assertSame('8.00', $producto->fresh()->precio_oferta);
    }

    public function test_el_formulario_trae_la_seccion_de_oferta(): void
    {
        $this->actingAs($this->u('admin'))->get(route('productos.create'))->assertOk()
            ->assertSee('data-oferta', false)
            ->assertSee('name="precio_oferta"', false)
            ->assertSee('name="oferta_desde"', false)
            ->assertSee('name="oferta_hasta"', false);

        $producto = $this->enOferta();
        $this->actingAs($this->u('admin'))->get(route('productos.edit', $producto))->assertOk()
            ->assertSee('value="8.00"', false)
            ->assertSee('Para quitar la oferta');
    }

    public function test_el_listado_y_la_ficha_muestran_la_oferta_que_rige(): void
    {
        $producto = $this->enOferta();

        $this->actingAs($this->u('admin'))->get(route('productos.index', ['buscar' => $producto->codigo]))->assertOk()
            ->assertSee('data-en-oferta', false)
            ->assertSee('Oferta hasta '.$producto->oferta_hasta->format('d/m'));

        $this->actingAs($this->u('admin'))->get(route('productos.show', $producto))->assertOk()
            ->assertSee('rige hoy');

        $producto->forceFill(['oferta_desde' => now()->subDays(4)->toDateString(), 'oferta_hasta' => now()->subDay()->toDateString()])->save();

        $this->actingAs($this->u('admin'))->get(route('productos.index', ['buscar' => $producto->codigo]))->assertOk()
            ->assertSee($producto->nombre)
            ->assertDontSee('data-en-oferta', false);
        $this->actingAs($this->u('admin'))->get(route('productos.show', $producto))->assertOk()
            ->assertSee('ya terminó');
    }

    // ============================================================ cambiar de modo de impuesto

    private function guardarConfiguracion(array $cambios)
    {
        $valor = fn (string $clave) => (string) DB::table('configuracion')->where('clave', $clave)->value('valor');
        $tasa = (float) $valor('tasa_impuesto');

        return $this->actingAs($this->u('admin'))->put(route('configuracion.update'), array_merge([
            'negocio_nombre' => $valor('negocio_nombre'),
            'negocio_documento' => $valor('negocio_documento'),
            'negocio_direccion' => $valor('negocio_direccion'),
            'negocio_telefono' => $valor('negocio_telefono'),
            'moneda_codigo' => $valor('moneda_codigo'),
            'cobra_impuesto' => $tasa > 0 ? '1' : '0',
            'tasa_impuesto' => $tasa > 0 ? (string) ($tasa * 100) : '13',
            'precios_incluyen_impuesto' => $valor('precios_incluyen_impuesto') === '1' ? '1' : '0',
            'descuento_max_cajero' => $valor('descuento_max_cajero'),
            'egreso_max_cajero' => $valor('egreso_max_cajero'),
            'cliente_generico_nombre' => $valor('cliente_generico_nombre'),
            'dias_max_sustitucion' => $valor('dias_max_sustitucion'),
            'dias_max_devolucion' => $valor('dias_max_devolucion'),
            'exigir_referencia_pago' => $valor('exigir_referencia_pago'),
            'serie_factura' => $valor('serie_factura'),
            'serie_recibo' => $valor('serie_recibo'),
        ], $cambios));
    }

    private function conIvaEncima(): Producto
    {
        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => '0.1300']);
        DB::table('configuracion')->updateOrInsert(['clave' => 'precios_incluyen_impuesto'], ['valor' => '0']);
        Config::olvidar();

        $producto = $this->producto([
            'precio_venta' => 10.00,
            'precio_oferta' => 8.00,
            'oferta_desde' => now()->subDay()->toDateString(),
            'oferta_hasta' => now()->addDay()->toDateString(),
            'afecto_impuesto' => 1,
        ]);

        return $producto;
    }

    /**
     * Con el impuesto encima, 8,00 + 13 % = 9,04 es lo que paga el cliente. Al
     * pasar a precios con el impuesto incluido, la oferta tiene que valer 9,04
     * como el de lista vale 11,30: si se quedara en 8,00 sería un descuento de
     * más del 29 % que nadie decidió.
     */
    public function test_cambiar_a_precios_con_impuesto_incluido_convierte_tambien_la_oferta(): void
    {
        $producto = $this->conIvaEncima();

        $this->guardarConfiguracion(['precios_incluyen_impuesto' => '1', 'convertir_precios' => '1'])->assertSessionHasNoErrors();

        $producto = $producto->fresh();
        $this->assertSame('11.30', $producto->precio_venta);
        $this->assertSame('9.04', $producto->precio_oferta);
        $this->assertSame(9.04, $producto->precio_estante_vigente);

        $conversion = Auditoria::where('accion', 'PRECIOS_CONVERTIDOS')->latest('id')->firstOrFail();
        $this->assertSame(['8.00', '9.04'], $conversion->detalle['ofertas'][$producto->id]);
    }

    public function test_la_bitacora_de_la_conversion_no_nombra_a_los_exonerados(): void
    {
        $afecto = $this->conIvaEncima();
        $exonerado = Producto::where('codigo', 'P-0009')->firstOrFail();
        $exonerado->forceFill([
            'afecto_impuesto' => 0, 'precio_venta' => 10.00,
            'precio_oferta' => 8.00,
            'oferta_desde' => now()->subDay()->toDateString(), 'oferta_hasta' => now()->addDay()->toDateString(),
        ])->save();

        $this->guardarConfiguracion(['precios_incluyen_impuesto' => '1', 'convertir_precios' => '1'])->assertSessionHasNoErrors();

        $conversion = Auditoria::where('accion', 'PRECIOS_CONVERTIDOS')->latest('id')->firstOrFail();
        $this->assertArrayHasKey($afecto->id, $conversion->detalle['ofertas']);
        $this->assertArrayNotHasKey($exonerado->id, $conversion->detalle['ofertas']);
        $this->assertArrayNotHasKey($exonerado->id, $conversion->detalle['precios']);
    }

    public function test_deshacer_la_conversion_devuelve_tambien_la_oferta_salvo_que_la_hayan_corregido(): void
    {
        $producto = $this->conIvaEncima();
        $otro = Producto::where('codigo', 'P-0009')->firstOrFail();
        $otro->forceFill([
            'afecto_impuesto' => 1, 'precio_venta' => 10.00, 'precio_compra' => 4.00,
            'precio_oferta' => 8.00,
            'oferta_desde' => now()->subDay()->toDateString(), 'oferta_hasta' => now()->addDay()->toDateString(),
        ])->save();

        $this->guardarConfiguracion(['precios_incluyen_impuesto' => '1', 'convertir_precios' => '1'])->assertSessionHasNoErrors();

        // Alguien corrige a mano la oferta de uno de los dos: esa se respeta.
        $otro->fresh()->forceFill(['precio_oferta' => 9.50])->save();

        $this->actingAs($this->u('admin'))->post(route('configuracion.deshacer-conversion'))->assertSessionHasNoErrors();

        $this->assertSame('8.00', $producto->fresh()->precio_oferta);
        $this->assertSame('10.00', $producto->fresh()->precio_venta);
        $this->assertSame('9.50', $otro->fresh()->precio_oferta);
    }

    public function test_cambiar_de_modo_sin_convertir_no_toca_las_ofertas(): void
    {
        $producto = $this->conIvaEncima();

        $this->guardarConfiguracion(['precios_incluyen_impuesto' => '1'])->assertSessionHasNoErrors();

        $this->assertSame('8.00', $producto->fresh()->precio_oferta);
        $this->assertSame('10.00', $producto->fresh()->precio_venta);
    }

    public function test_un_producto_exonerado_conserva_su_oferta_al_convertir(): void
    {
        $this->conIvaEncima();
        $exonerado = Producto::where('codigo', 'P-0009')->firstOrFail();
        $exonerado->forceFill([
            'afecto_impuesto' => 0, 'precio_venta' => 10.00,
            'precio_oferta' => 8.00,
            'oferta_desde' => now()->subDay()->toDateString(), 'oferta_hasta' => now()->addDay()->toDateString(),
        ])->save();

        $this->guardarConfiguracion(['precios_incluyen_impuesto' => '1', 'convertir_precios' => '1'])->assertSessionHasNoErrors();

        $this->assertSame('8.00', $exonerado->fresh()->precio_oferta);
    }
}
