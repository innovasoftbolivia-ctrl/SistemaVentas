<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\CobroQr;
use App\Models\Devolucion;
use App\Models\Lote;
use App\Models\MetodoPago;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Auditor;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Descuadres;
use App\Services\Inventario;
use App\Services\ReglasEnPhp;
use App\Services\Ventas;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Cuarto bloque de la auditoría de octubre de 2026: la base se endurece para que un
 * descuadre sea IMPOSIBLE de escribir, y lo que aun así se cuele se descubra la
 * misma noche.
 *
 *   - restricciones (CHECK) que valen en los dos modos, porque viven en las tablas;
 *   - turno abierto para devoluciones y movimientos de caja, y bitácora y kardex
 *     inmutables: triggers en la base, y sus gemelos en PHP donde no hay triggers;
 *   - los índices que sobraban y los que faltaban;
 *   - `v_descuadres`, el comando que la revisa y el aviso de la portada;
 *   - la imagen del QR, que solo vive mientras el cobro está pendiente;
 *   - la cuenta de la aplicación, que ya no borra donde no debe.
 */
class BaseEndurecidaTest extends TestCase
{
    use DatabaseTransactions;

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function turno(?Usuario $usuario = null): SesionCaja
    {
        $usuario ??= $this->u('cajero1');

        return Cajas::sesionDe($usuario) ?? Cajas::abrir(Caja::firstOrFail(), $usuario, 100);
    }

