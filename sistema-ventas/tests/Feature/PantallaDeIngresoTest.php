<?php

namespace Tests\Feature;

use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La pantalla de ingreso: el nombre del negocio arriba, quién desarrolló el
 * sistema abajo, y los errores a la vista.
 */
class PantallaDeIngresoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_muestra_el_nombre_del_negocio_y_quien_lo_desarrollo(): void
    {
        DB::table('configuracion')->updateOrInsert(['clave' => 'negocio_nombre'], ['valor' => 'Minimarket de Prueba']);
        Config::olvidar();

        $this->get(route('login'))->assertOk()
            ->assertSee('Minimarket de Prueba')
            ->assertSee('Ingresa para empezar tu turno')
            ->assertSee('Desarrollado por', false)
            ->assertSee('InnovaDevs');
    }

    public function test_sin_desarrollador_configurado_no_aparece_el_pie(): void
    {
        config(['ventas.desarrollado_por' => '']);

        $this->get(route('login'))->assertOk()->assertDontSee('data-desarrollado-por', false);
    }

    public function test_una_clave_equivocada_se_avisa_en_la_misma_pantalla(): void
    {
        $this->from(route('login'))->post(route('login.store'), ['usuario' => 'admin', 'password' => 'no-es-esta'])
            ->assertRedirect(route('login'));

        $this->get(route('login'))->assertSee('No se pudo ingresar')->assertSee('role="alert"', false);
    }
}
