<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Usuario;
use App\Services\Cajas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El cobro vive en su propia ventana: armar la venta y cobrarla son dos
 * trabajos, y en un solo panel la forma de pago quedaba al fondo de una lista
 * que había que bajar.
 */
class VentanaDeCobroTest extends TestCase
{
    use DatabaseTransactions;

    private function mostrador(): string
    {
        $admin = Usuario::where('usuario', 'admin')->firstOrFail();
        Cajas::sesionDe($admin) ?? Cajas::abrir(Caja::orderBy('id')->firstOrFail(), $admin, 100);

        return $this->actingAs($admin)->get(route('pos.index'))->assertOk()->getContent();
    }

    public function test_el_panel_solo_abre_la_ventana_y_el_cobro_esta_dentro(): void
    {
        $html = $this->mostrador();

        // El botón del panel no envía el formulario: abre la ventana.
        $this->assertStringContainsString('x-ref="abrirCobro" @click="abrirCobro()"', $html);
        $this->assertStringContainsString('x-show="cobrando"', $html);
        $this->assertStringContainsString('Cobrar venta', $html);
        $this->assertStringContainsString('Confirmar cobro de', $html);

        // La ventana va fuera del formulario y se ata a él con `form=`.
        $this->assertStringContainsString('id="pos-formulario"', $html);
        $this->assertStringContainsString('form="pos-formulario"', $html);
    }

    public function test_las_formas_de_pago_son_tarjetas_con_icono(): void
    {
        $html = $this->mostrador();

        $this->assertStringContainsString('data-medios-de-pago', $html);
        $this->assertStringContainsString('iconoMetodo(m.codigo)', $html);
        // El código del método viaja a la pantalla: sin él no hay ni ícono ni nombre corto.
        $this->assertMatchesRegularExpression('/metodos:.*codigo/s', substr($html, strpos($html, 'metodos:'), 200));
    }

    public function test_el_descuento_y_el_cliente_van_plegados_dentro_de_la_ventana(): void
    {
        $html = $this->mostrador();

        $this->assertStringContainsString('Descuento y cliente', $html);
        $this->assertStringContainsString('data-mas-opciones', $html);
        $this->assertStringContainsString('id="pos-opciones"', $html);
    }

    public function test_el_cobro_por_qr_y_los_qr_pagados_sin_venta_siguen_en_la_ventana(): void
    {
        $html = $this->mostrador();

        $this->assertStringContainsString('data-generar-qr', $html);
        $this->assertStringContainsString('data-qr-libres', $html);
        $this->assertStringContainsString('data-recibido', $html);
    }
}
