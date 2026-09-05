<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Ventas;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CajaTest extends TestCase
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

    private function turno(?Usuario $usuario = null, float $inicial = 100): SesionCaja
    {
        return Cajas::abrir(Caja::firstOrFail(), $usuario ?? $this->cajero(), $inicial);
    }

    private function vender(SesionCaja $sesion, float $cantidad = 2, string $codigo = 'P-0004'): void
    {
        $producto = Producto::where('codigo', $codigo)->firstOrFail();

        Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $producto->id, 'cantidad' => $cantidad, 'precio_unitario' => (float) $producto->precio_venta]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    // -------------------------------------------------------------- permisos

    /**
     * Solo el administrador cierra la caja (O4: el arqueo lo hace quien no
     * tuvo la mano en el cajón durante el turno). El cajero abre y vende,
     * pero perdió `caja.cerrar` — probarlo por HTTP y no llamando al
     * servicio directo, porque el permiso se aplica en el middleware de la
     * ruta, no dentro de `Cajas::cerrar()`.
     */
    public function test_el_cajero_ya_no_puede_cerrar_su_propia_caja(): void
    {
        $sesion = $this->turno($this->cajero());

        $this->actingAs($this->cajero())
            ->post(route('caja.cerrar', $sesion), ['monto_declarado' => 100])
            ->assertForbidden();

        $this->assertTrue($sesion->fresh()->estaAbierta());
    }

    public function test_el_administrador_si_puede_cerrar_la_caja_del_cajero(): void
    {
        $sesion = $this->turno($this->cajero());

        $this->actingAs($this->admin())
            ->post(route('caja.cerrar', $sesion), ['monto_declarado' => 100])
            ->assertRedirect(route('caja.imprimir', $sesion));

        $this->assertFalse($sesion->fresh()->estaAbierta());
    }

    /**
     * El mismo cajero no puede terminar con dos turnos abiertos en dos cajas
     * distintas: sin esto, `Cajas::sesionDe()` (que usa `->first()`, sin
     * criterio de desempate) le atribuiría todas sus ventas siempre a una
     * sola de las dos sesiones, de forma no determinista, mientras el
     * efectivo real queda repartido entre dos cajones. La única defensa
     * anterior era un SELECT-antes-de-INSERT en PHP —una condición de carrera
     * real bajo dos peticiones simultáneas—, así que la garantía de verdad
     * tiene que estar en la base: el índice único `uq_sesion_usuario_abierta`.
     */
    public function test_la_base_impide_dos_sesiones_abiertas_para_el_mismo_usuario(): void
    {
        $otraCaja = Caja::create(['nombre' => 'Caja 2', 'ubicacion' => 'Depósito']);
        $usuario = $this->cajero();

        $this->turno($usuario);

        $this->expectException(QueryException::class);

        // Directo al modelo, saltándose el chequeo de `Cajas::abrir()`: así se
        // prueba el candado real (el de la base), no el atajo de PHP que solo
        // cierra la ventana de carrera a medias.
        SesionCaja::create([
            'caja_id' => $otraCaja->id,
            'usuario_apertura_id' => $usuario->id,
            'fecha_apertura' => now(),
            'monto_inicial' => 50,
            'estado' => 'ABIERTA',
        ]);
    }

    /** Mismo grupo de permisos que ya protege la ficha del turno (`caja.show`), pero solo si ya está cerrada. */
    public function test_quien_ve_el_turno_tambien_ve_el_resumen_imprimible_una_vez_cerrado(): void
    {
        $sesion = $this->turno();
        Cajas::cerrar($sesion->fresh(), $this->admin(), 100);

        foreach ([$this->cajero(), $this->admin(), $this->almacenero()] as $usuario) {
            $this->actingAs($usuario)->get(route('caja.imprimir', $sesion))->assertOk();
        }
    }

    // -------------------------------------------------------------- contenido

    /**
     * El resumen es la constancia del cierre, no un borrador para revisar
     * antes: con la sesión todavía abierta no hay documento que mostrar —
     * de haberlo, el arqueo saldría en blanco, que es justo lo que no se
     * quiere.
     */
    public function test_no_se_puede_imprimir_el_resumen_con_el_turno_abierto(): void
    {
        $sesion = $this->turno();

        $this->actingAs($this->admin())
            ->get(route('caja.imprimir', $sesion))
            ->assertRedirect(route('caja.show', $sesion))
            ->assertSessionHas('error');
    }

    public function test_el_resumen_cerrado_muestra_lo_declarado_y_la_diferencia(): void
    {
        $sesion = $this->turno(inicial: 100);
        $this->vender($sesion, 2);

        $esperado = $sesion->fresh()->efectivoEsperado();
        Cajas::cerrar($sesion->fresh(), $this->cajero(), $esperado - 5, 'Faltó vuelto de una venta');

        $respuesta = $this->actingAs($this->admin())->get(route('caja.imprimir', $sesion))->assertOk();

        $respuesta->assertSee('Turno cerrado');
        $respuesta->assertSee('Faltó vuelto de una venta');
    }

    public function test_el_desglose_por_metodo_de_pago_cuadra_con_lo_vendido(): void
    {
        $sesion = $this->turno(inicial: 100);
        $tarjeta = MetodoPago::where('afecta_caja', 0)->firstOrFail();
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        // Una venta en efectivo y otra con tarjeta: el desglose debe separarlas.
        Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => (float) $producto->precio_venta]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
        Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => (float) $producto->precio_venta]],
            pagos: [['metodo_pago_id' => $tarjeta->id, 'monto' => null]],
        );
        Cajas::cerrar($sesion->fresh(), $this->admin(), $sesion->fresh()->efectivoEsperado());

        $respuesta = $this->actingAs($this->admin())->get(route('caja.imprimir', $sesion))->assertOk();

        $respuesta->assertSee('Efectivo');
        $respuesta->assertSee($tarjeta->nombre);
    }

    public function test_el_movimiento_de_egreso_aparece_en_el_resumen(): void
    {
        $sesion = $this->turno(inicial: 100);
        Cajas::movimiento($sesion, $this->cajero(), 'EGRESO', 'Pago a proveedor de bolsas', 15);
        Cajas::cerrar($sesion->fresh(), $this->admin(), $sesion->fresh()->efectivoEsperado());

        $this->actingAs($this->admin())
            ->get(route('caja.imprimir', $sesion))
            ->assertOk()
            ->assertSee('Pago a proveedor de bolsas');
    }
}