    private function vender(?SesionCaja $turno = null, float $cantidad = 2): Venta
    {
        $turno ??= $this->turno();
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        // El stock entra por el kardex, como en la vida real: ponerlo a mano rompería la cadena
        // y la revisión de descuadres lo diría.
        Inventario::ingreso($producto, 50, usuarioId: $this->u('admin')->id);

        return Ventas::registrar(
            sesion: $turno,
            usuario: $turno->usuarioApertura,
            lineas: [['producto_id' => $producto->id, 'cantidad' => $cantidad, 'precio_unitario' => (float) $producto->precio_venta]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    /** Lo que la base (QueryException) o el gemelo en PHP (RuntimeException) lanzan, según el modo. */
    private function falla(callable $accion, string $fragmento): void
    {
        $esperada = ReglasEnPhp::activa() ? RuntimeException::class : QueryException::class;

        try {
            $accion();
            $this->fail("No falló: tendría que haber dicho «{$fragmento}».");
        } catch (Throwable $e) {
            if ($e instanceof AssertionFailedError) {
                throw $e;
            }

            $this->assertInstanceOf($esperada, $e, $e->getMessage());
            $this->assertStringContainsString($fragmento, $e->getMessage());
        }
    }

    /** Falla por una restricción CHECK: vale en los dos modos, vive en la tabla. */
    private function violaLaRestriccion(callable $accion, string $restriccion): void
    {
        try {
            $accion();
            $this->fail("No se rechazó: tendría que violar {$restriccion}.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($restriccion, $e->getMessage());
        }
    }

    private function soloEnLaBase(): void
    {
        if (ReglasEnPhp::activa()) {
            $this->markTestSkipped('Sin triggers esta regla vive en PHP; su gemelo se prueba en los demás casos.');
        }
    }

    // ============================================================ restricciones (CHECK)

    private function movimientoCrudo(array $sobrescribir = []): array
    {
        return [
            'producto_id' => Producto::where('codigo', 'P-0004')->value('id'),
            'usuario_id' => $this->u('admin')->id,
            'tipo' => 'ENTRADA', 'origen' => 'AJUSTE', 'motivo' => 'prueba',
            'cantidad' => 5, 'stock_anterior' => 10, 'stock_resultante' => 15,
            ...$sobrescribir,
        ];
    }

    public function test_cada_tipo_del_kardex_cumple_su_propia_cuenta(): void
    {
        // Las cuentas buenas entran...
        foreach ([
            ['tipo' => 'ENTRADA', 'cantidad' => 5, 'stock_anterior' => 10, 'stock_resultante' => 15],
            ['tipo' => 'SALIDA', 'cantidad' => 5, 'stock_anterior' => 10, 'stock_resultante' => 5],
            ['tipo' => 'AJUSTE', 'cantidad' => 3, 'stock_anterior' => 10, 'stock_resultante' => 13],   // un ajuste puede subir...
            ['tipo' => 'AJUSTE', 'cantidad' => 3, 'stock_anterior' => 10, 'stock_resultante' => 7],    // ...o bajar
        ] as $buena) {
            DB::table('movimientos_inventario')->insert($this->movimientoCrudo($buena));
        }

        $this->assertSame(4, DB::table('movimientos_inventario')->where('motivo', 'prueba')->count());

        // ...y las malas no, cada una por su razón.
        foreach ([
            'una entrada que resta' => ['tipo' => 'ENTRADA', 'cantidad' => 5, 'stock_anterior' => 10, 'stock_resultante' => 5],
            'una salida que suma' => ['tipo' => 'SALIDA', 'cantidad' => 5, 'stock_anterior' => 10, 'stock_resultante' => 15],
            'un resultado que no sale de la cuenta' => ['tipo' => 'ENTRADA', 'cantidad' => 5, 'stock_anterior' => 10, 'stock_resultante' => 16],
            'un ajuste con otra diferencia' => ['tipo' => 'AJUSTE', 'cantidad' => 3, 'stock_anterior' => 10, 'stock_resultante' => 20],
            'un stock resultante negativo' => ['tipo' => 'SALIDA', 'cantidad' => 5, 'stock_anterior' => 3, 'stock_resultante' => -2],
        ] as $porque => $mala) {
            $this->violaLaRestriccion(
                fn () => DB::table('movimientos_inventario')->insert($this->movimientoCrudo($mala)),
                'ck_movinv_cadena',
            );
        }
    }

    public function test_una_devolucion_no_puede_tener_total_negativo(): void
    {
        $venta = $this->vender();

        $this->violaLaRestriccion(fn () => DB::table('devoluciones')->insert([
            'venta_id' => $venta->id, 'usuario_id' => $this->u('admin')->id,
            'sesion_caja_id' => $venta->sesion_caja_id, 'tipo' => 'PARCIAL', 'motivo' => 'prueba', 'total' => -1,
        ]), 'ck_devol_total');
    }

    public function test_una_venta_anulada_dice_cuando_quien_y_por_que(): void
    {
        $venta = $this->vender();

        foreach ([
            'anulada sin nada' => ['estado' => 'ANULADA'],
            'anulada sin motivo' => ['estado' => 'ANULADA', 'anulada_en' => now(), 'anulada_por' => $this->u('admin')->id],
            'con fecha de anulación pero sin estar anulada' => ['anulada_en' => now()],
        ] as $porque => $cambios) {
            $this->violaLaRestriccion(
                fn () => DB::table('ventas')->where('id', $venta->id)->update($cambios),
                'ck_ventas_anulacion',
            );
        }
    }

    public function test_un_turno_cerrado_dice_cuando_y_quien_lo_cerro(): void
    {
        $turno = $this->turno();

        $this->violaLaRestriccion(fn () => DB::table('sesiones_caja')->where('id', $turno->id)
            ->update(['estado' => 'CERRADA', 'fecha_cierre' => now()]), 'ck_sesion_cierre');

        $this->violaLaRestriccion(fn () => DB::table('sesiones_caja')->where('id', $turno->id)
            ->update(['fecha_cierre' => now()]), 'ck_sesion_cierre');
    }

    public function test_un_comprobante_anulado_lleva_su_fecha(): void
    {
        $venta = $this->vender();

        $this->violaLaRestriccion(fn () => DB::table('comprobantes')->where('venta_id', $venta->id)
            ->update(['estado' => 'ANULADO']), 'ck_comprobante_anulado');
    }

    public function test_lo_que_hace_la_aplicacion_sigue_cumpliendo_las_restricciones(): void
    {
        // Anular una venta y cerrar un turno por los caminos de verdad no choca con nada.
        $turno = $this->turno();
        $venta = $this->vender($turno);
        Ventas::anular($venta, $this->u('admin'), 'Prueba de las restricciones');

        $this->assertSame('ANULADA', $venta->fresh()->estado);

        Cajas::cerrar($turno->fresh(), $this->u('admin'), (float) $turno->fresh()->efectivoEsperado());

        $this->assertSame('CERRADA', $turno->fresh()->estado);
    }

    // ============================================================ turno abierto

    public function test_una_devolucion_exige_un_turno_abierto(): void
    {
        $venta = $this->vender();
        $base = ['venta_id' => $venta->id, 'usuario_id' => $this->u('admin')->id, 'tipo' => 'PARCIAL', 'motivo' => 'prueba', 'total' => 1, 'fecha' => now()];

        $this->falla(fn () => Devolucion::forceCreate($base + ['sesion_caja_id' => null]), 'turno de caja abierto');

        $cerrado = $this->turno();
        DB::table('sesiones_caja')->where('id', $cerrado->id)
            ->update(['estado' => 'CERRADA', 'fecha_cierre' => now(), 'usuario_cierre_id' => $this->u('admin')->id]);

        $this->falla(fn () => Devolucion::forceCreate($base + ['sesion_caja_id' => $cerrado->id]), 'turno de caja abierto');
    }

    public function test_un_movimiento_de_caja_exige_un_turno_abierto(): void
    {
        $turno = $this->turno();
        DB::table('sesiones_caja')->where('id', $turno->id)
            ->update(['estado' => 'CERRADA', 'fecha_cierre' => now(), 'usuario_cierre_id' => $this->u('admin')->id]);

        $this->falla(fn () => MovimientoCaja::forceCreate([
            'sesion_caja_id' => $turno->id, 'usuario_id' => $this->u('admin')->id,
            'tipo' => 'EGRESO', 'concepto' => 'Después del cierre', 'monto' => 5, 'fecha' => now(),
        ]), 'turno de caja abierto');
    }

    public function test_un_movimiento_de_caja_en_turno_abierto_entra_normal(): void
    {
        $turno = $this->turno();

        $movimiento = Cajas::movimiento($turno, $this->u('cajero1'), 'EGRESO', 'Bolsas', 5);

        $this->assertNotNull($movimiento->id);
    }

    public function test_una_venta_no_entra_en_un_turno_cerrado(): void
    {
        $turno = $this->turno();
        DB::table('sesiones_caja')->where('id', $turno->id)
            ->update(['estado' => 'CERRADA', 'fecha_cierre' => now(), 'usuario_cierre_id' => $this->u('admin')->id]);

        $this->falla(fn () => Venta::forceCreate([
            'usuario_id' => $this->u('admin')->id, 'sesion_caja_id' => $turno->id, 'fecha' => now(),
        ]), 'turno de caja abierto');
    }

    public function test_las_ventas_no_se_borran(): void
    {
        $venta = $this->vender();

        $this->falla(fn () => $venta->delete(), 'Las ventas no se eliminan');
        $this->assertNotNull(Venta::find($venta->id));
    }

    public function test_el_kardex_exige_responsable_salvo_la_carga_inicial(): void
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        $base = ['producto_id' => $producto->id, 'tipo' => 'ENTRADA', 'cantidad' => 1, 'stock_anterior' => 0, 'stock_resultante' => 1, 'fecha' => now()];

        $this->falla(fn () => MovimientoInventario::forceCreate($base + ['origen' => 'AJUSTE', 'motivo' => 'sin responsable', 'usuario_id' => null]),
            'necesita un responsable');

        // La carga INICIAL puede no tenerlo: corre antes de que exista nadie.
        $inicial = MovimientoInventario::forceCreate($base + ['origen' => 'INICIAL', 'usuario_id' => null]);
        $this->assertNotNull($inicial->id);
    }

    // ============================================================ bitácora y kardex inmutables

    public function test_la_bitacora_no_se_edita_ni_se_borra(): void
    {
        Auditor::registrar('PRUEBA_INMUTABLE', 'pruebas', 1, ['dato' => 'original']);
        $registro = Auditoria::where('accion', 'PRUEBA_INMUTABLE')->firstOrFail();

        $this->falla(fn () => $registro->update(['accion' => 'OTRA_COSA']), 'La bitácora no se edita');
        $this->falla(fn () => $registro->delete(), 'La bitácora no se borra');

        $this->assertSame('PRUEBA_INMUTABLE', $registro->fresh()->accion);
    }

    public function test_el_kardex_no_se_edita_ni_se_borra(): void
    {
        $movimiento = MovimientoInventario::firstOrFail();
        $cantidad = $movimiento->cantidad;

        $this->falla(fn () => $movimiento->update(['cantidad' => 999]), 'El kardex no se edita');
        $this->falla(fn () => $movimiento->delete(), 'El kardex no se borra');

        $this->assertEquals($cantidad, $movimiento->fresh()->cantidad);
    }

    public function test_ni_a_mano_ni_con_sql_directo_se_edita_o_borra_el_kardex_o_la_bitacora(): void
    {
        $this->soloEnLaBase();   // sin triggers, el SQL directo no tiene quién lo frene

        Auditor::registrar('PRUEBA_INMUTABLE', 'pruebas', 1);

        foreach ([
            ['update', fn () => DB::table('movimientos_inventario')->update(['motivo' => 'x']), 'El kardex no se edita'],
            ['delete', fn () => DB::table('movimientos_inventario')->delete(), 'El kardex no se borra'],
            ['update', fn () => DB::table('auditoria')->update(['accion' => 'x']), 'La bitácora no se edita'],
            ['delete', fn () => DB::table('auditoria')->delete(), 'La bitácora no se borra'],
        ] as [, $accion, $mensaje]) {
            $this->falla($accion, $mensaje);
        }
    }

    public function test_un_error_del_kardex_se_corrige_con_un_movimiento_nuevo(): void
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        $antes = (float) $producto->stock_actual;
        $this->actingAs($this->u('admin'));

        $ajuste = Inventario::ajuste($producto->fresh(), $antes + 2, 'Conteo físico');

        $this->assertNotNull($ajuste);
        $this->assertEquals($antes + 2, (float) $producto->fresh()->stock_actual);
        $this->assertSame(0, Descuadres::filas()->count());
    }

    // ============================================================ los gemelos dicen lo mismo que la base

    public function test_los_mensajes_del_gemelo_son_los_de_los_triggers(): void
    {
        $esquema = (string) file_get_contents(base_path('../docs/sql/01_schema_mysql.sql'));

        $mensajes = [
            'La venta debe registrarse en un turno de caja abierto',
            'La devolución debe registrarse en un turno de caja abierto',
            'El movimiento de caja debe registrarse en un turno de caja abierto',
            'Las ventas no se eliminan. Use la anulación (RNF6).',
            'El movimiento de inventario necesita un responsable',
            'La bitácora no se edita: lo que pasó, pasó.',
            'La bitácora no se borra: lo que pasó, pasó. (Lo viejo se archiva con archivar:anual.)',
            'El kardex no se edita: un error se corrige con un movimiento nuevo.',
            'El kardex no se borra: un error se corrige con un movimiento nuevo. (Lo viejo se archiva con archivar:anual.)',
        ];

        $php = (string) file_get_contents(app_path('Services/ReglasEnPhp.php'));

        foreach ($mensajes as $mensaje) {
            $this->assertStringContainsString("MESSAGE_TEXT = '{$mensaje}'", $esquema, "el trigger no dice: {$mensaje}");
        }

        // El gemelo, probado: lanza exactamente esos textos.
        foreach ([
            [fn () => ReglasEnPhp::antesDeInsertarVenta(null), $mensajes[0]],
            [fn () => ReglasEnPhp::antesDeInsertarDevolucion(null), $mensajes[1]],
            [fn () => ReglasEnPhp::antesDeInsertarMovimientoCaja(null), $mensajes[2]],
            [fn () => ReglasEnPhp::antesDeBorrarVenta(), $mensajes[3]],
            [fn () => ReglasEnPhp::antesDeInsertarMovimientoInventario(null, 'VENTA'), $mensajes[4]],
            [fn () => ReglasEnPhp::bitacoraInmutable('auditoria', 'editar'), $mensajes[5]],
            [fn () => ReglasEnPhp::bitacoraInmutable('auditoria', 'borrar'), $mensajes[6]],
            [fn () => ReglasEnPhp::bitacoraInmutable('kardex', 'editar'), $mensajes[7]],
            [fn () => ReglasEnPhp::bitacoraInmutable('kardex', 'borrar'), $mensajes[8]],
        ] as [$accion, $esperado]) {
            try {
                $accion();
                $this->fail("el gemelo no lanzó: {$esperado}");
            } catch (RuntimeException $e) {
                $this->assertSame($esperado, $e->getMessage());
            }
        }

        $this->assertStringContainsString('los 15 triggers', $php);
    }

    // ============================================================ índices

    /** @return list<string> */
    private function indices(string $tabla): array
    {
        return collect(DB::select("SHOW INDEX FROM {$tabla}"))->pluck('Key_name')->unique()->values()->all();
    }

    public function test_los_indices_que_sobraban_ya_no_estan(): void
    {
        $this->assertNotContains('ix_detalle_venta', $this->indices('venta_detalle'));
        $this->assertNotContains('ix_clientes_persona', $this->indices('clientes'));
        $this->assertNotContains('ix_empleados_estado', $this->indices('empleados'));
        $this->assertNotContains('ix_productos_activo', $this->indices('productos'));
        $this->assertNotContains('ix_ventas_fecha', $this->indices('ventas'));

        // Y la llave foránea de las líneas sigue teniendo en qué apoyarse.
        $this->assertContains('uq_detalle_venta_producto', $this->indices('venta_detalle'));
    }

    public function test_los_indices_que_faltaban_estan_y_cubren_lo_que_dicen(): void
    {
        $this->assertContains('ix_productos_activo_nombre', $this->indices('productos'));
        $this->assertContains('ix_ventas_fecha_cubre', $this->indices('ventas'));
        $this->assertContains('ix_movcaja_fecha', $this->indices('movimientos_caja'));

        $columnas = collect(DB::select("SHOW INDEX FROM ventas WHERE Key_name = 'ix_ventas_fecha_cubre'"))
            ->sortBy('Seq_in_index')->pluck('Column_name')->all();
        $this->assertSame(['fecha', 'estado', 'total', 'impuesto'], $columnas);
    }

    public function test_la_suma_por_dia_se_resuelve_dentro_del_indice(): void
    {
        $plan = collect(DB::select(
            "EXPLAIN SELECT SUM(total), SUM(impuesto) FROM ventas WHERE fecha BETWEEN '2026-01-01' AND '2026-12-31' AND estado <> 'ANULADA'"
        ))->first();

        $this->assertSame('ix_ventas_fecha_cubre', $plan->key);
        $this->assertStringContainsString('Using index', (string) $plan->Extra, 'tendría que leer solo el índice, no cada venta');
    }

    // ============================================================ v_descuadres

    public function test_una_base_sana_no_tiene_descuadres(): void
    {
        $this->vender();

        $this->assertSame(0, Descuadres::filas()->count(), Descuadres::filas()->pluck('detalle')->implode(' | '));
    }

    public function test_detecta_un_stock_que_no_sale_del_kardex(): void
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        DB::table('productos')->where('id', $producto->id)->update(['stock_actual' => DB::raw('stock_actual + 7')]);

        $fila = Descuadres::filas()->firstWhere('regla', 'STOCK_DISTINTO_DEL_KARDEX');

        $this->assertNotNull($fila);
        $this->assertSame($producto->id, (int) $fila->entidad_id);
        $this->assertEquals(7, (float) $fila->actual - (float) $fila->esperado);
    }

    public function test_detecta_stock_sin_ningun_movimiento(): void
    {
        $producto = Producto::activos()->orderBy('id')->firstOrFail();
        // Un producto nuevo con stock puesto a mano: nadie lo respalda.
        $nuevo = Producto::create([
            'categoria_id' => $producto->categoria_id, 'unidad_medida_id' => $producto->unidad_medida_id,
            'codigo' => 'P-9777', 'nombre' => 'Sin kardex', 'precio_compra' => 1, 'precio_venta' => 2, 'stock_minimo' => 0,
        ]);
        DB::table('productos')->where('id', $nuevo->id)->update(['stock_actual' => 4]);

        $this->assertTrue(Descuadres::filas()->contains(fn ($f) => $f->regla === 'STOCK_SIN_KARDEX' && (int) $f->entidad_id === $nuevo->id));
    }

    public function test_detecta_un_salto_en_la_cadena_del_kardex(): void
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        $ultimo = MovimientoInventario::where('producto_id', $producto->id)->orderByDesc('id')->firstOrFail();

        // Un movimiento que parte de un stock que no es donde terminó el anterior (la cuenta es correcta
        // por sí sola, pero la cadena se rompe).
        $salto = MovimientoInventario::forceCreate([
            'producto_id' => $producto->id, 'usuario_id' => $this->u('admin')->id, 'tipo' => 'ENTRADA', 'origen' => 'AJUSTE',
            'motivo' => 'salto de prueba', 'cantidad' => 1,
            'stock_anterior' => (float) $ultimo->stock_resultante + 50, 'stock_resultante' => (float) $ultimo->stock_resultante + 51,
            'fecha' => now(),
        ]);

        $fila = Descuadres::filas()->firstWhere('regla', 'KARDEX_CON_SALTO');

        $this->assertNotNull($fila);
        $this->assertSame($salto->id, (int) $fila->entidad_id);
    }

