<?php

namespace Tests\Feature;

use App\Http\Controllers\BitacoraController;
use App\Http\Middleware\RegistrarConsulta;
use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\Cargo;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\MetodoPago;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\SesionCaja;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Auditor;
use App\Services\Cajas;
use App\Services\ControlDeCajeros;
use App\Services\Devoluciones;
use App\Services\Inventario;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La bitácora, más útil (segunda auditoría de octubre de 2026):
 *   1. guarda QUÉ cambió, campo por campo;
 *   2. anota quién miró un reporte o se llevó un Excel o un PDF;
 *   3. anota los accesos denegados;
 *   4. el control de cajeros marca a quien se aparta de lo normal.
 */
class BitacoraMasUtilTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // El freno de los accesos denegados vive en la caché, que no se revierte con la transacción.
        Cache::flush();
    }

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function ultima(string $accion, ?int $entidadId = null): Auditoria
    {
        return Auditoria::where('accion', $accion)
            ->when($entidadId, fn ($q) => $q->where('entidad_id', $entidadId))
            ->latest('id')->firstOrFail();
    }

    // ============================================================ 1. qué cambió

    private function datosProducto(array $sobrescribir = []): array
    {
        return [
            'categoria_id' => Categoria::first()->id,
            'unidad_medida_id' => UnidadMedida::where('codigo', 'UND')->first()->id,
            'proveedor_id' => Proveedor::first()->id,
            'codigo' => 'P-9801',
            'codigo_barras' => '7750009998011',
            'nombre' => 'Producto de bitácora',
            'precio_compra' => '5.00',
            'precio_venta' => '8.00',
            'afecto_impuesto' => 1,
            'stock_minimo' => '10',
            'activo' => 1,
            ...$sobrescribir,
        ];
    }

    public function test_editar_un_producto_deja_cada_campo_con_su_antes_y_su_despues(): void
    {
        $admin = $this->u('admin');
        $this->actingAs($admin)->post(route('productos.store'), $this->datosProducto())->assertSessionHasNoErrors();
        $producto = Producto::where('codigo', 'P-9801')->firstOrFail();
        $otraCategoria = Categoria::where('id', '<>', $producto->categoria_id)->firstOrFail();
        // El sello de «última edición» cambia solo con cada guardado: no es un cambio que anotar.
        // (Se atrasa un día para que la edición de abajo sí lo mueva, aunque ocurra en el mismo segundo.)
        DB::table('productos')->where('id', $producto->id)->update(['actualizado_en' => now()->subDay()]);

        $this->actingAs($admin)->put(route('productos.update', $producto), $this->datosProducto([
            'precio_venta' => '9.50', 'categoria_id' => $otraCategoria->id, 'nombre' => 'Producto renombrado',
        ]))->assertSessionHasNoErrors();

        $cambios = $this->ultima('PRODUCTO_ACTUALIZADO', $producto->id)->detalle['cambios'];

        $this->assertSame(['antes' => '8.00', 'despues' => '9.50'], $cambios['precio venta']);
        $this->assertSame('Producto de bitácora', $cambios['nombre']['antes']);
        $this->assertSame('Producto renombrado', $cambios['nombre']['despues']);
        $this->assertSame($otraCategoria->nombre, $cambios['categoria']['despues'], 'la categoría se anota con su nombre, no con su id');
        $this->assertNotSame($otraCategoria->nombre, $cambios['categoria']['antes']);
        $this->assertArrayNotHasKey('precio compra', $cambios, 'lo que no cambió no se anota');
        $this->assertArrayNotHasKey('stock actual', $cambios);
        $this->assertCount(3, $cambios);
    }

    public function test_guardar_sin_tocar_nada_no_inventa_cambios(): void
    {
        $admin = $this->u('admin');
        $this->actingAs($admin)->post(route('productos.store'), $this->datosProducto())->assertSessionHasNoErrors();
        $producto = Producto::where('codigo', 'P-9801')->firstOrFail();

        // «8.0» y «8.00» son el mismo precio.
        $this->actingAs($admin)->put(route('productos.update', $producto), $this->datosProducto(['precio_venta' => '8.0']))
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->ultima('PRODUCTO_ACTUALIZADO', $producto->id)->detalle['cambios']);
    }

    public function test_un_cliente_una_categoria_y_un_cargo_tambien_dejan_su_antes_y_su_despues(): void
    {
        $admin = $this->u('admin');

        $datosCliente = ['tipo_persona' => 'NATURAL', 'tipo_documento' => 'CI', 'documento' => '1234567', 'nombres' => 'Ana', 'apellidos' => 'Pérez'];
        $this->actingAs($admin)->post(route('clientes.store'), $datosCliente)->assertSessionHasNoErrors();
        $cliente = Cliente::where('documento', '1234567')->firstOrFail();
        $this->actingAs($admin)->put(route('clientes.update', $cliente), $datosCliente + ['telefono' => '70001234'])->assertSessionHasNoErrors();
        $cambios = $this->ultima('CLIENTE_ACTUALIZADO', $cliente->id)->detalle['cambios'];
        $this->assertSame(['antes' => null, 'despues' => '70001234'], $cambios['telefono']);

        $categoria = Categoria::firstOrFail();
        $this->actingAs($admin)->put(route('categorias.update', $categoria), ['nombre' => $categoria->nombre, 'descripcion' => 'Una descripción', 'activo' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame('Una descripción', $this->ultima('CATEGORIA_ACTUALIZADA', $categoria->id)->detalle['cambios']['descripcion']['despues']);

        $cargo = Cargo::firstOrFail();
        $this->actingAs($admin)->put(route('cargos.update', $cargo), ['nombre' => $cargo->nombre, 'descripcion' => 'Otra', 'activo' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame('Otra', $this->ultima('CARGO_ACTUALIZADO', $cargo->id)->detalle['cambios']['descripcion']['despues']);
    }

    public function test_cambiar_la_clave_o_el_rol_de_una_cuenta_no_guarda_la_clave_en_la_bitacora(): void
    {
        $admin = $this->u('admin');
        $cajero = $this->u('cajero1');
        $otroRol = Rol::where('id', '<>', $cajero->rol_id)->where('activo', 1)->firstOrFail();

        $this->actingAs($admin)->put(route('usuarios.update', $cajero), [
            'rol_id' => $otroRol->id, 'usuario' => $cajero->usuario, 'activo' => 1,
            'password' => 'UnaClaveNueva#2026', 'password_confirmation' => 'UnaClaveNueva#2026',
        ])->assertSessionHasNoErrors();

        $registro = $this->ultima('USUARIO_ACTUALIZADO', $cajero->id);
        $cambios = $registro->detalle['cambios'];

        $this->assertSame($otroRol->nombre, $cambios['rol']['despues']);
        $this->assertSame(['antes' => '(oculta)', 'despues' => 'cambiada'], $cambios['password hash']);
        $this->assertStringNotContainsString('UnaClaveNueva', json_encode($registro->detalle));
        $this->assertStringNotContainsString('$2y$', json_encode($registro->detalle), 'ni el hash');
    }

    public function test_un_cambio_de_rol_dice_que_permisos_gano_y_cuales_perdio(): void
    {
        $admin = $this->u('admin');
        $rol = Rol::where('nombre', 'Cajero')->firstOrFail();
        $tenia = $rol->permisos()->pluck('permisos.id')->all();
        $conserva = array_slice($tenia, 1); // pierde el primero
        $gana = Permiso::whereNotIn('id', $tenia)->whereNotIn('codigo', ['usuarios.gestionar'])->firstOrFail();
        $perdido = Permiso::findOrFail($tenia[0]);

        $this->actingAs($admin)->put(route('roles.update', $rol), [
            'nombre' => $rol->nombre, 'descripcion' => $rol->descripcion, 'activo' => 1,
            'permisos' => [...$conserva, $gana->id],
        ])->assertSessionHasNoErrors();

        $detalle = $this->ultima('ROL_ACTUALIZADO', $rol->id)->detalle;

        $this->assertContains($gana->codigo, $detalle['permisos_agregados']);
        $this->assertContains($perdido->codigo, $detalle['permisos_quitados']);
    }

    public function test_la_pantalla_lee_los_cambios_en_filas_legibles(): void
    {
        $filas = BitacoraController::detalle([
            'codigo' => 'P-1',
            'cambios' => ['precio venta' => ['antes' => '8.00', 'despues' => '9.50'], 'proveedor' => ['antes' => null, 'despues' => 'Distribuidora']],
        ]);

        $this->assertContains(['cambió precio venta', '8.00 → 9.50'], $filas);
        $this->assertContains(['cambió proveedor', '— → Distribuidora'], $filas);
    }

    public function test_el_texto_de_un_valor_largo_se_acorta(): void
    {
        $producto = Producto::firstOrFail();
        $antes = Auditor::foto($producto);
        $producto->forceFill(['descripcion' => str_repeat('x', 255)])->save();

        $cambios = Auditor::diferencias($antes, $producto);

        $this->assertLessThanOrEqual(201, mb_strlen($cambios['descripcion']['despues']));
        $this->assertStringEndsWith('…', $cambios['descripcion']['despues']);
    }

    // ============================================================ 2. quién miró

    public function test_abrir_un_reporte_queda_en_la_bitacora_con_sus_filtros(): void
    {
        $this->actingAs($this->u('admin'))
            ->get(route('informes.mermas', ['desde' => '2026-10-01', 'hasta' => '2026-10-10']))->assertOk();

        $registro = $this->ultima('REPORTE_VISTO');

        $this->assertSame('admin', $registro->usuario->usuario);
        $this->assertSame('reportes', $registro->entidad);
        $this->assertSame('Mermas', $registro->detalle['reporte']);
        $this->assertSame(['desde' => '2026-10-01', 'hasta' => '2026-10-10'], $registro->detalle['filtros']);
        $this->assertArrayNotHasKey('formato', $registro->detalle);
    }

    public function test_cada_reporte_se_anota_con_su_nombre(): void
    {
        $admin = $this->u('admin');

        foreach ([
            'reportes.ventas' => 'Reporte de ventas',
            'reportes.productos' => 'Productos e inventario',
            'informes.sin-movimiento' => 'Productos sin movimiento',
            'informes.horas' => 'Horas de más venta',
            'informes.cajeros' => 'Control de cajeros',
        ] as $ruta => $titulo) {
            $this->actingAs($admin)->get(route($ruta))->assertOk();
            $this->assertSame($titulo, $this->ultima('REPORTE_VISTO')->detalle['reporte'], $ruta);
        }
    }

    public function test_llevarse_un_excel_o_un_pdf_queda_anotado_como_exportacion(): void
    {
        $admin = $this->u('admin');

        $this->actingAs($admin)->get(route('informes.horas.excel'))->assertOk();
        $excel = $this->ultima('REPORTE_EXPORTADO');
        $this->assertSame('Horas de más venta', $excel->detalle['reporte']);
        $this->assertSame('Excel', $excel->detalle['formato']);

        $this->actingAs($admin)->get(route('reportes.productos.pdf'))->assertOk();
        $pdf = $this->ultima('REPORTE_EXPORTADO');
        $this->assertSame('Productos e inventario', $pdf->detalle['reporte']);
        $this->assertSame('PDF', $pdf->detalle['formato']);
    }

    public function test_el_kardex_general_tambien_queda_anotado(): void
    {
        $this->actingAs($this->u('admin'))->get(route('inventario.movimientos'))->assertOk();

        $this->assertSame('Movimientos del almacén (kardex)', $this->ultima('KARDEX_VISTO')->detalle['reporte']);
    }

    public function test_un_reporte_que_no_se_sirvio_no_se_anota(): void
    {
        $antes = Auditoria::whereIn('accion', ['REPORTE_VISTO', 'REPORTE_EXPORTADO'])->count();

        // Un cajero no ve reportes: 403, y eso no es una consulta.
        $this->actingAs($this->u('cajero1'))->get(route('informes.mermas'))->assertForbidden();

        $this->assertSame($antes, Auditoria::whereIn('accion', ['REPORTE_VISTO', 'REPORTE_EXPORTADO'])->count());
    }

    public function test_la_bitacora_muestra_las_consultas_con_palabras_de_persona(): void
    {
        $admin = $this->u('admin');
        $this->actingAs($admin)->get(route('informes.mermas', ['desde' => '2026-10-01']))->assertOk();

        $this->actingAs($admin)->get(route('bitacora.index'))->assertOk()
            ->assertSee('Reporte consultado')
            ->assertSee('Mermas')
            ->assertSee('desde 2026-10-01');
    }

    // ============================================================ 3. accesos denegados

    public function test_un_acceso_denegado_queda_anotado_con_quien_y_a_donde(): void
    {
        $cajero = $this->u('cajero1');

        $this->actingAs($cajero)->get('/reportes/mermas')->assertForbidden();

        $registro = $this->ultima('ACCESO_DENEGADO');
        $this->assertSame($cajero->id, $registro->usuario_id);
        $this->assertSame('/reportes/mermas', $registro->detalle['ruta']);
        $this->assertSame('GET', $registro->detalle['metodo']);
        $this->assertNotEmpty($registro->detalle['motivo']);
    }

    public function test_pedir_lo_mismo_muchas_veces_se_anota_una_vez_por_minuto(): void
    {
        $cajero = $this->u('cajero1');
        $antes = Auditoria::where('accion', 'ACCESO_DENEGADO')->count();

        foreach (range(1, 5) as $_) {
            $this->actingAs($cajero)->get('/reportes/mermas')->assertForbidden();
        }
        $this->assertSame($antes + 1, Auditoria::where('accion', 'ACCESO_DENEGADO')->count());

        // Otra pantalla es otra señal.
        $this->actingAs($cajero)->get('/reportes/horas')->assertForbidden();
        $this->assertSame($antes + 2, Auditoria::where('accion', 'ACCESO_DENEGADO')->count());
    }

    public function test_sin_sesion_no_hay_quien_anotar(): void
    {
        $antes = Auditoria::where('accion', 'ACCESO_DENEGADO')->count();

        $this->get('/reportes/mermas')->assertRedirect('/login');

        $this->assertSame($antes, Auditoria::where('accion', 'ACCESO_DENEGADO')->count());
    }

    public function test_un_error_que_no_es_403_no_se_anota_como_acceso_denegado(): void
    {
        $antes = Auditoria::where('accion', 'ACCESO_DENEGADO')->count();

        $this->actingAs($this->u('admin'))->get('/esto-no-existe')->assertNotFound();

        $this->assertSame($antes, Auditoria::where('accion', 'ACCESO_DENEGADO')->count());
    }

    // ============================================================ 4. control de cajeros

    private function turno(Usuario $usuario): SesionCaja
    {
        // Una caja por persona: dos turnos abiertos a la vez en la misma caja no se pueden. La segunda
        // lleva el id puesto a mano: `cajas.id` es TINYINT, y crear una por prueba agotaría los 255
        // (una transacción revertida no devuelve el contador).
        DB::table('cajas')->insertOrIgnore(['id' => 2, 'nombre' => 'Caja 2 de prueba', 'activo' => 1]);
        $caja = Caja::findOrFail($usuario->usuario === 'admin' ? 2 : Caja::orderBy('id')->value('id'));

        return Cajas::sesionDe($usuario) ?? Cajas::abrir($caja, $usuario, 100, 'Apertura de prueba');
    }

    private function productoVendible(): Producto
    {
        $p = Producto::where('codigo', 'P-0004')->firstOrFail();
        $p->forceFill(['precio_compra' => 4, 'precio_venta' => 10, 'afecto_impuesto' => 0])->save();
        Inventario::ingreso($p->fresh(), 500, usuarioId: $this->u('admin')->id);

        return $p->fresh();
    }

    /** @return array<int, Venta> */
    private function vender(Usuario $usuario, Producto $producto, int $veces, float $descuento = 0.0): array
    {
        $sesion = $this->turno($usuario);
        $ventas = [];

        for ($i = 0; $i < $veces; $i++) {
            $ventas[] = Ventas::registrar(
                sesion: $sesion,
                usuario: $usuario,
                lineas: [['producto_id' => $producto->id, 'cantidad' => 1]],
                pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
                descuento: $descuento,
            );
        }

        return $ventas;
    }

    private function analizar(): array
    {
        return ControlDeCajeros::analizar(now()->subDay()->startOfDay(), now()->endOfDay());
    }

    private function filaDe(array $r, string $usuario): array
    {
        return collect($r['filas'])->firstWhere('nombre', Usuario::where('usuario', $usuario)->first()->empleado?->nombre_completo ?: $usuario);
    }

    public function test_quien_anula_mucho_mas_que_el_resto_queda_marcado(): void
    {
        $producto = $this->productoVendible();
        $cajero = $this->u('cajero1');
        $admin = $this->u('admin');

        $ventasCajero = $this->vender($cajero, $producto, 25);
        $this->vender($admin, $producto, 25);
        foreach (array_slice($ventasCajero, 0, 6) as $venta) {
            Ventas::anular($venta, $cajero, 'Se equivocó');
        }

        $r = $this->analizar();
        $marcas = collect($r['alertas'])->where('tipo', 'anulaciones');

        $this->assertCount(1, $marcas);
        $marca = $marcas->first();
        $this->assertStringContainsString('Anuló 6 de 25 ventas (24.0 %)', $marca['texto']);
        $this->assertStringContainsString('el resto del equipo anula el 0.0 %', $marca['texto']);

        $fila = $this->filaDe($r, 'cajero1');
        $this->assertSame(25, $fila['ventas']);
        $this->assertSame(6, $fila['anuladas']);
        $this->assertEquals(190.0, $fila['vendido'], 'lo vendido no cuenta las anuladas');
        $this->assertEqualsWithDelta(0.24, $fila['pct_anuladas'], 0.0001);
    }

    public function test_con_pocas_ventas_un_porcentaje_no_dice_nada(): void
    {
        $producto = $this->productoVendible();
        $cajero = $this->u('cajero1');

        // 10 de 10 anuladas: 100 %, pero con menos de 20 ventas no se juzga.
        foreach ($this->vender($cajero, $producto, 10) as $venta) {
            Ventas::anular($venta, $cajero, 'Prueba');
        }
        $this->vender($this->u('admin'), $producto, 25);

        $this->assertSame([], collect($this->analizar()['alertas'])->where('tipo', 'anulaciones')->all());
    }

    public function test_anular_lo_mismo_que_el_resto_no_marca_a_nadie(): void
    {
        $producto = $this->productoVendible();
        $cajero = $this->u('cajero1');
        $admin = $this->u('admin');

        foreach ([[$cajero, 25], [$admin, 25]] as [$quien, $n]) {
            foreach (array_slice($this->vender($quien, $producto, $n), 0, 3) as $venta) {
                Ventas::anular($venta, $quien, 'Se equivocó');
            }
        }

        $this->assertSame([], $this->analizar()['alertas']);
    }

    public function test_quien_trabaja_solo_se_marca_con_un_piso_mas_alto(): void
    {
        $producto = $this->productoVendible();
        $cajero = $this->u('cajero1');

        // Sin nadie con quien compararlo: 8 % de anulaciones no alcanza (el piso es 10 %)…
        $ventas = $this->vender($cajero, $producto, 25);
        foreach (array_slice($ventas, 0, 2) as $venta) {
            Ventas::anular($venta, $cajero, 'x');
        }
        $this->assertSame([], collect($this->analizar()['alertas'])->where('tipo', 'anulaciones')->all());

        // …y 12 % sí.
        Ventas::anular($ventas[2], $cajero, 'x');
        $marca = collect($this->analizar()['alertas'])->where('tipo', 'anulaciones')->first();
        $this->assertNotNull($marca);
        $this->assertStringContainsString('Anuló 3 de 25 ventas (12.0 %)', $marca['texto']);
        $this->assertStringNotContainsString('el resto del equipo', $marca['texto']);
    }

    public function test_los_descuentos_se_miden_sobre_lo_vendido(): void
    {
        $producto = $this->productoVendible();
        $this->vender($this->u('cajero1'), $producto, 25);
        $this->vender($this->u('admin'), $producto, 25, descuento: 1.00); // 10 % de cada venta de 10

        $r = $this->analizar();
        $marca = collect($r['alertas'])->where('tipo', 'descuentos')->first();

        $this->assertNotNull($marca);
        $this->assertStringContainsString('Bs 25.00 en descuentos (10.0 %', $marca['texto']);
        $this->assertEqualsWithDelta(0.10, $this->filaDe($r, 'admin')['pct_descuento'], 0.001);
    }

    public function test_los_turnos_que_no_cuadran_marcan_desde_el_segundo_y_cuentan_solo_los_que_no_cuadran(): void
    {
        $cajero = $this->u('cajero1');
        $cerrar = function (float $diferencia) use ($cajero) {
            $sesion = $this->turno($cajero)->fresh();
            Cajas::cerrar($sesion, $cajero, (float) $sesion->efectivoEsperado() + $diferencia, 'Cierre de prueba', 0, null);
        };
        $arqueos = fn () => collect($this->analizar()['alertas'])->where('tipo', 'arqueos')->pluck('texto');

        $cerrar(-10);
        $this->assertCount(0, $arqueos(), 'un turno con diferencia todavía no es un patrón');

        $cerrar(0);
        $this->assertCount(0, $arqueos(), 'y uno que cuadra no suma');

        $cerrar(-5);
        $this->assertCount(1, $arqueos());
        $this->assertStringContainsString('2 de 3 turnos cerraron con diferencia en el arqueo (neto Bs -15.00)', $arqueos()->first());
    }

    public function test_los_accesos_denegados_marcan_a_partir_de_cinco(): void
    {
        $cajero = $this->u('cajero1');
        $accesos = fn () => collect($this->analizar()['alertas'])->where('tipo', 'accesos')->pluck('texto');

        // A mano: el freno de un minuto es de la ruta, no de lo que se inserta.
        foreach (range(1, 4) as $i) {
            Auditor::registrar('ACCESO_DENEGADO', null, null, ['ruta' => "/x{$i}"], $cajero->id);
        }
        $this->assertCount(0, $accesos());

        Auditor::registrar('ACCESO_DENEGADO', null, null, ['ruta' => '/x5'], $cajero->id);
        $this->assertStringContainsString('Intentó entrar 5 veces', $accesos()->first());
    }

    public function test_un_egreso_anulado_no_cuenta_como_plata_que_salio_de_la_caja(): void
    {
        $cajero = $this->u('cajero1');
        $sesion = $this->turno($cajero);

        Cajas::movimiento($sesion, $cajero, 'EGRESO', 'Compra de bolsas', 30);
        $equivocado = Cajas::movimiento($sesion, $cajero, 'EGRESO', 'Egreso mal puesto', 20);
        Cajas::anularMovimiento($equivocado, $this->u('admin'), 'Error de digitación');

        $this->assertEquals(30.0, $this->filaDe($this->analizar(), 'cajero1')['egresos']);
    }

    public function test_las_devoluciones_se_miden_sobre_lo_que_vendio_quien_las_hace(): void
    {
        $producto = $this->productoVendible();
        $admin = $this->u('admin');
        $this->vender($this->u('cajero1'), $producto, 25);
        $ventas = $this->vender($admin, $producto, 25);

        foreach (array_slice($ventas, 0, 5) as $venta) {
            Devoluciones::registrar(
                venta: $venta->fresh(),
                usuario: $admin,
                sesion: $this->turno($admin),
                lineas: [['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1]],
                motivo: 'Llegó abollado',
            );
        }

        $r = $this->analizar();
        $marca = collect($r['alertas'])->where('tipo', 'devoluciones')->first();

        $this->assertNotNull($marca);
        $this->assertStringContainsString('Devolvió Bs 50.00 (20.0 % de lo que vendió)', $marca['texto']);
        $this->assertSame(5, $this->filaDe($r, 'admin')['devoluciones']);
    }

    public function test_lo_vendido_no_cuenta_las_ventas_anuladas(): void
    {
        $producto = $this->productoVendible();
        $cajero = $this->u('cajero1');
        foreach (array_slice($this->vender($cajero, $producto, 25), 0, 6) as $venta) {
            Ventas::anular($venta, $cajero, 'x');
        }

        $this->assertEquals(190.0, $this->filaDe($this->analizar(), 'cajero1')['vendido']);
    }

    public function test_una_respuesta_que_no_es_200_no_se_anota_como_consulta(): void
    {
        $solicitud = Request::create('/reportes/mermas', 'GET');
        $solicitud->setRouteResolver(fn () => app('router')->getRoutes()->match($solicitud));
        $this->actingAs($this->u('admin'));
        $antes = Auditoria::whereIn('accion', ['REPORTE_VISTO', 'REPORTE_EXPORTADO'])->count();

        (new RegistrarConsulta)->handle($solicitud, fn () => redirect('/otra-parte'));
        $this->assertSame($antes, Auditoria::whereIn('accion', ['REPORTE_VISTO', 'REPORTE_EXPORTADO'])->count());

        (new RegistrarConsulta)->handle($solicitud, fn () => response('ok', 200));
        $this->assertSame($antes + 1, Auditoria::whereIn('accion', ['REPORTE_VISTO', 'REPORTE_EXPORTADO'])->count());
    }

    public function test_el_informe_muestra_para_mirar_y_cajero_por_cajero(): void
    {
        $producto = $this->productoVendible();
        $cajero = $this->u('cajero1');
        $ventas = $this->vender($cajero, $producto, 25);
        $this->vender($this->u('admin'), $producto, 25);
        foreach (array_slice($ventas, 0, 6) as $venta) {
            Ventas::anular($venta, $cajero, 'x');
        }

        $this->actingAs($this->u('admin'))
            ->get(route('informes.cajeros', ['desde' => now()->subDay()->toDateString(), 'hasta' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('Para mirar')
            ->assertSee('Cajero por cajero')
            ->assertSee('Anuló 6 de 25 ventas')
            ->assertSee('no una acusación', false);
    }

    public function test_el_informe_sin_nada_raro_lo_dice(): void
    {
        $this->actingAs($this->u('admin'))->get(route('informes.cajeros'))->assertOk()
            ->assertSee('Nadie se aparta de lo normal en el período.');
    }

    public function test_el_excel_y_el_pdf_del_control_de_cajeros_se_descargan(): void
    {
        $admin = $this->u('admin');

        $this->actingAs($admin)->get(route('informes.cajeros.excel'))->assertOk();
        $respuesta = $this->actingAs($admin)->get(route('informes.cajeros.pdf'))->assertOk();
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
        $this->assertStringContainsString('reporte-cajeros_', (string) $respuesta->headers->get('content-disposition'));
    }

    public function test_solo_quien_ve_reportes_abre_el_control_de_cajeros(): void
    {
        foreach (['informes.cajeros', 'informes.cajeros.excel', 'informes.cajeros.pdf'] as $ruta) {
            $this->actingAs($this->u('cajero1'))->get(route($ruta))->assertForbidden();
        }
    }

    public function test_esta_en_el_menu_de_reportes(): void
    {
        $this->actingAs($this->u('admin'))->get(route('informes.cajeros'))->assertOk()
            ->assertSee('/reportes/cajeros', false)
            ->assertSee('Control de cajeros');
    }

    public function test_la_portada_avisa_de_las_marcas_de_la_semana_solo_a_quien_gestiona(): void
    {
        $producto = $this->productoVendible();
        $cajero = $this->u('cajero1');

        $this->actingAs($this->u('admin'))->get('/inicio')->assertOk()->assertDontSee('data-control-cajeros', false);

        $ventas = $this->vender($cajero, $producto, 25);
        $this->vender($this->u('admin'), $producto, 25);
        foreach (array_slice($ventas, 0, 6) as $venta) {
            Ventas::anular($venta, $cajero, 'x');
        }

        $this->actingAs($this->u('admin'))->get('/inicio')->assertOk()
            ->assertSee('data-control-cajeros', false)
            ->assertSee('cosa para mirar')
            ->assertSee('Anuló 6 de 25 ventas')
            ->assertSee('Ver el detalle');

        $this->actingAs($cajero)->get('/inicio')->assertOk()->assertDontSee('data-control-cajeros', false);
    }

    public function test_una_marca_vieja_no_aparece_en_la_portada(): void
    {
        $producto = $this->productoVendible();
        $cajero = $this->u('cajero1');
        $ventas = $this->vender($cajero, $producto, 25);
        $this->vender($this->u('admin'), $producto, 25);
        foreach (array_slice($ventas, 0, 6) as $venta) {
            Ventas::anular($venta, $cajero, 'x');
        }
        DB::table('ventas')->update(['fecha' => now()->subDays(20)]);

        $this->actingAs($this->u('admin'))->get('/inicio')->assertOk()->assertDontSee('data-control-cajeros', false);
    }
}
