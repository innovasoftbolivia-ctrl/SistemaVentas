<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El buscador de arriba busca productos. Antes buscaba empleados en todas las
 * pantallas, que es lo que el negocio casi nunca necesita buscar.
 */
class BuscadorDelEncabezadoTest extends TestCase
{
    use DatabaseTransactions;

    /** Cambiar de cuenta exige sesión limpia (`auth.session` manda al login). */
    private function como(string $usuario): static
    {
        $this->flushSession();
        app('auth')->forgetGuards();

        return $this->actingAs(Usuario::where('usuario', $usuario)->firstOrFail());
    }

    public function test_quien_gestiona_el_catalogo_busca_productos_desde_cualquier_pantalla(): void
    {
        $this->como('almacen')->get(route('inventario.index'))->assertOk()
            ->assertSee('action="'.route('productos.index').'"', false)
            ->assertSee('Buscar producto por nombre, código o código de barras', false)
            ->assertDontSee('Buscar empleado');

        $this->como('admin')->get(route('inicio'))->assertOk()
            ->assertSee('id="buscar-producto"', false);
    }

    public function test_la_busqueda_lleva_al_catalogo_filtrado_y_repite_lo_buscado(): void
    {
        $html = $this->como('admin')->get(route('productos.index', ['buscar' => 'Arroz extra']))->assertOk()
            ->assertSee('Arroz extra 1 kg')
            ->assertDontSee('Gaseosa 1.5 L')
            ->getContent();

        $this->assertMatchesRegularExpression('/id="buscar-producto"[^>]*value="Arroz extra"/s', $html);
    }

    public function test_no_repite_en_el_encabezado_lo_buscado_en_otras_listas(): void
    {
        $html = $this->como('admin')->get(route('clientes.index', ['buscar' => 'Mendoza']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="buscar-producto"[^>]*value=""/s', $html);
    }

    public function test_el_cajero_no_ve_un_buscador_que_lo_lleve_a_una_pantalla_prohibida(): void
    {
        $this->como('cajero1')->get(route('inicio'))->assertOk()
            ->assertDontSee('id="buscar-producto"', false);
    }

    public function test_en_el_mostrador_no_aparece(): void
    {
        $this->como('admin')->get(route('pos.index'))->assertOk()
            ->assertDontSee('id="buscar-producto"', false);
    }
}