    public function test_detecta_un_stock_que_no_es_la_suma_de_sus_lotes(): void
    {
        $producto = Producto::activos()->orderBy('id')->firstOrFail();
        $producto->forceFill(['controla_vencimiento' => 1])->save();
        Lote::create(['producto_id' => $producto->id, 'cantidad_inicial' => 1, 'cantidad_actual' => 1, 'fecha_vencimiento' => now()->addMonth()->toDateString()]);

        $fila = Descuadres::filas()->first(fn ($f) => $f->regla === 'STOCK_DISTINTO_DE_LOS_LOTES' && (int) $f->entidad_id === $producto->id);

        $this->assertNotNull($fila, 'el producto tiene stock y un solo lote de 1');
        $this->assertEquals(1, (float) $fila->esperado);
    }

    public function test_detecta_una_venta_cuyos_pagos_no_suman_su_total(): void
    {
        $venta = $this->vender();
        DB::table('venta_pagos')->where('venta_id', $venta->id)->update(['monto' => DB::raw('monto - 1')]);

        $fila = Descuadres::filas()->firstWhere('regla', 'VENTA_NO_CUADRA_CON_SUS_PAGOS');

        $this->assertNotNull($fila);
        $this->assertSame($venta->id, (int) $fila->entidad_id);
        $this->assertEquals(1, (float) $fila->esperado - (float) $fila->actual);
    }

