<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El negocio vende de a uno: un producto que llega en caja o paquete se cuenta
 * en unidades, y las pantallas lo dicen en palabras («66 unidades», no
 * «66 PQT»).
 */
class UnidadDeVentaTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): static
    {
        return $this->actingAs(Usuario::where('usuario', 'admin')->firstOrFail());
    }

    public function test_un_producto_nuevo_arranca_vendiendose_por_unidad(): void
    {
        $und = UnidadMedida::where('codigo', 'UND')->value('id');

        $html = $this->admin()->get(route('productos.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/unidad: Number\('.$und.'\)/', $html);
    }

    public function test_al_editar_se_respeta_la_unidad_que_tiene(): void
    {
        $kilo = UnidadMedida::where('codigo', 'KG')->value('id');
        $producto = Producto::where('unidad_medida_id', $kilo)->firstOrFail();

        $html = $this->admin()->get(route('productos.edit', $producto))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/unidad: Number\('.$kilo.'\)/', $html);
    }

    public function test_las_unidades_llegan_con_su_plural_para_la_cuenta_en_palabras(): void
    {
        $html = $this->admin()->get(route('productos.create'))->assertOk()->getContent();

        foreach (['unidades', 'kilogramos', 'litros', 'paquetes'] as $plural) {
            // @js lo escribe dentro de JSON.parse('…'), con las comillas escapadas.
            $this->assertStringContainsString('plural\u0022:\u0022'.$plural.'\u0022', $html);
        }
        // El resumen del stock inicial usa el plural, no el código.
        $this->assertStringContainsString('unidad="unidadPlural"', file_get_contents(resource_path('views/productos/form.blade.php')));
    }

    public function test_la_ficha_cuenta_el_ingreso_en_palabras(): void
    {
        $producto = Producto::activos()->whereHas('unidadMedida', fn ($q) => $q->where('codigo', 'UND'))->firstOrFail();

        $this->admin()->get(route('productos.show', $producto))->assertOk()
            ->assertSee('&quot;unidades&quot;', false)
            ->assertDontSee('&quot;UND&quot;', false);
    }
}
