<?php

namespace Tests\Feature;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Con MOSTRAR_RESPALDOS=false (lo de fábrica) el cliente no ve respaldos en
 * ninguna parte, pero el respaldo de todas las noches sigue programado y nadie
 * pierde el permiso. phpunit.xml los muestra para el resto de las pruebas.
 */
class RespaldosOcultosTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ventas.mostrar_respaldos' => false]);
    }

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    public function test_el_menu_no_ofrece_respaldos(): void
    {
        $this->actingAs($this->admin())->get(route('inicio'))->assertOk()
            ->assertDontSee('href="/respaldos"', false);
    }

    public function test_la_pantalla_y_sus_acciones_no_existen(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('respaldos.index'))->assertNotFound();
        $this->post(route('respaldos.store'))->assertNotFound();
        $this->get('/respaldos/ventas_db_2026-09-19_010000_abcd1234.sql.gz/descargar')->assertNotFound();
    }

    public function test_roles_no_muestra_el_permiso_y_guardar_no_se_lo_quita_a_nadie(): void
    {
        $this->actingAs($this->admin())->get(route('roles.index'))->assertOk()
            ->assertDontSee('Hacer y descargar respaldos');

        $rol = Rol::where('nombre', 'Administrador')->firstOrFail();
        $visibles = $rol->permisos()->where('codigo', '<>', 'respaldos.gestionar')->pluck('permisos.id')->all();

        // El formulario solo manda los permisos que se ven.
        $this->actingAs($this->admin())->put(route('roles.update', $rol), [
            'nombre' => $rol->nombre, 'descripcion' => $rol->descripcion, 'activo' => 1, 'permisos' => $visibles,
        ])->assertRedirect(route('roles.index'));

        $this->assertTrue($rol->permisos()->where('codigo', 'respaldos.gestionar')->exists());
        $this->assertSame(count($visibles) + 1, $rol->permisos()->count());
    }

    public function test_el_respaldo_de_cada_noche_sigue_programado(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'respaldo:crear'));

        $this->assertCount(1, $eventos);
        $this->assertSame('0 1 * * *', $eventos->first()->expression);
        $this->assertNotNull(Permiso::where('codigo', 'respaldos.gestionar')->first());
    }

    public function test_con_la_opcion_encendida_vuelven(): void
    {
        config(['ventas.mostrar_respaldos' => true]);

        $this->actingAs($this->admin())->get(route('respaldos.index'))->assertOk();
        $this->actingAs($this->admin())->get(route('roles.index'))->assertSee('Hacer y descargar respaldos');
    }
}