    public function test_detecta_un_qr_pagado_hace_dias_que_nunca_fue_venta(): void
    {
        $turno = $this->turno();
        $cobro = CobrosQr::generar($turno, $this->u('cajero1'), 10);
        CobrosQr::confirmarAMano($cobro, $this->u('cajero1'));

        // Recién pagado todavía puede estar a punto de ser una venta: no se marca.
        $this->assertFalse(Descuadres::filas()->contains(fn ($f) => $f->regla === 'QR_PAGADO_SIN_VENTA'));

        DB::table('cobros_qr')->where('id', $cobro->id)->update(['pagado_en' => now()->subDays(2)]);

        $this->assertTrue(Descuadres::filas()->contains(fn ($f) => $f->regla === 'QR_PAGADO_SIN_VENTA' && (int) $f->entidad_id === $cobro->id));
    }

    public function test_una_venta_anulada_no_cuenta_como_descuadre_de_pagos(): void
    {
        $venta = $this->vender();
        Ventas::anular($venta, $this->u('admin'), 'Prueba de descuadres');

        $this->assertFalse(Descuadres::filas()->contains(fn ($f) => $f->regla === 'VENTA_NO_CUADRA_CON_SUS_PAGOS'));
        $this->assertSame(0, Descuadres::filas()->count());

        // Aunque sus pagos ya no sumen su total —una venta anulada no tiene por qué cuadrarlos—,
        // no es un descuadre: la anulada se deja afuera de esta regla a propósito.
        DB::table('venta_pagos')->where('venta_id', $venta->id)->update(['monto' => DB::raw('monto - 1')]);

        $this->assertFalse(Descuadres::filas()->contains(fn ($f) => $f->regla === 'VENTA_NO_CUADRA_CON_SUS_PAGOS' && (int) $f->entidad_id === $venta->id));
    }

