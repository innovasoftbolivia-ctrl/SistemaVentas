<?php

namespace Tests\Feature;

use App\Http\Controllers\InformeController;
use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Inventario;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Quinto bloque de la auditoría de octubre de 2026, los informes para decidir:
 * mermas, productos sin movimiento y horas de más venta.
 *
 * Cada uno es un documento del que salen la pantalla, el Excel y el PDF; aquí se
 * prueban con escenarios de cifras conocidas.
 */
class InformesDeGestionTest extends TestCase
{
    use DatabaseTransactions;

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function p(string $codigo, ?float $costo = null): Producto
    {
        $producto = Producto::where('codigo', $codigo)->firstOrFail();

        if ($costo !== null) {
            $producto->forceFill(['precio_compra' => $costo])->save();
        }

        return $producto->fresh();
    }

    private function turno(): SesionCaja
    {
        $cajero = $this->u('cajero1');

        return Cajas::sesionDe($cajero) ?? Cajas::abrir(Caja::firstOrFail(), $cajero, 100);
    }

    /** Una venta de una unidad, con stock que entra por el kardex y la fecha que se pida. */
    private function vender(Producto $producto, ?Carbon $fecha = null): Venta
    {
        Inventario::ingreso($producto, 5, usuarioId: $this->u('admin')->id);
        $turno = $this->turno();

        $venta = Ventas::registrar(
            sesion: $turno,
            usuario: $turno->usuarioApertura,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => (float) $producto->precio_venta]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );

        if ($fecha) {
            DB::table('ventas')->where('id', $venta->id)->update(['fecha' => $fecha]);
        }

