<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Ventas;
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

    /** Mismo grupo de permisos que ya protege la ficha del turno (`caja.show`). */
    public function test_quien_ve_el_turno_tambien_ve_el_resumen_imprimible(): void
    {
        $sesion = $this->turno();

        foreach ([$this->cajero(), $this->admin(), $this->almacenero()] as $usuario) {
            $this->actingAs($usuario)->get(route('caja.imprimir', $sesion))->assertOk();
        }
    }

    // -------------------------------------------------------------- contenido

    public function test_el_resumen_abierto_deja_el_arqueo_en_blanco_para_llenar_a_mano(): void
    {
        $sesion = $this->turno(inicial: 100);
        $this->vender($sesion, 2);

        $respuesta = $this->actingAs($this->admin())->get(route('caja.imprimir', $sesion))->assertOk();

        $respuesta->assertSee('todavía no se cerró');
        $respuesta->assertSee('Turno abierto — para revisar antes de cerrar', false);
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
        $respuesta->assertDontSee('todavía no se cerró');
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

        $respuesta = $this->actingAs($this->admin())->get(route('caja.imprimir', $sesion))->assertOk();

        $respuesta->assertSee('Efectivo');
        $respuesta->assertSee($tarjeta->nombre);
    }

    public function test_el_movimiento_de_egreso_aparece_en_el_resumen(): void
    {
        $sesion = $this->turno(inicial: 100);
        Cajas::movimiento($sesion, $this->cajero(), 'EGRESO', 'Pago a proveedor de bolsas', 15);

        $this->actingAs($this->admin())
            ->get(route('caja.imprimir', $sesion))
            ->assertOk()
            ->assertSee('Pago a proveedor de bolsas');
    }
}