    // ============================================================ el comando, la bitácora y la portada

    public function test_el_comando_dice_que_todo_cuadra_y_deja_constancia(): void
    {
        $this->assertSame(0, Artisan::call('descuadres:revisar'));
        $this->assertStringContainsString('Todo cuadra', Artisan::output());

        $registro = Descuadres::ultima();
        $this->assertNotNull($registro, 'la revisión deja constancia también cuando todo cuadra');
        $this->assertSame(0, $registro->detalle['cantidad']);
    }

    public function test_el_comando_falla_y_explica_cuando_algo_no_cuadra(): void
    {
        DB::table('productos')->where('codigo', 'P-0004')->update(['stock_actual' => DB::raw('stock_actual + 3')]);

        $this->assertSame(1, Artisan::call('descuadres:revisar'));
        $salida = Artisan::output();

        $this->assertStringContainsString('1 cosa(s) que no cuadran', $salida);
        $this->assertStringContainsString(Descuadres::REGLAS['STOCK_DISTINTO_DEL_KARDEX'], $salida);
        $this->assertStringContainsString('P-0004', $salida);

        $this->assertSame(1, Descuadres::ultima()->detalle['cantidad']);
        $this->assertSame(['STOCK_DISTINTO_DEL_KARDEX' => 1], Descuadres::ultima()->detalle['por_regla']);
    }