        return $venta->fresh();
    }

    // ============================================================ permisos y menú

    public function test_los_informes_son_para_quien_ve_reportes(): void
    {
        $rutas = [
            'informes.mermas', 'informes.mermas.excel', 'informes.mermas.pdf',
            'informes.sin-movimiento', 'informes.sin-movimiento.excel', 'informes.sin-movimiento.pdf',
            'informes.horas', 'informes.horas.excel', 'informes.horas.pdf',
        ];

        foreach ($rutas as $ruta) {
            $this->actingAs($this->u('cajero1'))->get(route($ruta))->assertForbidden();
            $this->actingAs($this->u('admin'))->get(route($ruta))->assertOk();
        }
    }

    public function test_el_menu_de_reportes_los_ofrece(): void
    {
        $this->actingAs($this->u('admin'))->get(route('inicio'))->assertOk()
            ->assertSee('/reportes/mermas', false)
            ->assertSee('/reportes/sin-movimiento', false)
            ->assertSee('/reportes/horas', false)
            ->assertSee('Productos sin movimiento');
    }

    // ============================================================ mermas

    public function test_cada_motivo_de_merma_se_lee_por_su_prefijo(): void
    {
        $this->assertSame('Vencimiento', InformeController::claseDeMerma('Baja por vencimiento, lote L06452, venció el 22/07/2026'));
        $this->assertSame('Conteo de inventario', InformeController::claseDeMerma('Toma de inventario #12'));
        $this->assertSame('Ajuste manual', InformeController::claseDeMerma('Se rompió en el estante'));
        $this->assertSame('Ajuste manual', InformeController::claseDeMerma(null));
        // Solo al principio: «vencimiento» a media frase no es una baja por vencimiento.
        $this->assertSame('Ajuste manual', InformeController::claseDeMerma('Revisar el vencimiento'));
    }

    /** @return array{a: Producto, b: Producto, c: Producto} */
    private function escenarioDeMermas(): array
    {
        $this->actingAs($this->u('admin'));

        $a = $this->p('P-0001', 4.00);
        $b = $this->p('P-0002', 10.00);
        $c = $this->p('P-0003', 2.50);

        foreach ([$a, $b, $c] as $producto) {
            Inventario::ingreso($producto, 20, usuarioId: $this->u('admin')->id);
        }

        Inventario::corregir($a->fresh(), -3, 'Se rompió en el estante');                   // 3 × 4,00  = 12,00 (ajuste manual)
        Inventario::corregir($b->fresh(), -2, 'Baja por vencimiento, lote L1, venció el 01/09/2026');   // 2 × 10,00 = 20,00 (vencimiento)
        Inventario::corregir($c->fresh(), -4, 'Toma de inventario #9');                      // 4 × 2,50  = 10,00 (conteo)
        Inventario::corregir($a->fresh(), 5, 'Sobrante al contar');                          // 5 × 4,00  = 20,00 (sobrante)

        return compact('a', 'b', 'c');
    }

    public function test_las_mermas_suman_lo_perdido_y_separan_los_sobrantes(): void
    {
        $this->escenarioDeMermas();

        $this->actingAs($this->u('admin'))->get(route('informes.mermas'))->assertOk()
            ->assertSee('Perdiste')
            ->assertSee('Bs 42.00')                  // 12 + 20 + 10
            ->assertSee('Bs 20.00')                  // el sobrante, y también lo perdido por vencimiento
            ->assertSee('Resultado neto')
            ->assertSee('Bs -22.00');                // 20 de sobrante menos 42 perdidos
    }

    public function test_las_mermas_se_reparten_por_motivo_con_su_porcentaje(): void
    {
        $this->escenarioDeMermas();

        $html = $this->actingAs($this->u('admin'))->get(route('informes.mermas'))->assertOk()->getContent();
        $texto = preg_replace('/\s+/', ' ', strip_tags($html));

        // Ordenadas por valor: vencimiento (20 = 47,6 %), ajuste manual (12 = 28,6 %), conteo (10 = 23,8 %).
        $this->assertMatchesRegularExpression('/Vencimiento 1 2 Bs 20\.00 47\.6 %/', $texto);
        $this->assertMatchesRegularExpression('/Ajuste manual 1 3 Bs 12\.00 28\.6 %/', $texto);
        $this->assertMatchesRegularExpression('/Conteo de inventario 1 4 Bs 10\.00 23\.8 %/', $texto);
        $this->assertLessThan(strpos($texto, 'Ajuste manual 1'), strpos($texto, 'Vencimiento 1'), 'la mayor pérdida va primero');
    }

    public function test_las_mermas_se_reparten_por_categoria_y_por_mes(): void
    {
        $e = $this->escenarioDeMermas();

        $html = $this->actingAs($this->u('admin'))->get(route('informes.mermas'))->assertOk()->getContent();
        $texto = preg_replace('/\s+/', ' ', strip_tags($html));

        $this->assertStringContainsString($e['b']->categoria->nombre, $texto);
        $this->assertMatchesRegularExpression('/'.preg_quote(now()->format('Y-m'), '/').' 3 9 Bs 42\.00 100\.0 %/', $texto);
    }

    public function test_las_mayores_perdidas_dicen_quien_y_por_que(): void
    {
        $e = $this->escenarioDeMermas();

        $texto = preg_replace('/\s+/', ' ', strip_tags($this->actingAs($this->u('admin'))->get(route('informes.mermas'))->getContent()));

        $this->assertStringContainsString($e['b']->nombre, $texto);
        $this->assertStringContainsString('Baja por vencimiento, lote L1', $texto);
        $this->assertStringContainsString('Se rompió en el estante', $texto);
        $this->assertStringContainsString('admin', $texto);
    }

    public function test_un_rango_sin_ajustes_dice_que_no_se_perdio_nada(): void
    {
        $this->escenarioDeMermas();

        $this->actingAs($this->u('admin'))
            ->get(route('informes.mermas', ['desde' => now()->subDays(20)->toDateString(), 'hasta' => now()->subDays(10)->toDateString()]))
            ->assertOk()->assertSee('No se perdió nada en el período.');
    }

    public function test_las_ventas_no_cuentan_como_merma(): void
    {
        $this->vender($this->p('P-0004'));

        $this->actingAs($this->u('admin'))->get(route('informes.mermas'))->assertOk()
            ->assertSee('Bs 0.00')->assertSee('No se perdió nada en el período.');
    }

    public function test_las_mermas_se_valoran_al_costo_actual(): void
    {
        $e = $this->escenarioDeMermas();
        $e['a']->forceFill(['precio_compra' => 6.00])->save();     // 3 × 6,00 = 18,00 en vez de 12,00

        $this->actingAs($this->u('admin'))->get(route('informes.mermas'))->assertOk()->assertSee('Bs 48.00');
    }

    // ============================================================ productos sin movimiento

    public function test_con_el_tiempo_lo_que_no_se_vende_aparece_como_plata_parada(): void
    {
        $this->actingAs($this->u('admin'));
        $conStock = Producto::activos()->where('stock_actual', '>', 0)->count();
        $this->assertGreaterThan(3, $conStock);

        // Hoy todo acaba de llegar con la carga inicial: nada está dormido.
        $this->get(route('informes.sin-movimiento'))->assertOk()->assertSee('Todo lo que tienes se movió en los últimos 60 días.');

        // Pasan 100 días sin vender nada.
        Carbon::setTestNow(now()->addDays(100));
        $texto = preg_replace('/\s+/', ' ', strip_tags($this->get(route('informes.sin-movimiento'))->getContent()));

        $this->assertMatchesRegularExpression("/Productos sin moverse {$conStock} sin una venta en 60 días/", $texto);
        $this->assertStringContainsString('Nunca vendidos '.$conStock, $texto);

        Carbon::setTestNow();
    }

    public function test_lo_que_se_vendio_o_llego_hace_poco_no_esta_dormido(): void
    {
        $this->actingAs($this->u('admin'));
        $conStock = Producto::activos()->where('stock_actual', '>', 0)->count();

        Carbon::setTestNow(now()->addDays(100));

        $vendido = $this->p('P-0004');
        $this->vender($vendido, now());                                       // se vendió hoy (a los 100 días)
        $recibido = $this->p('P-0005');
        Inventario::ingreso($recibido, 5, usuarioId: $this->u('admin')->id);  // llegó hoy

        $texto = preg_replace('/\s+/', ' ', strip_tags($this->get(route('informes.sin-movimiento'))->getContent()));

        $this->assertMatchesRegularExpression('/Productos sin moverse '.($conStock - 2).' /', $texto);
        $this->assertStringNotContainsString($vendido->nombre, $texto);
        $this->assertStringNotContainsString($recibido->nombre, $texto);

        Carbon::setTestNow();
    }

    public function test_la_plata_parada_es_stock_por_costo_y_va_de_mayor_a_menor(): void
    {
        $this->actingAs($this->u('admin'));
        $grande = $this->p('P-0001', 100.00);
        $chico = $this->p('P-0002', 1.00);
        DB::table('productos')->whereNotIn('id', [$grande->id, $chico->id])->update(['stock_actual' => 0]);
        DB::table('productos')->where('id', $grande->id)->update(['stock_actual' => 3]);
        DB::table('productos')->where('id', $chico->id)->update(['stock_actual' => 7]);

        Carbon::setTestNow(now()->addDays(100));
        $texto = preg_replace('/\s+/', ' ', strip_tags($this->get(route('informes.sin-movimiento'))->getContent()));
        Carbon::setTestNow();

        $this->assertStringContainsString('Plata parada Bs 307.00', $texto);        // 3×100 + 7×1
        $this->assertLessThan(strpos($texto, $chico->nombre), strpos($texto, $grande->nombre), 'lo de más valor primero');
        $this->assertStringContainsString('nunca', $texto);
    }

    public function test_un_producto_dado_de_baja_o_sin_stock_no_cuenta(): void
    {
        $this->actingAs($this->u('admin'));
        $baja = $this->p('P-0001');
        $vacio = $this->p('P-0002');
        $baja->forceFill(['activo' => 0])->save();
        DB::table('productos')->where('id', $vacio->id)->update(['stock_actual' => 0]);

        Carbon::setTestNow(now()->addDays(100));
        $texto = preg_replace('/\s+/', ' ', strip_tags($this->get(route('informes.sin-movimiento'))->getContent()));
        Carbon::setTestNow();

        // Solo la tabla del informe: la campana de stock bajo, arriba, sí puede nombrarlos.
        $tabla = substr($texto, (int) strpos($texto, 'Lo que no se vende'));
        $this->assertStringNotContainsString($baja->nombre, $tabla);
        $this->assertStringNotContainsString($vacio->nombre, $tabla);
    }

    public function test_el_plazo_se_elige_entre_los_que_se_ofrecen(): void
    {
        $this->actingAs($this->u('admin'));
        $conStock = Producto::activos()->where('stock_actual', '>', 0)->count();

        Carbon::setTestNow(now()->addDays(100));

        // 365 días: a los 100 todavía no es nada.
        $this->get(route('informes.sin-movimiento', ['dias' => 365]))->assertSee('Todo lo que tienes se movió en los últimos 365 días.');
        // 90 días: ya.
        $this->assertMatchesRegularExpression("/Productos sin moverse {$conStock} /", preg_replace('/\s+/', ' ', strip_tags($this->get(route('informes.sin-movimiento', ['dias' => 90]))->getContent())));
        // Un plazo que no se ofrece cae al de siempre (60).
        $this->get(route('informes.sin-movimiento', ['dias' => 7]))->assertSee('sin una venta en 60 días');

        Carbon::setTestNow();

        $this->get(route('informes.sin-movimiento'))->assertSee('data-plazos', false);
        $this->assertSame([30, 60, 90, 180, 365], InformeController::PLAZOS_SIN_MOVIMIENTO);
    }

    // ============================================================ horas de más venta

    public function test_las_ventas_se_reparten_por_dia_y_por_hora(): void
    {
        $lunes = Carbon::parse('2026-09-07 10:15:00');       // lunes
        $sabado = Carbon::parse('2026-09-12 18:05:00');       // sábado

        $this->vender($this->p('P-0004'), $lunes);
        $this->vender($this->p('P-0004'), $lunes->copy()->setTime(10, 45));
        $this->vender($this->p('P-0005'), $sabado);

        $url = route('informes.horas', ['desde' => '2026-09-01', 'hasta' => '2026-09-30']);
        $texto = preg_replace('/\s+/', ' ', strip_tags($this->actingAs($this->u('admin'))->get($url)->assertOk()->getContent()));

        $this->assertStringContainsString('Ventas del período 3', $texto);
        $this->assertMatchesRegularExpression('/Lunes 2 Bs/', $texto);
        $this->assertMatchesRegularExpression('/Sábado 1 Bs/', $texto);
        $this->assertMatchesRegularExpression('/Martes 0 Bs 0\.00/', $texto);
        $this->assertMatchesRegularExpression('/10:00 a 10:59 2 Bs/', $texto);
        $this->assertMatchesRegularExpression('/18:00 a 18:59 1 Bs/', $texto);
        $this->assertStringNotContainsString('09:00 a 09:59', $texto, 'las horas sin ventas no salen en la tabla');
    }

    public function test_el_dia_y_la_hora_mas_fuertes_se_miden_por_lo_vendido(): void
    {
        // Dos ventas baratas el lunes a las 10 y una cara el sábado a las 18.
        $barato = $this->p('P-0004');
        $caro = $this->p('P-0005');
        DB::table('productos')->where('id', $caro->id)->update(['precio_venta' => 500]);

        $this->vender($barato, Carbon::parse('2026-09-07 10:00:00'));
        $this->vender($barato, Carbon::parse('2026-09-07 10:30:00'));
        $this->vender($caro->fresh(), Carbon::parse('2026-09-12 18:00:00'));

        $texto = preg_replace('/\s+/', ' ', strip_tags($this->actingAs($this->u('admin'))
            ->get(route('informes.horas', ['desde' => '2026-09-01', 'hasta' => '2026-09-30']))->getContent()));

        $this->assertMatchesRegularExpression('/Día más fuerte Sábado/', $texto);
        $this->assertMatchesRegularExpression('/Hora más fuerte 18:00 h/', $texto);
    }

    public function test_las_ventas_anuladas_y_las_de_fuera_del_rango_no_cuentan(): void
    {
        $dentro = $this->vender($this->p('P-0004'), Carbon::parse('2026-09-08 11:00:00'));
        $anulada = $this->vender($this->p('P-0005'), Carbon::parse('2026-09-08 12:00:00'));
        $this->vender($this->p('P-0004'), Carbon::parse('2026-08-08 11:00:00'));      // agosto

        Ventas::anular($anulada, $this->u('admin'), 'Prueba de horas');

        $texto = preg_replace('/\s+/', ' ', strip_tags($this->actingAs($this->u('admin'))
            ->get(route('informes.horas', ['desde' => '2026-09-01', 'hasta' => '2026-09-30']))->getContent()));

        $this->assertStringContainsString('Ventas del período 1', $texto);
        $this->assertMatchesRegularExpression('/11:00 a 11:59 1 Bs/', $texto);
        $this->assertStringNotContainsString('12:00 a 12:59', $texto);
        $this->assertSame('COMPLETADA', $dentro->fresh()->estado);
    }

    public function test_el_mapa_de_calor_pinta_mas_oscuro_lo_que_mas_se_vendio(): void
    {
        $this->vender($this->p('P-0004'), Carbon::parse('2026-09-07 10:00:00'));
        $caro = $this->p('P-0005');
        DB::table('productos')->where('id', $caro->id)->update(['precio_venta' => 900]);
        $this->vender($caro->fresh(), Carbon::parse('2026-09-12 18:00:00'));

        $html = $this->actingAs($this->u('admin'))
            ->get(route('informes.horas', ['desde' => '2026-09-01', 'hasta' => '2026-09-30']))->getContent();

        $this->assertStringContainsString('data-mapa-horas', $html);
        // La celda más vendida va con intensidad 1; la otra, bastante más clara.
        $this->assertStringContainsString('rgba(70, 95, 255, 1)', $html);
        $this->assertMatchesRegularExpression('/rgba\(70, 95, 255, 0\.0\d\)/', $html);
        // Las horas que se muestran van de la primera venta a la última.
        $this->assertStringContainsString('10h', $html);
        $this->assertStringContainsString('18h', $html);
        $this->assertStringNotContainsString('>09h<', $html);
    }

    public function test_sin_ventas_no_hay_mapa_ni_division_por_cero(): void
    {
        $this->actingAs($this->u('admin'))
            ->get(route('informes.horas', ['desde' => '2020-01-01', 'hasta' => '2020-01-31']))
            ->assertOk()->assertDontSee('data-mapa-horas', false)->assertSee('No hubo ventas en el período.');
    }

    // ============================================================ descargas

    /** Todas las celdas con algo, de todas las hojas del libro (hay una por cada desglose). */
    private function hojaDe(string $ruta, array $parametros = []): Collection
    {
        $respuesta = $this->actingAs($this->u('admin'))->get(route($ruta, $parametros))->assertOk();
        $temporal = tempnam(sys_get_temp_dir(), 'inf').'.xlsx';
        file_put_contents($temporal, $respuesta->streamedContent());

        try {
            return collect(IOFactory::load($temporal)->getAllSheets())
                ->flatMap(fn ($hoja) => $hoja->toArray(null, true, false, false))
                ->flatten()
                ->filter(fn ($c) => $c !== null && $c !== '')
                ->values();
        } finally {
            @unlink($temporal);
        }
    }

    private function contiene(Collection $celdas, string $texto): bool
    {
        return $celdas->contains(fn ($c) => is_string($c) && str_contains($c, $texto));
    }

    public function test_el_excel_de_mermas_lleva_las_mismas_cifras_que_la_pantalla(): void
    {
        $this->escenarioDeMermas();

        $celdas = $this->hojaDe('informes.mermas.excel');

        $this->assertTrue($this->contiene($celdas, 'Mermas'));
        $this->assertTrue($celdas->contains('Vencimiento'));
        $this->assertTrue($celdas->contains('Ajuste manual'));
        $this->assertTrue($celdas->contains('Conteo de inventario'));
        $this->assertTrue($celdas->contains(fn ($c) => is_numeric($c) && abs((float) $c - 42.0) < 0.001), 'el total perdido, 42,00, va como número');
    }

    public function test_el_excel_de_horas_y_de_sin_movimiento_se_abren(): void
    {
        $this->vender($this->p('P-0004'), Carbon::parse('2026-09-07 10:00:00'));

        $horas = $this->hojaDe('informes.horas.excel', ['desde' => '2026-09-01', 'hasta' => '2026-09-30']);
        $this->assertTrue($horas->contains('Lunes'));
        $this->assertTrue($horas->contains('10:00 a 10:59'));

        $sin = $this->hojaDe('informes.sin-movimiento.excel', ['dias' => 30]);
        $this->assertTrue($this->contiene($sin, 'Productos sin movimiento'));
    }

    public function test_los_pdf_se_descargan_con_su_nombre(): void
    {
        $admin = $this->u('admin');

        foreach ([
            ['informes.mermas.pdf', 'reporte-mermas_'],
            ['informes.horas.pdf', 'reporte-horas_'],
            ['informes.sin-movimiento.pdf', 'reporte-sin-movimiento_'],
        ] as [$ruta, $prefijo]) {
            $r = $this->actingAs($admin)->get(route($ruta))->assertOk();

            $this->assertStringContainsString('application/pdf', $r->headers->get('Content-Type'));
            $this->assertStringContainsString($prefijo, $r->headers->get('Content-Disposition'));
            $this->assertStringStartsWith('%PDF', $r->getContent());
        }
    }

    public function test_los_reportes_de_siempre_siguen_funcionando_con_la_pieza_comun(): void
    {
        $admin = $this->u('admin');

        foreach (['reportes.ventas', 'reportes.productos', 'reportes.ventas.excel', 'reportes.productos.excel', 'reportes.ventas.pdf', 'reportes.productos.pdf'] as $ruta) {
            $this->actingAs($admin)->get(route($ruta, ['desde' => '2026-09-01', 'hasta' => '2026-09-30']))->assertOk();
        }

        $this->assertStringContainsString('reporte-ventas_2026-09-01_2026-09-30.pdf',
            $this->actingAs($admin)->get(route('reportes.ventas.pdf', ['desde' => '2026-09-01', 'hasta' => '2026-09-30']))->headers->get('Content-Disposition'));
    }

    // ============================================================ los bordes de «sin movimiento» y del rango

    /** Un producto nuevo, con su único ingreso hoy: sirve para medir los días exactos. */
    private function productoNuevo(string $codigo): Producto
    {
        $base = Producto::where('codigo', 'P-0004')->firstOrFail();
        $nuevo = Producto::create([
            'categoria_id' => $base->categoria_id, 'unidad_medida_id' => $base->unidad_medida_id,
            'codigo' => $codigo, 'nombre' => 'Producto de borde '.$codigo,
            'precio_compra' => 3, 'precio_venta' => 5, 'afecto_impuesto' => 0, 'stock_minimo' => 0, 'activo' => 1,
        ]);
        Inventario::ingreso($nuevo, 5, usuarioId: $this->u('admin')->id);

        return $nuevo->fresh();
    }

    /** Solo la tabla de lo parado: la campana del encabezado también nombra productos. */
    private function tablaDeParados(?int $dias = null): string
    {
        $html = $this->actingAs($this->u('admin'))
            ->get(route('informes.sin-movimiento', $dias ? ['dias' => $dias] : []))->getContent();

        $texto = preg_replace('/\s+/', ' ', strip_tags($html));

        return substr($texto, (int) strpos($texto, 'Lo que no se vende'));
    }

    public function test_el_dia_exacto_en_que_un_ingreso_pasa_a_estar_parado(): void
    {
        $producto = $this->productoNuevo('P-9701');
        $hoy = now();

        // A los 60 días justos todavía cuenta como movido; al día siguiente, no.
        Carbon::setTestNow($hoy->copy()->addDays(60));
        $this->assertStringNotContainsString($producto->nombre, $this->tablaDeParados());

        Carbon::setTestNow($hoy->copy()->addDays(61));
        $this->assertStringContainsString($producto->nombre, $this->tablaDeParados());
    }

    public function test_una_venta_reciente_basta_para_que_no_este_parado_aunque_el_ingreso_sea_viejo(): void
    {
        $vendido = $this->productoNuevo('P-9702');
        $quieto = $this->productoNuevo('P-9703');
        $hoy = now();

        Carbon::setTestNow($hoy->copy()->addDays(100));
        $cajero = $this->u('cajero1');
        $venta = Ventas::registrar(
            sesion: $this->turno(),
            usuario: $cajero,
            lineas: [['producto_id' => $vendido->id, 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
        DB::table('ventas')->where('id', $venta->id)->update(['fecha' => now()]);

        $tabla = $this->tablaDeParados();
        $this->assertStringNotContainsString($vendido->nombre, $tabla);
        $this->assertStringContainsString($quieto->nombre, $tabla);
    }

    public function test_una_venta_anulada_no_salva_a_un_producto_de_estar_parado(): void
    {
        $producto = $this->productoNuevo('P-9704');
        $hoy = now();

        Carbon::setTestNow($hoy->copy()->addDays(100));
        $cajero = $this->u('cajero1');
        $venta = Ventas::registrar(
            sesion: $this->turno(),
            usuario: $cajero,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
        DB::table('ventas')->where('id', $venta->id)->update(['fecha' => now()]);
        Ventas::anular($venta->fresh(), $this->u('admin'), 'Se devolvió todo');

        $this->assertStringContainsString($producto->nombre, $this->tablaDeParados(), 'una venta anulada no es una venta');
    }

    public function test_un_rango_al_reves_se_endereza_en_vez_de_salir_vacio(): void
    {
        $this->escenarioDeMermas();

        $this->actingAs($this->u('admin'))
            ->get(route('informes.mermas', ['desde' => now()->addDay()->toDateString(), 'hasta' => now()->subDays(10)->toDateString()]))
            ->assertOk()->assertSee('Bs 42.00')->assertDontSee('No se perdió nada en el período.');
    }
}
