<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\LibroDeVentas;
use App\Support\Config;
use App\Support\Contrasena;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

/**
 * Los detalles menores de la segunda auditoría de octubre de 2026: cambios de
 * pocas líneas que se dejan hechos de una vez, cada uno con su prueba.
 */
class DetallesMenoresTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Los frenos de frecuencia viven en la caché, que no se revierte con la transacción.
        Cache::flush();
    }

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    /** La cuenta con una clave conocida: la de la base de demostración no se da por sentada. */
    private function conClave(string $usuario): Usuario
    {
        $cuenta = $this->u($usuario);
        $cuenta->forceFill(['password_hash' => Hash::make('ClaveVieja#01')])->save();

        return $cuenta->fresh();
    }

    private function raiz(string $ruta): string
    {
        return base_path('../'.$ruta);
    }

    // ============================================================ 1. contador de intentos de ingreso

    public function test_el_contador_de_intentos_aguanta_mas_de_255(): void
    {
        $this->assertSame('smallint', strtolower((string) DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', 'usuarios')
            ->where('COLUMN_NAME', 'intentos_fallidos')->value('DATA_TYPE')));

        DB::table('usuarios')->where('usuario', 'cajero1')->update(['intentos_fallidos' => 255]);

        // Antes, este intento era el 256: error 500 en vez de «usuario o contraseña incorrectos».
        $this->post('/login', ['usuario' => 'cajero1', 'password' => 'una-clave-que-no-es'])
            ->assertSessionHasErrors('usuario');

        $this->assertSame(256, (int) DB::table('usuarios')->where('usuario', 'cajero1')->value('intentos_fallidos'));
    }

    public function test_pasado_el_tope_el_contador_deja_de_contar_sin_romper_nada(): void
    {
        DB::table('usuarios')->where('usuario', 'cajero1')->update(['intentos_fallidos' => 60000]);

        $this->post('/login', ['usuario' => 'cajero1', 'password' => 'una-clave-que-no-es'])
            ->assertSessionHasErrors('usuario');

        $this->assertSame(60000, (int) DB::table('usuarios')->where('usuario', 'cajero1')->value('intentos_fallidos'));
    }

    // ============================================================ 2. tope de líneas y pagos

    private function turnoDelCajero(): void
    {
        $cajero = $this->u('cajero1');
        Cajas::sesionDe($cajero) ?? Cajas::abrir(Caja::orderBy('id')->firstOrFail(), $cajero, 100, 'Apertura de prueba');
    }

    public function test_una_venta_de_mas_de_200_lineas_se_rechaza(): void
    {
        $this->turnoDelCajero();
        $lineas = array_map(fn ($i) => ['producto_id' => $i, 'cantidad' => 1], range(1, 201));

        $this->actingAs($this->u('cajero1'))->post('/pos', ['lineas' => $lineas, 'pagos' => [['metodo_pago_id' => 1]]])
            ->assertSessionHasErrors('lineas');
        $this->assertStringContainsString('más de 200 productos', session('errors')->first('lineas'));
    }

    public function test_200_lineas_exactas_no_se_rechazan_por_el_tope(): void
    {
        $this->turnoDelCajero();
        $lineas = array_map(fn ($i) => ['producto_id' => $i, 'cantidad' => 1], range(1, 200));

        $this->actingAs($this->u('cajero1'))->post('/pos', ['lineas' => $lineas, 'pagos' => [['metodo_pago_id' => 1]]])
            ->assertSessionDoesntHaveErrors('lineas');
    }

    public function test_una_venta_con_mas_de_5_pagos_se_rechaza_y_con_5_no(): void
    {
        $this->turnoDelCajero();
        $pagos = fn (int $n) => array_fill(0, $n, ['metodo_pago_id' => 1, 'monto' => 1]);
        $linea = [['producto_id' => 1, 'cantidad' => 1]];

        $this->actingAs($this->u('cajero1'))->post('/pos', ['lineas' => $linea, 'pagos' => $pagos(6)])->assertSessionHasErrors('pagos');
        $this->assertStringContainsString('no admite más de 5 pagos', session('errors')->first('pagos'));

        $this->actingAs($this->u('cajero1'))->post('/pos', ['lineas' => $linea, 'pagos' => $pagos(5)])->assertSessionDoesntHaveErrors('pagos');
    }

    // ============================================================ 3. /up con freno

    public function test_la_ruta_de_salud_sigue_respondiendo_y_consulta_la_base(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_la_ruta_de_salud_frena_a_quien_la_martilla_sin_dejar_a_nadie_fuera_del_ingreso(): void
    {
        foreach (range(1, 60) as $_) {
            $this->get('/up')->assertOk();
        }

        $this->get('/up')->assertStatus(429);

        // El cupo es solo de `/up`: el ingreso de los cajeros sigue abierto.
        $this->get('/login')->assertOk();
        $this->post('/login', ['usuario' => 'cajero1', 'password' => 'una-clave-que-no-es'])->assertSessionHasErrors('usuario');
    }

    // ============================================================ 4. aviso del banco

    public function test_el_aviso_del_banco_aguanta_30_por_minuto(): void
    {
        foreach (range(1, 30) as $i) {
            $this->assertNotSame(429, $this->post('/qr/aviso', [])->getStatusCode(), "pedido {$i}");
        }

        $this->post('/qr/aviso', [])->assertStatus(429);
    }

    // ============================================================ 5. binlog

    public function test_mysql_guarda_el_registro_binario_7_dias(): void
    {
        foreach (['docker-compose.yml', 'docker-compose.prod.yml'] as $archivo) {
            $this->assertStringContainsString('--binlog-expire-logs-seconds=604800', file_get_contents($this->raiz($archivo)), $archivo);
        }
    }

    // ============================================================ 6. contraseñas

    public function test_la_contrasena_pide_10_caracteres(): void
    {
        $perfil = fn (string $clave) => $this->actingAs($this->conClave('cajero1'))->put(route('perfil.password'), [
            'password_actual' => 'ClaveVieja#01', 'password' => $clave, 'password_confirmation' => $clave,
        ]);

        $perfil('Zorro#2026')->assertSessionHasNoErrors();   // 10
    }

    public function test_una_contrasena_de_9_caracteres_ya_no_alcanza(): void
    {
        $clave = 'Zorro#202';  // 9

        $this->actingAs($this->conClave('cajero1'))->put(route('perfil.password'), [
            'password_actual' => 'ClaveVieja#01', 'password' => $clave, 'password_confirmation' => $clave,
        ])->assertSessionHasErrors('password');
    }

    public function test_se_rechaza_lo_que_cualquiera_probaria_primero(): void
    {
        $this->assertNotNull(Contrasena::problema('cajero1-2026', 'cajero1'), 'el usuario adentro');
        $this->assertNotNull(Contrasena::problema('CAJERO1xyz!!', 'cajero1'), 'sin importar mayúsculas');
        $this->assertNotNull(Contrasena::problema('Password2026!', null), 'password');
        $this->assertNotNull(Contrasena::problema('MiContraseña99', null), 'contraseña, con o sin eñe');
        $this->assertNotNull(Contrasena::problema('Contrasena#77', null));
        $this->assertNotNull(Contrasena::problema('xx12345678xx', null), '12345678 adentro');
        $this->assertNotNull(Contrasena::problema('aaaaaaaaaaaa', null), 'un solo carácter repetido');
        $this->assertNotNull(Contrasena::problema('1212121212', null), 'dos caracteres alternados');

        $this->assertNull(Contrasena::problema('Zorro#Lento82', 'cajero1'));
        $this->assertNull(Contrasena::problema('tres caballos rojos', 'cajero1'));
    }

    public function test_se_rechaza_el_nombre_del_negocio(): void
    {
        DB::table('configuracion')->updateOrInsert(['clave' => 'negocio_nombre'], ['valor' => 'Minimarket La Esperanza']);
        Config::olvidar();

        $this->assertStringContainsString('nombre del negocio', (string) Contrasena::problema('minimarketlaesperanza1', null));
        $this->assertStringContainsString('nombre del negocio', (string) Contrasena::problema('MINIMARKET la esperanza 77', null));
        $this->assertNull(Contrasena::problema('Zorro#Lento82', null));
    }

    public function test_el_alta_de_una_cuenta_aplica_las_mismas_reglas(): void
    {
        $this->actingAs($this->u('admin'))->post(route('usuarios.store'), [
            'empleado_id' => 1, 'rol_id' => 1, 'usuario' => 'nuevo.user',
            'password' => 'nuevo.user-2026', 'password_confirmation' => 'nuevo.user-2026', 'activo' => 1,
        ])->assertSessionHasErrors('password');
        $this->assertStringContainsString('nombre de usuario', session('errors')->first('password'));
    }

    // ============================================================ 7. zipear

    public function test_zipear_no_lleva_las_credenciales_ni_los_respaldos(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Sin la extensión zip.');
        }

        $dir = sys_get_temp_dir().'/zipear-'.bin2hex(random_bytes(4));
        $zip = $dir.'.zip';
        foreach (['app', 'storage/app/respaldos', 'storage/app/public', 'storage/logs', 'vendor/x', '.git', 'node_modules/y'] as $sub) {
            mkdir("{$dir}/{$sub}", 0777, true);
        }
        $archivos = [
            'app/Modelo.php' => 'ok', '.env' => 'SECRETO', '.env.docker' => 'SECRETO', '.env.example' => 'plantilla',
            'storage/app/respaldos/volcado.sql.gz' => 'x', 'storage/app/respaldos/PRIMER-ACCESO.txt' => 'clave',
            'storage/app/public/foto.jpg' => 'x', 'storage/logs/laravel.log' => 'x', 'vendor/x/a.php' => 'x',
            '.git/config' => 'x', 'node_modules/y/i.js' => 'x', 'PRIMER-ACCESO.txt' => 'clave', 'README.md' => 'ok',
        ];
        foreach ($archivos as $ruta => $contenido) {
            file_put_contents("{$dir}/{$ruta}", $contenido);
        }

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->raiz('scripts/zipear.php')).' '.escapeshellarg($dir).' '.escapeshellarg($zip).' 2>&1', $salida, $codigo);
        $this->assertSame(0, $codigo, implode("\n", $salida));

        $abierto = new ZipArchive;
        $abierto->open($zip);
        $dentro = [];
        for ($i = 0; $i < $abierto->numFiles; $i++) {
            $dentro[] = $abierto->getNameIndex($i);
        }
        $abierto->close();

        foreach (['app/Modelo.php', '.env.example', 'README.md', 'storage/app/public/foto.jpg'] as $debe) {
            $this->assertContains($debe, $dentro, "debería llevar {$debe}");
        }
        foreach (['.env', '.env.docker', 'PRIMER-ACCESO.txt', 'storage/app/respaldos/volcado.sql.gz', 'storage/app/respaldos/PRIMER-ACCESO.txt', 'storage/logs/laravel.log', 'vendor/x/a.php', '.git/config', 'node_modules/y/i.js'] as $noDebe) {
            $this->assertNotContains($noDebe, $dentro, "NO debería llevar {$noDebe}");
        }

        // limpieza
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
        @unlink($zip);
    }

    // ============================================================ 8. PRIMER-ACCESO.txt

    private function ponerPrimerAcceso(): string
    {
        $ruta = storage_path('app/respaldos/PRIMER-ACCESO.txt');
        @mkdir(dirname($ruta), 0775, true);
        file_put_contents($ruta, 'usuario: admin');

        return $ruta;
    }

    public function test_el_archivo_de_primer_acceso_se_borra_cuando_admin_pone_su_clave(): void
    {
        $ruta = $this->ponerPrimerAcceso();

        try {
            $this->actingAs($this->conClave('admin'))->put(route('perfil.password'), [
                'password_actual' => 'ClaveVieja#01', 'password' => 'Zorro#Lento82', 'password_confirmation' => 'Zorro#Lento82',
            ])->assertSessionHasNoErrors();

            $this->assertFileDoesNotExist($ruta);
        } finally {
            @unlink($ruta);
        }
    }

    public function test_si_cambia_la_clave_otra_persona_el_archivo_se_queda(): void
    {
        $ruta = $this->ponerPrimerAcceso();

        try {
            $this->actingAs($this->conClave('cajero1'))->put(route('perfil.password'), [
                'password_actual' => 'ClaveVieja#01', 'password' => 'Zorro#Lento82', 'password_confirmation' => 'Zorro#Lento82',
            ])->assertSessionHasNoErrors();

            $this->assertFileExists($ruta);
        } finally {
            @unlink($ruta);
        }
    }

    public function test_restablecer_la_clave_de_admin_por_consola_tambien_lo_borra(): void
    {
        $ruta = $this->ponerPrimerAcceso();

        try {
            $this->artisan('usuario:clave', ['usuario' => 'admin'])->assertExitCode(0);

            $this->assertFileDoesNotExist($ruta);
        } finally {
            @unlink($ruta);
        }
    }

    // ============================================================ 11. moneda

    public function test_la_moneda_tiene_el_mismo_tipo_en_los_cobros_qr_y_en_los_comprobantes(): void
    {
        $tipo = fn (string $tabla) => strtolower((string) DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $tabla)
            ->where('COLUMN_NAME', 'moneda')->value('COLUMN_TYPE'));

        $this->assertSame('varchar(3)', $tipo('cobros_qr'));
        $this->assertSame('varchar(3)', $tipo('comprobantes'));
    }

    // ============================================================ 13. libro de ventas

    public function test_el_libro_de_ventas_no_arma_una_lista_con_todas_las_facturas_del_mes(): void
    {
        DB::enableQueryLog();
        LibroDeVentas::mes(2026, 10);
        $consultas = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower($q));
        DB::disableQueryLog();

        $deLineas = $consultas->first(fn ($q) => str_contains($q, 'from `venta_detalle`'));

        $this->assertNotNull($deLineas);
        $this->assertStringContainsString('in (select', $deLineas, 'una subconsulta, no un IN con ids escritos uno por uno');
    }

    // ============================================================ 14. cambio de modo de precios

    public function test_la_pantalla_de_configuracion_avisa_que_convertir_los_precios_detiene_el_mostrador(): void
    {
        $this->actingAs($this->u('admin'))->get(route('configuracion.edit'))->assertOk()
            ->assertSee('data-aviso-bloqueo', false)
            ->assertSee('fuera del horario de atención');
    }

    // ============================================================ el formulario de cierre no miente

    /** Una caja abierta por el administrador, que es quien ve el formulario de cierre. */
    private function turnoDelAdministrador(): SesionCaja
    {
        $admin = $this->u('admin');

        return Cajas::sesionDe($admin) ?? Cajas::abrir(Caja::orderBy('id')->firstOrFail(), $admin, 100, 'Apertura de prueba');
    }

    /**
     * El campo «Queda en el cajón» decía «Opcional» y el servidor lo exigía: se escribía
     * el cierre, se rechazaba, y no había forma de saber por qué.
     */
    public function test_el_formulario_de_cierre_dice_que_lo_que_queda_en_el_cajon_es_obligatorio(): void
    {
        $sesion = $this->turnoDelAdministrador();

        $html = $this->actingAs($this->u('admin'))->get(route('caja.show', $sesion))->assertOk()->getContent();

        preg_match('/<input[^>]*name="fondo_dejado"[^>]*>/s', $html, $campo);
        $this->assertNotEmpty($campo, 'el campo está en el formulario');
        $this->assertStringContainsString('required', $campo[0]);
        $this->assertStringNotContainsString('Opcional', $campo[0], 'antes decía «Opcional» y el servidor lo exigía');
        $this->assertStringContainsString('escribe 0', $html, 'y se dice qué poner si no queda nada');
    }

    public function test_cerrar_sin_decir_cuanto_queda_se_rechaza_con_un_mensaje_que_lo_explica(): void
    {
        $sesion = $this->turnoDelAdministrador();

        $this->actingAs($this->u('admin'))->post(route('caja.cerrar', $sesion), [
            'monto_declarado' => (float) $sesion->fresh()->efectivoEsperado(), 'huella' => 'x',
        ])->assertSessionHasErrors('fondo_dejado');

        $this->assertStringContainsString('0 si no queda nada', session('errors')->first('fondo_dejado'));
        $this->assertSame('ABIERTA', $sesion->fresh()->estado, 'y no se cerró');
    }
}