    public function test_la_revision_esta_agendada_despues_del_respaldo(): void
    {
        $consola = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertMatchesRegularExpression("/Schedule::command\\('respaldo:crear'\\)->dailyAt\\('01:00'\\)/", $consola);
        $this->assertMatchesRegularExpression("/Schedule::command\\('descuadres:revisar'\\)->dailyAt\\('01:30'\\)->withoutOverlapping\\(\\)/", $consola);
    }

    public function test_la_portada_avisa_a_quien_gestiona_solo_si_algo_no_cuadra(): void
    {
        $admin = $this->u('admin');

        // Todo cuadra: no se dice nada.
        Artisan::call('descuadres:revisar');
        $this->actingAs($admin)->get(route('inicio'))->assertOk()->assertDontSee('data-descuadres', false);

        // Algo no cuadra: se dice, con la regla en palabras.
        DB::table('productos')->where('codigo', 'P-0004')->update(['stock_actual' => DB::raw('stock_actual + 3')]);
        Artisan::call('descuadres:revisar');

        $this->actingAs($admin)->get(route('inicio'))->assertOk()
            ->assertSee('data-descuadres', false)
            ->assertSee('cosa no cuadra')
            ->assertSee(Descuadres::REGLAS['STOCK_DISTINTO_DEL_KARDEX']);

        // El cajero no gestiona: no le toca verlo.
        $this->actingAs($this->u('cajero1'))->get(route('inicio'))->assertOk()->assertDontSee('data-descuadres', false);
    }

