<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Qr\QrBaneco;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Banco Económico, listo para configurar cuando el banco entregue los datos:
 * sin dirección por omisión, sin cobrar de mentira en producción, con un
 * diagnóstico que no muestra secretos y con el mostrador avisando cuando el
 * QR es de pruebas.
 *
 * Credenciales y llave son de prueba: las reales viven solo en el .env.docker
 * del servidor.
 */
class BancoListoParaConfigurarTest extends TestCase
{
    use DatabaseTransactions;

    private const PRUEBAS = 'https://apimktdesa.baneco.com.bo/ApiGateway';

    private const PRODUCCION = 'https://api.baneco.com.bo/ApiGateway';

    private const LLAVE = 'ABCDEF0123456789ABCDEF0123456789';

    private const TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjQxMDI0NDQ4MDAsImlhdCI6MTcwMDAwMDAwMH0.firma';

    /** @param  array<string, mixed>  $cambios */
    private function configurar(string $url, array $cambios = []): void
    {
        config([
            'qr.pasarela' => 'baneco',
            'qr.pasarelas.baneco' => array_merge([
                'url_base' => $url,
                'permitir_pruebas' => false,
                'usuario' => 'usuario-prueba',
                'password' => 'clave-secreta-de-prueba',
                'llave' => self::LLAVE,
                'cuenta' => '1234567890',
                'sucursal' => null,
                'prefijo' => 'SV',
                'timeout' => 5,
            ], $cambios),
        ]);
    }

