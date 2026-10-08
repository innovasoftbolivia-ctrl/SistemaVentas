<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * El primer bloque de la auditoría de octubre de 2026: lo que protege la base
 * y el acceso antes de instalar en el servidor de un cliente.
 *
 *   - un parche no puede elegir base (pisaba la de otra instalación);
 *   - el esquema ya no borra la base;
 *   - la venta del mostrador no se registra dos veces;
 *   - se puede recuperar la contraseña del administrador sin tocar MySQL.
 */
class ProteccionDeLaInstalacionTest extends TestCase
{
    use DatabaseTransactions;

    private function raiz(string $ruta): string
    {
        return base_path('../'.$ruta);
    }

    private function leer(string $ruta): string
    {
        return (string) file_get_contents($this->raiz($ruta));
    }

    /** El SQL sin sus comentarios: lo que de verdad se ejecuta. */
    private function sentencias(string $ruta): string
    {
        return (string) preg_replace('/^\s*--.*$/m', '', $this->leer($ruta));
    }

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    // ============================================================ parches

    public function test_ningun_parche_elige_la_base(): void
    {
        $parches = glob($this->raiz('docs/sql/parches/*.sql'));
        $this->assertNotEmpty($parches);

        foreach ($parches as $parche) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*USE\s/mi',
                (string) file_get_contents($parche),
                basename($parche).': un USE pisa la base que pide aplicar-parches.sh y cambia la de otra instalación'
            );
        }
    }

    public function test_aplicar_parches_se_niega_si_alguno_trae_un_use(): void
    {
        $script = $this->leer('scripts/aplicar-parches.sh');

        $this->assertMatchesRegularExpression('/grep -lE .\^\[\[:space:\]\]\*USE\[\[:space:\]\]. "\$DIRECTORIO"\/\*\.sql/', $script);
        $this->assertMatchesRegularExpression('/ERROR: estos parches traen un USE.*?exit 1/s', $script);

        // La guarda va antes de aplicar nada.
        $this->assertLessThan(strpos($script, 'Aplicando $archivo'), strpos($script, 'ERROR: estos parches traen un USE'));
    }

    // ============================================================ esquema

    public function test_el_esquema_ya_no_borra_ni_crea_la_base(): void
    {
        $esquema = $this->sentencias('docs/sql/01_schema_mysql.sql');

        $this->assertDoesNotMatchRegularExpression('/DROP\s+DATABASE/i', $esquema);
        $this->assertDoesNotMatchRegularExpression('/CREATE\s+DATABASE/i', $esquema);
        $this->assertMatchesRegularExpression('/^USE ventas_db;/m', $esquema);
    }

    public function test_crear_la_base_no_borra_nada(): void
    {
        $crear = $this->sentencias('docs/sql/00_crear_base.sql');

        $this->assertMatchesRegularExpression('/CREATE DATABASE IF NOT EXISTS ventas_db/i', $crear);
        $this->assertDoesNotMatchRegularExpression('/DROP\s/i', $crear);
    }

    public function test_el_drop_solo_vive_fuera_de_la_carpeta_que_mysql_ejecuta_sola(): void
    {
        // MySQL ejecuta solos los .sql del primer nivel de docs/sql al crear su base
        // por primera vez; las subcarpetas las ignora.
        foreach (glob($this->raiz('docs/sql/*.sql')) as $archivo) {
            $this->assertDoesNotMatchRegularExpression(
                '/DROP\s+DATABASE/i',
                $this->sentencias('docs/sql/'.basename($archivo)),
                basename($archivo).' borraría la base al arrancar'
            );
        }

        $destructivo = $this->leer('docs/sql/desarrollo/borrar_y_crear_base_DESTRUCTIVO.sql');
        $this->assertStringContainsString('DESTRUCTIVO', $destructivo);
        $this->assertMatchesRegularExpression('/DROP DATABASE IF EXISTS ventas_db;/', $destructivo);
    }

    public function test_produccion_monta_crear_base_y_esquema_pero_nunca_lo_destructivo(): void
    {
        $compose = $this->leer('docker-compose.prod.yml');

        $this->assertStringContainsString('docs/sql/00_crear_base.sql:/docker-entrypoint-initdb.d/00_crear_base.sql', $compose);
        $this->assertStringContainsString('docs/sql/01_schema_mysql.sql:/docker-entrypoint-initdb.d/01_schema_mysql.sql', $compose);
        $this->assertStringNotContainsString('DESTRUCTIVO', $compose);
        $this->assertStringNotContainsString('docs/sql/desarrollo', $compose);
    }

    public function test_el_script_de_recrear_se_niega_a_tocar_produccion_y_pide_confirmacion(): void
    {
        $script = $this->leer('scripts/recrear-base-desarrollo.sh');

        $this->assertStringContainsString('REHUSADO', $script);
        $this->assertStringContainsString('ventas_mysql_prod', $script);
        $this->assertMatchesRegularExpression('/\^ventas_db\[A-Za-z0-9_\]\*\$/', $script);
        $this->assertStringContainsString('escribe el nombre de la base', $script);
    }

    // ============================================================ los scripts, ejecutados de verdad

    /**
     * Corre un script de la carpeta scripts/ con bash. Devuelve [código, salida],
     * o salta la prueba si esta máquina no tiene un bash que sirva (en Windows,
     * el de Git; el de WSL no entiende estas rutas).
     *
     * @param  list<string>  $argumentos
     * @param  array<string, string>  $entorno
     * @return array{0: int, 1: string}
     */
    private function correrScript(string $script, array $argumentos = [], array $entorno = [], ?string $raiz = null): array
    {
        $candidatos = PHP_OS_FAMILY === 'Windows'
            ? ['C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe']
            : ['bash'];

        $bash = collect($candidatos)->first(fn ($c) => PHP_OS_FAMILY !== 'Windows' || is_file($c));

        if (! $bash) {
            $this->markTestSkipped('No hay un bash para ejecutar el script.');
        }

        $raiz ??= realpath($this->raiz(''));
        $comando = array_merge([$bash, $raiz.'/scripts/'.$script], $argumentos);
        $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, $raiz, array_merge(getenv(), $entorno));
        $salida = stream_get_contents($tuberias[1]).stream_get_contents($tuberias[2]);
        $codigo = proc_close($proceso);

        return [$codigo, $salida];
    }

    public function test_aplicar_parches_ejecutado_se_niega_si_un_parche_trae_un_use(): void
    {
        $carpeta = sys_get_temp_dir().'/guarda-parches-'.bin2hex(random_bytes(4));
        mkdir($carpeta.'/scripts', 0777, true);
        mkdir($carpeta.'/docs/sql/parches', 0777, true);
        copy($this->raiz('scripts/aplicar-parches.sh'), $carpeta.'/scripts/aplicar-parches.sh');
        file_put_contents($carpeta.'/docs/sql/parches/2099_01_01_malo.sql', "USE ventas_db;\nSELECT 1;\n");

        try {
            [$codigo, $salida] = $this->correrScript('aplicar-parches.sh', [], [], $carpeta);
        } finally {
            @unlink($carpeta.'/docs/sql/parches/2099_01_01_malo.sql');
            @unlink($carpeta.'/scripts/aplicar-parches.sh');
            @rmdir($carpeta.'/docs/sql/parches');
            @rmdir($carpeta.'/docs/sql');
            @rmdir($carpeta.'/docs');
            @rmdir($carpeta.'/scripts');
            @rmdir($carpeta);
        }

        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('2099_01_01_malo.sql', $salida);
        $this->assertStringContainsString('No se aplicó nada', $salida);
    }

    public function test_recrear_base_se_niega_ejecutado_a_tocar_produccion_y_nombres_ajenos(): void
    {
        [$codigo, $salida] = $this->correrScript('recrear-base-desarrollo.sh', ['ventas_db_x', '--si'], ['VENTAS_MYSQL' => 'ventas_mysql_prod']);
        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('PRODUCCIÓN', $salida);

        [$codigo, $salida] = $this->correrScript('recrear-base-desarrollo.sh', ['mysql', '--si']);
        $this->assertSame(2, $codigo, $salida);
        $this->assertStringContainsString('no es una base de este proyecto', $salida);

        [$codigo, $salida] = $this->correrScript('recrear-base-desarrollo.sh');
        $this->assertSame(2, $codigo, $salida);
        $this->assertStringContainsString('Falta decir qué base', $salida);
    }

    // ============================================================ doble envío del mostrador

    public function test_la_venta_del_mostrador_lleva_el_freno_de_doble_envio(): void
    {
        $ruta = app('router')->getRoutes()->getByName('pos.store');

        $this->assertContains('un.envio', $ruta->gatherMiddleware());

        $admin = $this->u('admin');
        Cajas::sesionDe($admin) ?? Cajas::abrir(Caja::firstOrFail(), $admin, 100);

        $this->actingAs($admin)->get(route('pos.index'))->assertOk()->assertSee('name="_envio"', false);
    }

    public function test_reenviar_el_mismo_formulario_no_registra_la_venta_dos_veces(): void
    {
        $cajero = $this->u('cajero1');
        $turno = Cajas::sesionDe($cajero) ?? Cajas::abrir(Caja::firstOrFail(), $cajero, 100);
        $this->assertInstanceOf(SesionCaja::class, $turno);

        $producto = Producto::activos()->where('controla_vencimiento', 0)
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))->orderBy('id')->firstOrFail();
        $producto->forceFill(['stock_actual' => 10])->save();

        $datos = [
            '_envio' => 'formulario-de-prueba-0001',
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 2]],
            'pagos' => [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id')]],
        ];

        $antes = Venta::where('sesion_caja_id', $turno->id)->count();

        $this->actingAs($cajero)->post(route('pos.store'), $datos)->assertSessionHasNoErrors();
        // Con la conexión cortada, el cajero da F5 y acepta «reenviar formulario».
        $this->actingAs($cajero)->post(route('pos.store'), $datos)
            ->assertSessionHas('aviso', fn ($m) => str_contains($m, 'ya se había enviado'));

        $this->assertSame($antes + 1, Venta::where('sesion_caja_id', $turno->id)->count(), 'la venta se registró dos veces');
        $this->assertEquals(8, (float) $producto->fresh()->stock_actual, 'el stock se descontó dos veces');
    }

    // ============================================================ recuperar la clave

    public function test_el_comando_pone_una_clave_temporal_y_obliga_a_cambiarla(): void
    {
        $admin = $this->u('admin');
        $admin->forceFill(['password_hash' => Hash::make('clave-que-se-olvido'), 'debe_cambiar_password' => false, 'intentos_fallidos' => 7])->save();
        RateLimiter::hit('login-cuenta:admin', 900);

        $this->assertSame(0, Artisan::call('usuario:clave', ['usuario' => 'admin']));
        $salida = Artisan::output();

        $this->assertMatchesRegularExpression('/Contraseña temporal: (\S{12,})/', $salida);
        preg_match('/Contraseña temporal: (\S+)/', $salida, $m);

        $admin->refresh();
        $this->assertTrue(Hash::check($m[1], $admin->password_hash), 'la clave que muestra es la que quedó');
        $this->assertFalse(Hash::check('clave-que-se-olvido', $admin->password_hash));
        $this->assertTrue($admin->debe_cambiar_password, 'tiene que cambiarla al entrar');
        $this->assertSame(0, (int) $admin->intentos_fallidos);
        $this->assertSame(0, RateLimiter::attempts('login-cuenta:admin'), 'el bloqueo por intentos se levanta');
    }

    public function test_la_clave_nunca_queda_en_la_bitacora(): void
    {
        Artisan::call('usuario:clave', ['usuario' => 'cajero1']);
        preg_match('/Contraseña temporal: (\S+)/', Artisan::output(), $m);

        $registro = Auditoria::where('accion', 'CLAVE_RESTABLECIDA_POR_CONSOLA')->latest('id')->firstOrFail();

        $this->assertSame($this->u('cajero1')->id, (int) $registro->entidad_id);
        $this->assertStringNotContainsString($m[1], json_encode($registro->detalle));
        $this->assertTrue($registro->detalle['generada']);
    }

    public function test_el_comando_dice_que_cuentas_existen_si_el_nombre_esta_mal(): void
    {
        $this->assertSame(1, Artisan::call('usuario:clave', ['usuario' => 'adminn']));

        $salida = Artisan::output();
        $this->assertStringContainsString('No existe la cuenta «adminn»', $salida);
        $this->assertStringContainsString('admin', $salida);
        $this->assertStringContainsString('cajero1', $salida);
    }

    public function test_con_pedir_la_clave_se_escribe_sin_mostrarse_y_se_valida(): void
    {
        $this->artisan('usuario:clave', ['usuario' => 'almacen', '--pedir' => true])
            ->expectsQuestion('Contraseña nueva', 'MiClaveNueva-2026')
            ->expectsQuestion('Repítela', 'MiClaveNueva-2026')
            ->doesntExpectOutputToContain('MiClaveNueva-2026')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('MiClaveNueva-2026', $this->u('almacen')->password_hash));
    }

    public function test_con_pedir_no_cambia_nada_si_no_coinciden_o_es_corta(): void
    {
        $antes = $this->u('almacen')->password_hash;

        $this->artisan('usuario:clave', ['usuario' => 'almacen', '--pedir' => true])
            ->expectsQuestion('Contraseña nueva', 'UnaClaveLarga-1')
            ->expectsQuestion('Repítela', 'OtraDistinta-2')
            ->assertFailed();

        $this->artisan('usuario:clave', ['usuario' => 'almacen', '--pedir' => true])
            ->expectsQuestion('Contraseña nueva', 'corta')
            ->expectsQuestion('Repítela', 'corta')
            ->assertFailed();

        $this->assertSame($antes, $this->u('almacen')->password_hash);
    }

    public function test_una_cuenta_desactivada_avisa_y_con_activar_vuelve_a_servir(): void
    {
        $cajero = $this->u('cajero1');
        $cajero->forceFill(['activo' => false])->save();

        $this->assertSame(1, Artisan::call('usuario:clave', ['usuario' => 'cajero1']));
        $this->assertStringContainsString('--activar', Artisan::output());
        $this->assertFalse((bool) $cajero->fresh()->activo, 'no se activa sola');

        $this->assertSame(0, Artisan::call('usuario:clave', ['usuario' => 'cajero1', '--activar' => true]));
        $this->assertTrue((bool) $cajero->fresh()->activo);
    }

    public function test_las_sesiones_abiertas_con_la_clave_vieja_dejan_de_valer(): void
    {
        $admin = $this->u('admin');
        $admin->forceFill(['password_hash' => Hash::make('clave-vieja-123'), 'debe_cambiar_password' => false])->save();

        $this->post(route('login.store'), ['usuario' => 'admin', 'password' => 'clave-vieja-123'])->assertRedirect();
        $this->get(route('inicio'))->assertOk();

        Artisan::call('usuario:clave', ['usuario' => 'admin']);

        // En producción cada petición es un proceso nuevo y lee la contraseña de
        // la base; aquí el guard recuerda al usuario de la anterior.
        app('auth')->forgetGuards();

        $this->get(route('inicio'))->assertRedirect(route('login'));
    }
}