    // ============================================================ el peso de los QR

    public function test_la_imagen_del_qr_solo_vive_mientras_el_cobro_esta_pendiente(): void
    {
        $turno = $this->turno();
        $cajero = $this->u('cajero1');

        $pendiente = CobrosQr::generar($turno, $cajero, 10);
        $this->assertNotNull($pendiente->payload, 'pendiente: la pantalla la necesita');

        $pagado = CobrosQr::generar($turno, $cajero, 11);
        CobrosQr::confirmarAMano($pagado, $cajero);
        $this->assertNull($pagado->fresh()->payload);

        $anulado = CobrosQr::generar($turno, $cajero, 12);
        CobrosQr::anular($anulado, $cajero);
        $this->assertNull($anulado->fresh()->payload);

        $vencido = CobrosQr::generar($turno, $cajero, 13);
        DB::table('cobros_qr')->where('id', $vencido->id)->update(['expira_en' => now()->subMinutes(5)]);
        CobrosQr::vencer($vencido->fresh());
        $this->assertSame(CobroQr::EXPIRADO, $vencido->fresh()->estado);
        $this->assertNull($vencido->fresh()->payload);

        $this->assertNotNull($pendiente->fresh()->payload, 'los demás no tocan al pendiente');
    }

    public function test_el_mostrador_conserva_la_imagen_que_ya_tenia(): void
    {
        $this->turno($this->u('admin'));
        $html = $this->actingAs($this->u('admin'))->get(route('pos.index'))->getContent();

        $this->assertStringContainsString('actualizarQr(pago, datos)', $html);
        $this->assertStringContainsString('this.actualizarQr(pago, await r.json())', $html);
        $this->assertStringNotContainsString('pago.qr = await r.json()', $html);
    }

    // ============================================================ la cuenta de la aplicación