    private function enProduccion(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function turno()
    {
        return Cajas::sesionDe($this->cajero()) ?? Cajas::abrir(Caja::firstOrFail(), $this->cajero(), 100);
    }

    private function banco(string $url, int $estadoAutenticacion = 200, string $qrId = '26091401016609000085'): void
    {
        Http::fake([
            $url.'/api/authentication/authenticate' => $estadoAutenticacion === 200
                ? Http::response(['token' => self::TOKEN, 'responseCode' => 0, 'message' => ''])
                : Http::response(['responseCode' => 5, 'message' => 'Credenciales inválidas'], 200),
            $url.'/api/qrsimple/generateQR' => Http::response(['qrId' => $qrId, 'qrImage' => 'iVBORw0KGgoAAAANSUhEUg', 'responseCode' => 0, 'message' => '']),
        ]);
    }

    // ============================================================ sin dirección por omisión

    public function test_la_direccion_del_banco_no_tiene_valor_por_omision(): void
    {
        $config = (string) file_get_contents(base_path('config/qr.php'));

        $this->assertMatchesRegularExpression("/'url_base' => env\\('QR_BANECO_URL'\\),/", $config);
        $this->assertStringNotContainsString("env('QR_BANECO_URL', ", $config);
    }

    public function test_sin_direccion_el_sistema_dice_que_falta_en_vez_de_cobrar_en_pruebas(): void
    {
        $this->configurar('');
        Http::fake();

        try {
            CobrosQr::generar($this->turno(), $this->cajero(), 10);
            $this->fail('tendría que haber avisado que falta la dirección');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('url_base', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    // ============================================================ ambiente

    public function test_reconoce_si_la_direccion_es_la_de_pruebas(): void
    {
        $this->assertTrue((new QrBaneco(['url_base' => self::PRUEBAS]))->enPruebas());
        $this->assertTrue((new QrBaneco(['url_base' => 'https://banco.test/ApiGateway']))->enPruebas());
        $this->assertFalse((new QrBaneco(['url_base' => self::PRODUCCION]))->enPruebas());
        $this->assertFalse((new QrBaneco(['url_base' => '']))->enPruebas());
    }

    public function test_un_servidor_de_produccion_no_cobra_contra_el_ambiente_de_pruebas(): void
    {
        $this->configurar(self::PRUEBAS);
        $this->enProduccion();
        Http::fake();

        try {
            CobrosQr::generar($this->turno(), $this->cajero(), 10);
            $this->fail('un servidor de producción no puede cobrar de mentira');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('PRUEBAS', $e->getMessage());
        }

        // Ni siquiera se le pidió acceso al banco.
        Http::assertNothingSent();
    }

    public function test_la_certificacion_en_el_servidor_real_se_declara_a_proposito(): void
    {
        $this->configurar(self::PRUEBAS, ['permitir_pruebas' => true]);
        $this->enProduccion();
        $this->banco(self::PRUEBAS);

        $cobro = CobrosQr::generar($this->turno(), $this->cajero(), 10);

        $this->assertSame('26091401016609000085', $cobro->id_externo);
    }

    public function test_con_la_direccion_de_produccion_cobra_normal(): void
    {
        $this->configurar(self::PRODUCCION);
        $this->enProduccion();
        $this->banco(self::PRODUCCION);

        $this->assertSame('26091401016609000085', CobrosQr::generar($this->turno(), $this->cajero(), 10)->id_externo);
    }

    public function test_en_desarrollo_se_puede_probar_contra_certificacion(): void
    {
        $this->configurar(self::PRUEBAS);
        $this->banco(self::PRUEBAS);

        $this->assertSame('26091401016609000085', CobrosQr::generar($this->turno(), $this->cajero(), 10)->id_externo);
    }

    // ============================================================ el mostrador lo avisa

    public function test_el_mostrador_recibe_si_el_qr_es_de_pruebas(): void
    {
        $this->configurar(self::PRUEBAS);
        $this->banco(self::PRUEBAS);
        $this->turno();

        $this->actingAs($this->cajero())->postJson(route('qr.crear'), ['monto' => 15])
            ->assertSuccessful()->assertJsonPath('pruebas', true)->assertJsonPath('simulado', false);

        $this->configurar(self::PRODUCCION);
        $this->banco(self::PRODUCCION, qrId: '26091401016609000999');

        $this->actingAs($this->cajero())->postJson(route('qr.crear'), ['monto' => 15])
            ->assertSuccessful()->assertJsonPath('pruebas', false);
    }

    public function test_la_pantalla_trae_el_aviso_de_ambiente_de_pruebas(): void
    {
        $this->turno();

        $this->actingAs($this->cajero())->get(route('pos.index'))->assertOk()
            ->assertSee('data-qr-pruebas', false)
            ->assertSee('Ambiente de PRUEBAS del banco', false);
    }

    // ============================================================ qr:diagnostico

    public function test_con_la_pasarela_simulada_el_diagnostico_no_pide_nada(): void
    {
        config(['qr.pasarela' => 'simulado']);

        $this->assertSame(0, Artisan::call('qr:diagnostico'));
        $this->assertStringContainsString('SIMULADO', Artisan::output());
    }

    public function test_el_diagnostico_dice_que_falta_sin_mostrar_ningun_secreto(): void
    {
        $this->configurar(self::PRODUCCION, ['usuario' => '', 'cuenta' => '']);

        $this->assertSame(1, Artisan::call('qr:diagnostico'));
        $salida = Artisan::output();

        $this->assertStringContainsString('QR_BANECO_USUARIO', $salida);
        $this->assertStringContainsString('FALTA', $salida);
        $this->assertStringContainsString('Falta resolver', $salida);

        foreach (['clave-secreta-de-prueba', self::LLAVE, '1234567890'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $salida, 'el diagnóstico mostró un secreto');
        }
    }

    public function test_el_diagnostico_avisa_si_la_llave_no_mide_32_caracteres(): void
    {
        $this->configurar(self::PRODUCCION, ['llave' => 'muy-corta']);

        $this->assertSame(1, Artisan::call('qr:diagnostico'));
        $this->assertStringContainsString('tienen que ser 32', Artisan::output());
    }

    public function test_el_diagnostico_bloquea_pruebas_en_produccion_y_da_la_direccion_del_aviso(): void
    {
        $this->configurar(self::PRUEBAS);
        $this->enProduccion();

        $this->assertSame(1, Artisan::call('qr:diagnostico'));
        $salida = Artisan::output();

        $this->assertStringContainsString('PRUEBAS', $salida);
        $this->assertStringContainsString('BLOQUEADO', $salida);
        $this->assertStringContainsString('/api/qrsimple/notifyPaymentQR', $salida);
    }

    public function test_con_todo_puesto_y_la_direccion_de_produccion_queda_en_orden(): void
    {
        $this->configurar(self::PRODUCCION);
        $this->enProduccion();
        Http::fake();

        $this->assertSame(0, Artisan::call('qr:diagnostico'));
        $this->assertStringContainsString('Todo en orden', Artisan::output());

        // Sin --conectar no se le pregunta nada al banco.
        Http::assertNothingSent();
    }

    public function test_conectar_comprueba_el_usuario_y_la_contrasena_sin_generar_ningun_qr(): void
    {
        $this->configurar(self::PRODUCCION);
        $this->banco(self::PRODUCCION);

        $this->assertSame(0, Artisan::call('qr:diagnostico', ['--conectar' => true]));
        $this->assertStringContainsString('aceptó el usuario', Artisan::output());

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/authentication/authenticate'));
    }

    public function test_conectar_dice_cuando_el_banco_rechaza_las_credenciales(): void
    {
        $this->configurar(self::PRODUCCION);
        $this->banco(self::PRODUCCION, estadoAutenticacion: 401);

        $this->assertSame(1, Artisan::call('qr:diagnostico', ['--conectar' => true]));
        $salida = Artisan::output();

        $this->assertStringContainsString('Falló', $salida);
        $this->assertStringNotContainsString('clave-secreta-de-prueba', $salida);
    }

    public function test_conectar_no_llama_al_banco_si_faltan_datos(): void
    {
        $this->configurar(self::PRODUCCION, ['password' => '']);
        Http::fake();

        $this->assertSame(1, Artisan::call('qr:diagnostico', ['--conectar' => true]));
        $this->assertStringContainsString('No se intentó', Artisan::output());

        Http::assertNothingSent();
    }
}
