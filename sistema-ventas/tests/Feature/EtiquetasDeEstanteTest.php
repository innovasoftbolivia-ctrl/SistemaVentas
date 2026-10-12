<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Usuario;
use App\Support\CodigoDeBarras;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Quinto bloque de la auditoría de octubre de 2026: etiquetas de estante.
 *
 * Nombre, precio vigente y código de barras de cada producto, en una hoja lista
 * para imprimir. El código de barras se dibuja con un codificador propio, así
 * que se prueba contra la regla de la norma: dígito de control, 95 módulos y la
 * lectura de vuelta del dibujo.
 */
class EtiquetasDeEstanteTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    // ============================================================ el código de barras

    public function test_el_digito_de_control_sale_de_la_norma(): void
    {
        // Códigos publicados de verdad: un libro, una bebida, un UPC, un EAN-8.
        $this->assertSame(1, CodigoDeBarras::digitoDeControl('400638133393'));
        $this->assertSame(7, CodigoDeBarras::digitoDeControl('590123412345'));
        $this->assertSame(2, CodigoDeBarras::digitoDeControl('03600029145'));
        $this->assertSame(7, CodigoDeBarras::digitoDeControl('7351353'));
        $this->assertSame(4, CodigoDeBarras::digitoDeControl('978020137962'));
    }

    public function test_solo_se_dibuja_un_ean_con_su_control_correcto(): void
    {
        foreach (['4006381333931', '5901234123457', '73513537', '036000291452', '9780201379624'] as $valido) {
            $this->assertTrue(CodigoDeBarras::esValido($valido), $valido);
        }

        foreach (['4006381333932', '12345', '', 'ABC12345', '40063813339311', '7351353', null] as $malo) {
            $this->assertFalse(CodigoDeBarras::esValido($malo), (string) $malo);
            $this->assertNull(CodigoDeBarras::modulos($malo));
            $this->assertNull(CodigoDeBarras::svg($malo));
        }
    }

    /**
     * Cada dígito en cada alfabeto: con un solo código de ejemplo, un dígito mal
     * escrito en la tabla G podría no tocarse nunca. Se recorren los diez primeros
     * dígitos (que fijan la paridad) con cuerpos que rotan los diez dígitos.
     */
    public function test_todos_los_digitos_en_todos_los_alfabetos_se_leen_de_vuelta(): void
    {
        $l = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
        $g = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
        $r = array_map(fn ($m) => strtr($m, '01', '10'), $l);
        $paridad = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];
        $vistoEnG = [];

        for ($primero = 0; $primero <= 9; $primero++) {
            for ($giro = 0; $giro <= 9; $giro++) {
                $cuerpo = $primero.substr(str_repeat('0123456789', 2), $giro, 11);
                $codigo = $cuerpo.CodigoDeBarras::digitoDeControl($cuerpo);
                $m = CodigoDeBarras::modulos($codigo);
                $this->assertSame(95, strlen($m), $codigo);

                $leidos = (string) $primero;
                foreach (str_split(substr($m, 3, 42), 7) as $i => $bloque) {
                    $tabla = $paridad[$primero][$i] === 'L' ? $l : $g;
                    $digito = array_search($bloque, $tabla, true);
                    $this->assertNotFalse($digito, "{$codigo}: bloque izquierdo {$i}");
                    $leidos .= $digito;

                    if ($paridad[$primero][$i] === 'G') {
                        $vistoEnG[$digito] = true;
                    }
                }
                foreach (str_split(substr($m, 50, 42), 7) as $i => $bloque) {
                    $digito = array_search($bloque, $r, true);
                    $this->assertNotFalse($digito, "{$codigo}: bloque derecho {$i}");
                    $leidos .= $digito;
                }

                $this->assertSame($codigo, $leidos);
            }
        }

        $this->assertCount(10, $vistoEnG, 'los diez dígitos pasaron por el alfabeto G');
    }

    public function test_un_ean13_son_95_modulos_con_sus_guardas(): void
    {
        $modulos = CodigoDeBarras::modulos('4006381333931');

        $this->assertSame(95, strlen($modulos));
        $this->assertSame('101', substr($modulos, 0, 3), 'guarda inicial');
        $this->assertSame('01010', substr($modulos, 45, 5), 'guarda central');
        $this->assertSame('101', substr($modulos, -3), 'guarda final');
        $this->assertMatchesRegularExpression('/^[01]+$/', $modulos);
    }

    public function test_un_ean8_son_67_modulos_y_un_upc_se_dibuja_como_ean13(): void
    {
        $ean8 = CodigoDeBarras::modulos('73513537');
        $this->assertSame(67, strlen($ean8));
        $this->assertSame('01010', substr($ean8, 31, 5));

        $this->assertSame(CodigoDeBarras::modulos('0036000291452'), CodigoDeBarras::modulos('036000291452'));
    }

    /**
     * Se lee el dibujo de vuelta, con los alfabetos de la norma escritos a mano en
     * la prueba y no importados del código: si la tabla del codificador tuviera un
     * dígito mal, aquí no daría el mismo número.
     */
    public function test_el_dibujo_se_lee_de_vuelta_como_el_mismo_numero(): void
    {
        $l = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
        $g = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
        $r = array_map(fn ($m) => strtr($m, '01', '10'), $l);
        $paridad = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

        foreach (['4006381333931', '5901234123457', '9780201379624', '7750001234565', '0036000291452'] as $codigo) {
            if (! CodigoDeBarras::esValido($codigo)) {
                continue;
            }

            $m = CodigoDeBarras::modulos($codigo);
            $izq = str_split(substr($m, 3, 42), 7);
            $der = str_split(substr($m, 50, 42), 7);

            $primer = null;
            $leidos = '';

            foreach (self::PARIDADES as $d => $p) {
                $coincide = true;
                foreach ($izq as $i => $bloque) {
                    $tabla = $p[$i] === 'L' ? $l : $g;
                    if (! in_array($bloque, $tabla, true)) {
                        $coincide = false;
                        break;
                    }
                }
                if ($coincide) {
                    $primer = $d;
                    $leidos .= $d;
                    foreach ($izq as $i => $bloque) {
                        $leidos .= array_search($bloque, $p[$i] === 'L' ? $l : $g, true);
                    }
                    break;
                }
            }

            foreach ($der as $bloque) {
                $leidos .= array_search($bloque, $r, true);
            }

            $this->assertNotNull($primer, "no se reconoció la paridad de {$codigo}");
            $this->assertSame($codigo, $leidos);
        }

        $this->assertSame($paridad, array_values(self::PARIDADES));
    }

    private const PARIDADES = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    public function test_el_svg_lleva_una_barra_por_cada_racha_de_modulos_y_el_numero_en_su_etiqueta(): void
    {
        $svg = CodigoDeBarras::svg('4006381333931');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('aria-label="Código de barras 4006381333931"', $svg);

        preg_match_all('/1+/', CodigoDeBarras::modulos('4006381333931'), $rachas);
        $this->assertSame(count($rachas[0]), substr_count($svg, '<rect x='));
        $this->assertSame(95 + 18, (int) preg_replace('/.*viewBox="0 0 (\d+) .*/s', '$1', $svg), 'zona en blanco a cada lado');
    }

    // ============================================================ la pantalla

    public function test_solo_quien_gestiona_productos_llega_a_las_etiquetas(): void
    {
        foreach (['etiquetas.index', 'etiquetas.hoja'] as $ruta) {
            $this->actingAs($this->u('cajero1'))->get(route($ruta, ['ids' => [1]]))->assertForbidden();
        }

        $this->actingAs($this->u('admin'))->get(route('etiquetas.index'))->assertOk()->assertSee('Imprimir etiquetas');
    }

    public function test_esta_en_el_menu_del_catalogo_y_en_el_listado_de_productos(): void
    {
        $this->actingAs($this->u('admin'))->get(route('productos.index'))->assertOk()
            ->assertSee('/productos/etiquetas', false)
            ->assertSee('Etiquetas de estante');
    }

    private function conEan(string $codigo, string $ean, array $extra = []): Producto
    {
        $p = Producto::where('codigo', $codigo)->firstOrFail();
        $p->forceFill(['codigo_barras' => $ean, ...$extra])->save();

        return $p->fresh();
    }

    public function test_el_listado_filtra_por_categoria_texto_y_oferta(): void
    {
        $a = $this->conEan('P-0004', '4006381333931');
        $b = Producto::where('codigo', 'P-0009')->firstOrFail();
        $b->forceFill([
            'precio_oferta' => 1.00, 'precio_venta' => 2.50,
            'oferta_desde' => now()->subDay()->toDateString(), 'oferta_hasta' => now()->addDay()->toDateString(),
        ])->save();

        $admin = $this->u('admin');

        $this->actingAs($admin)->get(route('etiquetas.index', ['categoria' => $a->categoria_id]))
            ->assertOk()->assertSee($a->nombre);

        $this->actingAs($admin)->get(route('etiquetas.index', ['buscar' => $a->codigo]))
            ->assertOk()->assertSee($a->nombre)->assertDontSee($b->nombre);

        $solo = $this->actingAs($admin)->get(route('etiquetas.index', ['oferta' => 1]))->assertOk();
        $solo->assertSee($b->nombre)->assertSee('oferta hasta');
        $this->assertStringNotContainsString('name="ids[]" value="'.$a->id.'"', $solo->getContent());
        $this->assertStringContainsString('name="ids[]" value="'.$b->id.'"', $solo->getContent());
    }

    public function test_el_filtro_de_oferta_no_incluye_las_terminadas_ni_las_que_no_empezaron(): void
    {
        $admin = $this->u('admin');
        $vigente = Producto::where('codigo', 'P-0004')->firstOrFail();
        $terminada = Producto::where('codigo', 'P-0009')->firstOrFail();
        $futura = Producto::where('codigo', 'P-0010')->firstOrFail();

        $vigente->forceFill(['precio_oferta' => 0.5, 'oferta_desde' => now()->subDay()->toDateString(), 'oferta_hasta' => now()->addDay()->toDateString()])->save();
        $terminada->forceFill(['precio_oferta' => 0.5, 'oferta_desde' => now()->subDays(9)->toDateString(), 'oferta_hasta' => now()->subDay()->toDateString()])->save();
        $futura->forceFill(['precio_oferta' => 0.5, 'oferta_desde' => now()->addDays(3)->toDateString(), 'oferta_hasta' => now()->addDays(6)->toDateString()])->save();

        $html = $this->actingAs($admin)->get(route('etiquetas.index', ['oferta' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('name="ids[]" value="'.$vigente->id.'"', $html);
        $this->assertStringNotContainsString('name="ids[]" value="'.$terminada->id.'"', $html);
        $this->assertStringNotContainsString('name="ids[]" value="'.$futura->id.'"', $html);
    }

    public function test_el_filtro_de_precio_cambiado_mira_la_bitacora(): void
    {
        $admin = $this->u('admin');
        $cambiado = Producto::where('codigo', 'P-0004')->firstOrFail();
        $quieto = Producto::where('codigo', 'P-0009')->firstOrFail();

        DB::table('auditoria')->insert([
            ['usuario_id' => $admin->id, 'accion' => 'CAMBIO_PRECIO', 'entidad' => 'productos', 'entidad_id' => $cambiado->id,
                'detalle' => json_encode(['anterior' => 1, 'nuevo' => 2]), 'fecha' => now()],
            // Un cambio viejo no cuenta en «últimos 7 días».
            ['usuario_id' => $admin->id, 'accion' => 'CAMBIO_PRECIO', 'entidad' => 'productos', 'entidad_id' => $quieto->id,
                'detalle' => json_encode(['anterior' => 1, 'nuevo' => 2]), 'fecha' => now()->subDays(20)],
        ]);

        $semana = $this->actingAs($admin)->get(route('etiquetas.index', ['cambio' => 7]))->assertOk();
        $this->assertStringContainsString('name="ids[]" value="'.$cambiado->id.'"', $semana->getContent());
        $this->assertStringNotContainsString('name="ids[]" value="'.$quieto->id.'"', $semana->getContent());

        $mes = $this->actingAs($admin)->get(route('etiquetas.index', ['cambio' => 30]))->assertOk();
        $this->assertStringContainsString('name="ids[]" value="'.$quieto->id.'"', $mes->getContent());

        // «Hoy» es solo hoy: un cambio de hace tres días entra en 7 pero no en 1.
        DB::table('auditoria')->insert([
            'usuario_id' => $admin->id, 'accion' => 'CAMBIO_PRECIO', 'entidad' => 'productos', 'entidad_id' => $quieto->id,
            'detalle' => json_encode(['anterior' => 1, 'nuevo' => 2]), 'fecha' => now()->subDays(3),
        ]);
        $hoy = $this->actingAs($admin)->get(route('etiquetas.index', ['cambio' => 1]))->assertOk();
        $this->assertStringContainsString('name="ids[]" value="'.$cambiado->id.'"', $hoy->getContent());
        $this->assertStringNotContainsString('name="ids[]" value="'.$quieto->id.'"', $hoy->getContent());
        $siete = $this->actingAs($admin)->get(route('etiquetas.index', ['cambio' => 7]))->assertOk();
        $this->assertStringContainsString('name="ids[]" value="'.$quieto->id.'"', $siete->getContent());

        // Un plazo que no está en la lista se ignora en vez de filtrar por cualquier cosa.
        $raro = $this->actingAs($admin)->get(route('etiquetas.index', ['cambio' => 5]))->assertOk();
        $this->assertStringContainsString('name="ids[]" value="'.$quieto->id.'"', $raro->getContent());
    }

    public function test_un_ean_que_no_cuadra_avisa_que_saldra_solo_el_numero(): void
    {
        $this->conEan('P-0004', '7790001000010'); // dígito de control mal puesto a propósito

        $this->actingAs($this->u('admin'))->get(route('etiquetas.index', ['buscar' => 'P-0004']))->assertOk()
            ->assertSee('data-sin-barras', false)
            ->assertSee('no es un EAN válido');
    }

    // ============================================================ la hoja

    public function test_la_hoja_trae_nombre_precio_barras_y_codigo_de_cada_producto(): void
    {
        $p = $this->conEan('P-0004', '4006381333931', ['precio_venta' => 4.00, 'afecto_impuesto' => 0]);

        $html = $this->actingAs($this->u('admin'))->get(route('etiquetas.hoja', ['ids' => [$p->id]]))
            ->assertOk()->assertSee($p->nombre)->assertSee('4006381333931')->assertSee($p->codigo)
            ->assertSee('data-barras', false)->assertSee('window.print()', false)->getContent();

        $this->assertStringContainsString('data-precio>4.00<', $html);
        $this->assertStringNotContainsString('data-precio-lista', $html);
        $this->assertStringNotContainsString('data-oferta>', $html);
        $this->assertSame(1, substr_count($html, 'data-etiqueta>'));
    }

    public function test_con_una_oferta_andando_la_etiqueta_lleva_el_precio_de_hoy_y_el_de_siempre_tachado(): void
    {
        $p = $this->conEan('P-0004', '4006381333931', [
            'precio_venta' => 10.00, 'afecto_impuesto' => 0, 'precio_compra' => 4.00,
            'precio_oferta' => 8.00,
            'oferta_desde' => now()->subDay()->toDateString(), 'oferta_hasta' => now()->addDays(2)->toDateString(),
        ]);

        $html = $this->actingAs($this->u('admin'))->get(route('etiquetas.hoja', ['ids' => [$p->id]]))->assertOk()->getContent();

        $this->assertStringContainsString('data-precio>8.00<', $html);
        $this->assertStringContainsString('data-precio-lista>10.00<', $html);
        $this->assertStringContainsString('Oferta hasta el '.now()->addDays(2)->format('d/m/Y'), $html);
    }

    public function test_una_oferta_terminada_vuelve_al_precio_de_lista_en_la_etiqueta(): void
    {
        $p = $this->conEan('P-0004', '4006381333931', [
            'precio_venta' => 10.00, 'afecto_impuesto' => 0, 'precio_compra' => 4.00,
            'precio_oferta' => 8.00,
            'oferta_desde' => now()->subDays(5)->toDateString(), 'oferta_hasta' => now()->subDay()->toDateString(),
        ]);

        $html = $this->actingAs($this->u('admin'))->get(route('etiquetas.hoja', ['ids' => [$p->id]]))->assertOk()->getContent();

        $this->assertStringContainsString('data-precio>10.00<', $html);
        $this->assertStringNotContainsString('data-precio-lista', $html);
    }

    public function test_el_precio_de_la_etiqueta_incluye_el_impuesto_cuando_se_suma_encima(): void
    {
        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => '0.1300']);
        DB::table('configuracion')->updateOrInsert(['clave' => 'precios_incluyen_impuesto'], ['valor' => '0']);
        Config::olvidar();

        $p = $this->conEan('P-0004', '4006381333931', ['precio_venta' => 10.00, 'afecto_impuesto' => 1]);

        $this->actingAs($this->u('admin'))->get(route('etiquetas.hoja', ['ids' => [$p->id]]))
            ->assertOk()->assertSee('data-precio>11.30<', false);
    }

    public function test_las_copias_multiplican_cada_etiqueta(): void
    {
        $a = $this->conEan('P-0004', '4006381333931');
        $b = Producto::where('codigo', 'P-0009')->firstOrFail();

        $html = $this->actingAs($this->u('admin'))
            ->get(route('etiquetas.hoja', ['ids' => [$a->id, $b->id], 'copias' => 3]))->assertOk()->getContent();

        $this->assertSame(6, substr_count($html, 'data-etiqueta>'));
        $this->assertStringContainsString('6 etiqueta(s) de 2 producto(s)', $html);
    }

    public function test_un_codigo_que_no_es_ean_sale_sin_barras_pero_con_su_numero(): void
    {
        $p = $this->conEan('P-0004', '12345');

        $html = $this->actingAs($this->u('admin'))->get(route('etiquetas.hoja', ['ids' => [$p->id]]))
            ->assertOk()->assertSee('12345')->getContent();

        $this->assertStringNotContainsString('data-barras', $html);
    }

    public function test_un_producto_sin_codigo_de_barras_sale_con_su_codigo_interno(): void
    {
        $p = Producto::where('codigo', 'P-0009')->firstOrFail();
        $p->forceFill(['codigo_barras' => null])->save();

        $this->actingAs($this->u('admin'))->get(route('etiquetas.hoja', ['ids' => [$p->id]]))
            ->assertOk()->assertSee($p->codigo)->assertDontSee('data-barras', false);
    }

    public function test_sin_productos_o_con_demasiadas_copias_no_hay_hoja(): void
    {
        $admin = $this->u('admin');
        $id = Producto::where('codigo', 'P-0004')->value('id');

        $this->actingAs($admin)->get(route('etiquetas.hoja'))->assertSessionHasErrors('ids');
        $this->actingAs($admin)->get(route('etiquetas.hoja', ['ids' => [$id], 'copias' => 0]))->assertSessionHasErrors('copias');
        $this->actingAs($admin)->get(route('etiquetas.hoja', ['ids' => [$id], 'copias' => 21]))->assertSessionHasErrors('copias');
    }

    public function test_un_producto_descatalogado_no_sale_en_la_hoja(): void
    {
        $p = Producto::where('codigo', 'P-0004')->firstOrFail();
        $p->forceFill(['activo' => 0])->save();

        $this->actingAs($this->u('admin'))->get(route('etiquetas.hoja', ['ids' => [$p->id]]))
            ->assertOk()->assertDontSee($p->nombre);
    }

    public function test_la_categoria_elegida_es_la_que_llega_a_la_hoja(): void
    {
        $categoria = Categoria::firstOrFail();
        $ids = Producto::activos()->where('categoria_id', $categoria->id)->pluck('id')->all();
        $this->assertNotEmpty($ids);

        $respuesta = $this->actingAs($this->u('admin'))->get(route('etiquetas.index', ['categoria' => $categoria->id]))->assertOk();

        foreach ($ids as $id) {
            $this->assertStringContainsString('name="ids[]" value="'.$id.'"', $respuesta->getContent());
        }
    }
}
