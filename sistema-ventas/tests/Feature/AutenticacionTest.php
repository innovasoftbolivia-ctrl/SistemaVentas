<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AutenticacionTest extends TestCase
{
    use DatabaseTransactions;

    private function cuentaAdmin(string $clave = 'admin123'): Usuario
    {
        $cuenta = Usuario::where('usuario', 'admin')->firstOrFail();
        $cuenta->forceFill(['password_hash' => Hash::make($clave), 'activo' => 1])->save();

        return $cuenta;
    }

    public function test_la_pantalla_de_login_se_muestra(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_una_cuenta_valida_ingresa_y_queda_registrada(): void
    {
        $cuenta = $this->cuentaAdmin();

        $respuesta = $this->post('/login', [
            'usuario' => 'admin',
            'password' => 'admin123',
        ]);

        $respuesta->assertRedirect('/inicio');
        $this->assertAuthenticatedAs($cuenta->fresh());

        $this->assertNotNull($cuenta->fresh()->ultimo_acceso);
        $this->assertDatabaseHas('auditoria', [
            'usuario_id' => $cuenta->id,
            'accion' => 'LOGIN',
        ]);
    }

    public function test_una_contrasena_incorrecta_no_ingresa_y_suma_un_intento_fallido(): void
    {
        $cuenta = $this->cuentaAdmin();
        $cuenta->forceFill(['intentos_fallidos' => 0])->save();

        $this->post('/login', [
            'usuario' => 'admin',
            'password' => 'no-es-la-clave',
        ])->assertSessionHasErrors('usuario');

        $this->assertGuest();
        $this->assertSame(1, $cuenta->fresh()->intentos_fallidos);
    }

    public function test_una_cuenta_desactivada_no_ingresa_aunque_la_clave_sea_correcta(): void
    {
        $cuenta = $this->cuentaAdmin();
        $cuenta->forceFill(['activo' => 0])->save();

        $this->post('/login', [
            'usuario' => 'admin',
            'password' => 'admin123',
        ])->assertSessionHasErrors('usuario');

        $this->assertGuest();
    }

    public function test_un_empleado_cesado_pierde_el_acceso(): void
    {
        $cuenta = $this->cuentaAdmin();

        // El trigger de la base desactiva la cuenta al cesar al empleado.
        $cuenta->empleado->update([
            'estado' => 'CESADO',
            'fecha_cese' => now()->toDateString(),
            'motivo_cese' => 'prueba',
        ]);

        $this->assertFalse((bool) $cuenta->fresh()->activo);

        $this->post('/login', [
            'usuario' => 'admin',
            'password' => 'admin123',
        ])->assertSessionHasErrors('usuario');

        $this->assertGuest();
    }

    public function test_la_sesion_se_corta_si_la_cuenta_se_deshabilita_mientras_navega(): void
    {
        $cuenta = $this->cuentaAdmin();

        $this->actingAs($cuenta)->get('/empleados')->assertOk();

        $cuenta->forceFill(['activo' => 0])->save();

        $this->actingAs($cuenta->fresh())
            ->get('/empleados')
            ->assertRedirect('/login');
    }

    public function test_cerrar_sesion_deja_al_usuario_fuera(): void
    {
        $cuenta = $this->cuentaAdmin();

        $this->actingAs($cuenta)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_un_visitante_no_entra_a_las_pantallas_internas(): void
    {
        $this->get('/empleados')->assertRedirect('/login');
    }

    public function test_el_empleado_sin_cuenta_existe_pero_no_puede_ingresar(): void
    {
        $jorge = Empleado::where('documento', '10000004')->firstOrFail();

        $this->assertNull($jorge->usuario);
        $this->assertSame('ACTIVO', $jorge->estado);
        $this->assertFalse(Auth::attempt(['usuario' => 'jorge', 'password' => 'lo-que-sea']));
    }
}
