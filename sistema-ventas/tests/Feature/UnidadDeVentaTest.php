<?php

namespace Tests\Feature;

use App\Models\Categoria;
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

    private function datos(string $unidad, array $mas = []): array
    {
        return [
            'categoria_id' => Categoria::first()->id,
            'unidad_medida_id' => UnidadMedida::where('codigo', $unidad)->value('id'),
            'codigo' => 'P-9202',
            'nombre' => 'Galletas de prueba',
            'precio_compra' => '1.00',
            'precio_venta' => '1.50',
            'afecto_impuesto' => 1,
            'stock_minimo' => '0',
            'activo' => 1,
            ...$mas,
        ];
    }

    public function test_diez_paquetes_de_seis_son_sesenta_unidades(): void
    {
        $this->admin()->post(route('productos.store'), $this->datos('UND', [
            'viene_en_empaque' => '1', 'nombre_empaque' => 'Paquete', 'contenido_empaque' => '6',
            'empaques' => '10', 'sueltas' => '0',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $producto = Producto::where('codigo', 'P-9202')->firstOrFail();
        $this->assertSame('UND', $producto->unidadMedida->codigo);
        $this->assertEquals(60, (float) $producto->stock_actual);
    }

    public function test_lo_que_llega_en_caja_o_paquete_no_se_vende_por_caja_ni_paquete(): void
    {
        foreach (['PQT', 'CAJA'] as $unidad) {
            $this->admin()->post(route('productos.store'), $this->datos($unidad, [
                'viene_en_empaque' => '1', 'nombre_empaque' => 'Paquete', 'contenido_empaque' => '6',
                'empaques' => '10', 'sueltas' => '0',
            ]))->assertSessionHasErrors('unidad_medida_id');
        }

        $this->assertNull(Producto::where('codigo', 'P-9202')->first());
    }

    public function test_la_caja_entera_se_sigue_pudiendo_vender_si_llega_suelta(): void
    {
        $this->admin()->post(route('productos.store'), $this->datos('CAJA', [
            'viene_en_empaque' => '0', 'stock_inicial' => '4',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('CAJA', Producto::where('codigo', 'P-9202')->firstOrFail()->unidadMedida->codigo);
    }
}