    private const TABLAS_QUE_LA_APP_BORRA = ['cajas', 'cargos', 'categorias', 'clientes', 'productos', 'proveedores', 'rol_permiso', 'roles', 'unidades_medida', 'usuarios'];

    public function test_la_cuenta_de_la_aplicacion_no_tiene_delete_sobre_toda_la_base(): void
    {
        foreach (['scripts/crear-usuario-app.sh', 'docs/sql/produccion/03_usuario_app.sh'] as $archivo) {
            $script = (string) file_get_contents(base_path('../'.$archivo));

            $this->assertDoesNotMatchRegularExpression('/GRANT[^;]*\bDELETE\b[^;]*\.\*/is', $script, "{$archivo} da DELETE sobre toda la base");
            $this->assertMatchesRegularExpression('/GRANT SELECT, INSERT, UPDATE, EXECUTE, SHOW VIEW, TRIGGER, LOCK TABLES/', $script);

            foreach (self::TABLAS_QUE_LA_APP_BORRA as $tabla) {
                $this->assertMatchesRegularExpression("/GRANT DELETE ON \\\$?\\{?(BASE|ventas_db)\\}?\\.{$tabla} TO/", $script, "{$archivo} no da DELETE en {$tabla}");
            }

            // Lo que nunca se borra.
            foreach (['ventas', 'venta_detalle', 'venta_pagos', 'comprobantes', 'movimientos_caja', 'movimientos_inventario', 'auditoria', 'devoluciones', 'sesiones_caja', 'cobros_qr', 'lotes'] as $tabla) {
                $this->assertDoesNotMatchRegularExpression("/GRANT DELETE ON [^ ]*\\.{$tabla}\\b/", $script, "{$archivo} da DELETE en {$tabla}");
            }
        }
    }

    public function test_las_dos_formas_de_crear_la_cuenta_dan_los_mismos_permisos_de_borrado(): void
    {
        $sacar = fn (string $archivo) => collect(
            preg_match_all('/GRANT DELETE ON [^.]+\.(\w+) TO/', (string) file_get_contents(base_path('../'.$archivo)), $m) ? $m[1] : []
        )->sort()->values()->all();

        $this->assertSame(self::TABLAS_QUE_LA_APP_BORRA, $sacar('scripts/crear-usuario-app.sh'));
        $this->assertSame(self::TABLAS_QUE_LA_APP_BORRA, $sacar('docs/sql/produccion/03_usuario_app.sh'));
    }

    public function test_el_script_de_cuenta_quita_el_delete_global_de_una_cuenta_antigua(): void
    {
        $script = (string) file_get_contents(base_path('../scripts/crear-usuario-app.sh'));

        $this->assertMatchesRegularExpression('/REVOKE DELETE ON \$\{BASE\}\.\* FROM .ventas_app.@/', $script);
    }

    public function test_la_aplicacion_solo_borra_en_las_tablas_a_las_que_se_le_da_delete(): void
    {
        // Cada `->delete()` del código de la aplicación es de un modelo cuya tabla está en la lista.
        $permitidas = collect(self::TABLAS_QUE_LA_APP_BORRA)->flip();
        $mapa = [
            'CajaFisicaController' => 'cajas', 'CargoController' => 'cargos', 'CategoriaController' => 'categorias',
            'ClienteController' => 'clientes', 'ProductoController' => 'productos', 'ProveedorController' => 'proveedores',
            'RolController' => 'roles', 'UnidadMedidaController' => 'unidades_medida', 'UsuarioController' => 'usuarios',
        ];

        $archivos = collect(glob(app_path('{Http/Controllers,Services}/*.php'), GLOB_BRACE) ?: [])
            ->filter(fn ($f) => (bool) preg_match('/\$\w+->delete\(\)|->detach\(|::destroy\(/', (string) file_get_contents($f)))
            ->map(fn ($f) => basename($f, '.php'))
            ->reject(fn ($c) => $c === 'ReglasEnPhp')
            ->values();

        foreach ($archivos as $clase) {
            $this->assertArrayHasKey($clase, $mapa, "{$clase} borra filas y no está en la lista de tablas con DELETE: ¿hay que darle permiso o es un error?");
            $this->assertTrue($permitidas->has($mapa[$clase]), "{$clase} borra en {$mapa[$clase]} sin permiso");
        }
    }
}
