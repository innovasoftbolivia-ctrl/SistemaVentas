<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Eliminar se hace desde el propio listado, sin entrar a cada ficha, y solo lo
 * ve quien puede eliminar.
 */
class EliminarDesdeElListadoTest extends TestCase
{
    use DatabaseTransactions;

    private function como(string $usuario): static
    {
        $this->flushSession();
        app('auth')->forgetGuards();

        return $this->actingAs(Usuario::where('usuario', $usuario)->firstOrFail());
    }

    /** @return array<string, string> ruta => lo que tiene que decir el botón */
    private static function listados(): array
    {
        return [
            'productos.index' => 'title="Eliminar"',
            'clientes.index' => 'title="Eliminar"',
            'categorias.index' => 'title="Eliminar"',
            'unidades.index' => 'title="Eliminar"',
            'proveedores.index' => 'title="Eliminar"',
            'cargos.index' => 'title="Eliminar"',
            'usuarios.index' => 'title="Eliminar"',
            'cajas.index' => 'title="Dar de baja"',
            'empleados.index' => 'title="Registrar cese"',
        ];
    }

    public function test_el_administrador_puede_eliminar_desde_cada_listado(): void
    {
        foreach (self::listados() as $ruta => $boton) {
            $this->como('admin')->get(route($ruta))->assertOk()->assertSee($boton, false);
        }

        $this->como('admin')->get(route('roles.index'))->assertOk()->assertSee('Eliminar');
    }

    public function test_el_almacenero_no_ve_el_boton_en_el_catalogo(): void
    {
        foreach (['productos.index', 'categorias.index', 'unidades.index', 'proveedores.index'] as $ruta) {
            $this->como('almacen')->get(route($ruta))->assertOk()->assertDontSee('title="Eliminar"', false);
        }
    }

    public function test_desde_la_lista_se_elimina_un_producto_sin_historial(): void
    {
        $producto = Producto::create([
            'categoria_id' => Categoria::first()->id,
            'unidad_medida_id' => UnidadMedida::where('codigo', 'UND')->value('id'),
            'codigo' => 'P-9303',
            'nombre' => 'Producto para borrar desde la lista',
            'precio_compra' => '1.00',
            'precio_venta' => '2.00',
            'afecto_impuesto' => 1,
            'stock_minimo' => '0',
            'activo' => 1,
        ]);

        $this->como('admin')->get(route('productos.index', ['buscar' => 'P-9303']))->assertOk()
            ->assertSee('borrando = true', false);

        $this->como('admin')->delete(route('productos.destroy', $producto))
            ->assertRedirect(route('productos.index'));

        $this->assertNull(Producto::find($producto->id));
    }

    public function test_la_lista_sabe_cuales_tienen_historial_para_avisar_que_se_descatalogan(): void
    {
        $conHistorial = Producto::whereHas('movimientos')->firstOrFail();

        $html = $this->como('admin')->get(route('productos.index', ['buscar' => $conHistorial->codigo]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('conHistorial = true', $html);
        $this->assertStringNotContainsString('conHistorial = false', $html);
    }
}
