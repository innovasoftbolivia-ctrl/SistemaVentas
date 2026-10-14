<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\AuditoriaArchivo;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\Archivador;
use App\Services\Descuadres;
use App\Support\Config;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Tests\TestCase;

/**
 * Segunda auditoría de octubre de 2026: el archivado anual.
 *
 * La bitácora y el kardex solo crecen y no se borran. Archivar es MOVER lo de más
 * de un año a una tabla gemela, sin perder una fila y sin descuadrar nada.
 */
class ArchivadoAnualTest extends TestCase
{
    use DatabaseTransactions;

    /** Todo lo de antes del 2025 se archiva sin pedir permiso especial: tiene más de un año. */
    private function corte(): Carbon
    {
        return Carbon::parse('2025-01-01');
    }

    protected function tearDown(): void
    {
        Archivador::$lote = Archivador::LOTE;

        parent::tearDown();
    }

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function bitacora(string $accion, string $fecha, ?array $detalle = null): int
    {
        return (int) DB::table('auditoria')->insertGetId([
            'usuario_id' => $this->u('admin')->id, 'accion' => $accion, 'entidad' => 'productos', 'entidad_id' => 1,
            'detalle' => $detalle ? json_encode($detalle) : null, 'ip' => '10.0.0.1', 'fecha' => $fecha,
        ]);
    }

    private function productoNuevo(string $codigo): Producto
    {
        $base = Producto::where('codigo', 'P-0004')->firstOrFail();

        return Producto::create([
            'categoria_id' => $base->categoria_id, 'unidad_medida_id' => $base->unidad_medida_id,
            'codigo' => $codigo, 'nombre' => 'Producto de archivo '.$codigo,
            'precio_compra' => 3, 'precio_venta' => 5, 'afecto_impuesto' => 0, 'stock_minimo' => 0, 'activo' => 1,
        ])->fresh();
    }

    /** Un movimiento de carga inicial de $cantidad unidades, encadenado al stock anterior. */
    private function movimiento(Producto $p, string $fecha, float $anterior, float $cantidad): int
    {
        return (int) DB::table('movimientos_inventario')->insertGetId([
            'producto_id' => $p->id, 'usuario_id' => null, 'tipo' => 'ENTRADA', 'origen' => 'INICIAL',
            'cantidad' => $cantidad, 'stock_anterior' => $anterior, 'stock_resultante' => $anterior + $cantidad,
            'costo_unitario' => 3, 'motivo' => 'prueba', 'fecha' => $fecha,
        ]);
    }

    private function enLaBitacora(int $id): bool
    {
        return DB::table('auditoria')->where('id', $id)->exists();
    }

    private function enElArchivo(int $id): bool
    {
        return DB::table('auditoria_archivo')->where('id', $id)->exists();
    }

    // ============================================================ la bitácora

    public function test_lo_viejo_de_la_bitacora_pasa_al_archivo_y_lo_reciente_se_queda(): void
    {
        $vieja = $this->bitacora('CAMBIO_PRECIO', '2024-03-05 10:00:00', ['codigo' => 'P-1', 'anterior' => 1, 'nuevo' => 2]);
        $reciente = $this->bitacora('CAMBIO_PRECIO', now()->subDays(3)->toDateTimeString());
        $justoAntes = $this->bitacora('LOGIN', '2024-12-31 23:59:59');
        $justoDespues = $this->bitacora('LOGIN', '2025-01-01 00:00:00');

        $r = Archivador::archivar($this->corte());

        $this->assertFalse($this->enLaBitacora($vieja));
        $this->assertTrue($this->enElArchivo($vieja));
        $this->assertTrue($this->enLaBitacora($reciente));
        $this->assertFalse($this->enElArchivo($reciente));
        $this->assertTrue($this->enElArchivo($justoAntes), 'el último segundo antes del corte se archiva');
        $this->assertTrue($this->enLaBitacora($justoDespues), 'el corte mismo ya no');
        $this->assertGreaterThanOrEqual(2, $r['auditoria']);
    }

    public function test_la_fila_archivada_es_identica_a_la_original(): void
    {
        $id = $this->bitacora('CAMBIO_PRECIO', '2024-03-05 10:00:00', ['codigo' => 'P-1', 'cambios' => ['precio' => ['antes' => '1', 'despues' => '2']]]);
        $antes = (array) DB::table('auditoria')->where('id', $id)->first();

        Archivador::archivar($this->corte());

        $archivada = (array) DB::table('auditoria_archivo')->where('id', $id)->first();
        $this->assertNotEmpty($archivada['archivado_en']);
        unset($archivada['archivado_en']);
        $this->assertEquals($antes, $archivada, 'misma id, mismo usuario, misma fecha, mismo detalle');
    }

    public function test_archivar_dos_veces_no_mueve_nada_la_segunda(): void
    {
        $this->bitacora('LOGIN', '2024-03-05 10:00:00');

        $primera = Archivador::archivar($this->corte());
        $segunda = Archivador::archivar($this->corte());

        $this->assertGreaterThan(0, $primera['auditoria']);
        $this->assertSame(0, $segunda['auditoria']);
        $this->assertSame(0, $segunda['movimientos']);
    }

    public function test_un_corte_con_menos_de_un_ano_se_rechaza_salvo_que_se_fuerce(): void
    {
        $hace6Meses = now()->subMonths(6);
        $id = $this->bitacora('LOGIN', $hace6Meses->copy()->subDay()->toDateTimeString());

        try {
            Archivador::archivar($hace6Meses);
            $this->fail('debió rechazar un corte de hace seis meses');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('más de un año', $e->getMessage());
        }
        $this->assertTrue($this->enLaBitacora($id), 'y no tocó nada');

        Archivador::archivar($hace6Meses, forzar: true);
        $this->assertTrue($this->enElArchivo($id));
    }

    public function test_el_corte_de_hace_exactamente_un_ano_es_valido(): void
    {
        $this->assertSame(Archivador::MINIMO_DIAS, 365);

        Archivador::archivar(now()->subDays(365), simular: true);
        $this->expectException(RuntimeException::class);
        Archivador::archivar(now()->subDays(364), simular: true);
    }

    public function test_simular_cuenta_sin_mover(): void
    {
        $id = $this->bitacora('LOGIN', '2024-03-05 10:00:00');

        $r = Archivador::archivar($this->corte(), simular: true);

        $this->assertTrue($r['simulado']);
        $this->assertGreaterThanOrEqual(1, $r['auditoria']);
        $this->assertTrue($this->enLaBitacora($id));
        $this->assertFalse($this->enElArchivo($id));
        $this->assertNull(Archivador::archivadoHasta());
    }

    public function test_varios_lotes_mueven_todo(): void
    {
        Archivador::$lote = 2;
        $ids = array_map(fn ($i) => $this->bitacora('LOGIN', "2024-03-0{$i} 10:00:00"), range(1, 7));

        $r = Archivador::archivar($this->corte());

        $this->assertGreaterThanOrEqual(7, $r['auditoria']);
        foreach ($ids as $id) {
            $this->assertTrue($this->enElArchivo($id));
            $this->assertFalse($this->enLaBitacora($id));
        }
    }

    public function test_queda_anotado_y_se_recuerda_hasta_cuando_se_archivo(): void
    {
        $this->bitacora('LOGIN', '2024-03-05 10:00:00');

        $this->assertNull(Archivador::archivadoHasta());
        Archivador::archivar($this->corte());

        $this->assertSame('2025-01-01', Archivador::archivadoHasta()->toDateString());
        $huella = Auditoria::where('accion', 'ARCHIVADO')->latest('id')->firstOrFail();
        $this->assertSame('2025-01-01', $huella->detalle['hasta']);
        $this->assertGreaterThanOrEqual(1, $huella->detalle['bitacora']);

        // Un corte más viejo después no hace retroceder el recuerdo.
        $this->bitacora('LOGIN', '2023-03-05 10:00:00');
        Archivador::archivar(Carbon::parse('2024-01-01'));
        $this->assertSame('2025-01-01', Archivador::archivadoHasta()->toDateString());
    }

    public function test_si_no_se_movio_nada_no_se_anota_ni_se_recuerda_nada(): void
    {
        Archivador::archivar(Carbon::parse('2001-01-01'));

        $this->assertNull(Archivador::archivadoHasta());
        $this->assertSame(0, Auditoria::where('accion', 'ARCHIVADO')->count());
    }

    // ============================================================ el kardex

    public function test_del_kardex_se_archiva_lo_viejo_menos_el_ultimo_movimiento_de_cada_producto(): void
    {
        $a = $this->productoNuevo('P-9801');
        $b = $this->productoNuevo('P-9802');

        $a1 = $this->movimiento($a, '2024-01-10 09:00:00', 0, 10);
        $a2 = $this->movimiento($a, '2024-02-10 09:00:00', 10, 5);
        $aReciente = $this->movimiento($a, now()->subDays(2)->toDateTimeString(), 15, 1);
        $b1 = $this->movimiento($b, '2024-01-10 09:00:00', 0, 8);
        $b2 = $this->movimiento($b, '2024-03-10 09:00:00', 8, 2); // el último de B, aunque sea viejo

        $r = Archivador::archivar($this->corte());

        $enArchivo = fn (int $id) => DB::table('movimientos_inventario_archivo')->where('id', $id)->exists();
        $enKardex = fn (int $id) => DB::table('movimientos_inventario')->where('id', $id)->exists();

        $this->assertTrue($enArchivo($a1) && ! $enKardex($a1));
        $this->assertTrue($enArchivo($a2) && ! $enKardex($a2));
        $this->assertTrue($enKardex($aReciente) && ! $enArchivo($aReciente));
        $this->assertTrue($enArchivo($b1) && ! $enKardex($b1));
        $this->assertTrue($enKardex($b2) && ! $enArchivo($b2), 'el último de cada producto no se mueve: es contra el que se revisa el stock');
        $this->assertGreaterThanOrEqual(3, $r['movimientos']);
    }

    public function test_un_movimiento_que_usa_una_toma_de_inventario_no_se_archiva(): void
    {
        $p = $this->productoNuevo('P-9803');
        $usado = $this->movimiento($p, '2024-01-10 09:00:00', 0, 10);
        $libre = $this->movimiento($p, '2024-01-11 09:00:00', 10, 1);
        $ultimo = $this->movimiento($p, '2024-01-12 09:00:00', 11, 1);

        $toma = DB::table('tomas_inventario')->insertGetId([
            'estado' => 'CERRADA', 'usuario_apertura_id' => $this->u('admin')->id, 'usuario_cierre_id' => $this->u('admin')->id,
            'fecha_apertura' => '2024-01-10 08:00:00', 'fecha_cierre' => '2024-01-10 10:00:00',
        ]);
        DB::table('toma_inventario_detalle')->insert([
            'toma_id' => $toma, 'producto_id' => $p->id, 'contado' => 10, 'stock_sistema' => 10, 'movimiento_id' => $usado,
        ]);

        Archivador::archivar($this->corte());

        $this->assertTrue(DB::table('movimientos_inventario')->where('id', $usado)->exists(), 'lo cita una toma: la llave foránea no lo suelta');
        $this->assertTrue(DB::table('movimientos_inventario_archivo')->where('id', $libre)->exists());
        $this->assertTrue(DB::table('movimientos_inventario')->where('id', $ultimo)->exists());
    }

    public function test_archivar_el_kardex_no_crea_descuadres(): void
    {
        $p = $this->productoNuevo('P-9804');
        DB::table('productos')->where('id', $p->id)->update(['stock_actual' => 17]);
        $this->movimiento($p, '2024-01-10 09:00:00', 0, 10);
        $this->movimiento($p, '2024-02-10 09:00:00', 10, 5);
        $this->movimiento($p, '2024-03-10 09:00:00', 15, 2);

        $antes = Descuadres::revisar()['cantidad'];
        Archivador::archivar($this->corte());
        $despues = Descuadres::revisar()['cantidad'];

        $this->assertSame($antes, $despues, 'el último movimiento se queda: stock y kardex siguen cuadrando');
    }

    // ============================================================ el archivo no se toca

    public function test_el_archivo_no_se_edita_ni_se_borra(): void
    {
        if (! $this->hayTriggers()) {
            $this->markTestSkipped('Sin triggers esta regla vive en los modelos (se prueba abajo).');
        }

        $id = $this->bitacora('LOGIN', '2024-03-05 10:00:00');
        // Con filas en las dos tablas del archivo: sin filas, un UPDATE o un DELETE no dispara ningún trigger.
        $p = $this->productoNuevo('P-9807');
        $this->movimiento($p, '2024-01-10 09:00:00', 0, 10);
        $this->movimiento($p, '2024-02-10 09:00:00', 10, 1);
        Archivador::archivar($this->corte());
        $this->assertGreaterThan(0, DB::table('movimientos_inventario_archivo')->count());

        // Ni declarándose «archivando»: el archivo no tiene bypass.
        DB::statement('SET @archivando = 1');
        try {
            foreach ([
                fn () => DB::table('auditoria_archivo')->where('id', $id)->update(['accion' => 'OTRA']),
                fn () => DB::table('auditoria_archivo')->where('id', $id)->delete(),
                fn () => DB::table('movimientos_inventario_archivo')->update(['motivo' => 'x']),
                fn () => DB::table('movimientos_inventario_archivo')->delete(),
            ] as $intento) {
                try {
                    $intento();
                    $this->fail('el archivo aceptó un cambio');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('archivo', $e->getMessage());
                }
            }
        } finally {
            DB::statement('SET @archivando = NULL');
        }

        $this->assertTrue($this->enElArchivo($id));
    }

    public function test_la_bitacora_viva_sigue_sin_poder_borrarse_fuera_del_archivado(): void
    {
        if (! $this->hayTriggers()) {
            $this->markTestSkipped('Sin triggers esta regla vive en los modelos.');
        }

        $id = $this->bitacora('LOGIN', '2024-03-05 10:00:00');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no se borra');
        DB::table('auditoria')->where('id', $id)->delete();
    }

    /** Declararse «archivando» vale solo mientras dura el archivado: si no, esa sesión borraría lo que quisiera. */
    public function test_terminado_el_archivado_la_sesion_deja_de_poder_borrar_la_bitacora(): void
    {
        if (! $this->hayTriggers()) {
            $this->markTestSkipped('Sin triggers esta regla vive en los modelos.');
        }

        $this->bitacora('LOGIN', '2024-03-05 10:00:00');
        $reciente = $this->bitacora('LOGIN', now()->subDay()->toDateTimeString());
        Archivador::archivar($this->corte());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no se borra');
        DB::table('auditoria')->where('id', $reciente)->delete();
    }

    // ============================================================ los reportes avisan que parte está archivada

    public function test_las_mermas_avisan_si_el_periodo_empieza_antes_del_corte(): void
    {
        $this->bitacora('LOGIN', '2024-03-05 10:00:00');
        Archivador::archivar($this->corte());
        $admin = $this->u('admin');

        $this->actingAs($admin)->get(route('informes.mermas', ['desde' => '2024-06-01', 'hasta' => '2024-06-30']))->assertOk()
            ->assertSee('data-aviso-archivo', false)->assertSee('anterior al 01/01/2025', false)
            ->assertSee('Ver el archivo');

        $this->actingAs($admin)->get(route('informes.mermas', ['desde' => '2025-06-01', 'hasta' => '2025-06-30']))->assertOk()
            ->assertDontSee('data-aviso-archivo', false);
    }

    public function test_sin_archivado_las_mermas_no_avisan_nada(): void
    {
        $this->actingAs($this->u('admin'))->get(route('informes.mermas', ['desde' => '2020-01-01']))->assertOk()
            ->assertDontSee('data-aviso-archivo', false);
    }

    public function test_el_gemelo_de_los_modelos_protege_el_archivo_cuando_no_hay_triggers(): void
    {
        if ($this->hayTriggers()) {
            $this->markTestSkipped('Con triggers la base ya lo hace (prueba de arriba).');
        }

        $id = $this->bitacora('LOGIN', '2024-03-05 10:00:00');
        Archivador::archivar($this->corte());

        $this->expectException(\Throwable::class);
        AuditoriaArchivo::findOrFail($id)->delete();
    }

    private function hayTriggers(): bool
    {
        return (int) DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'trg_auditoria_before_delete')->count() > 0;
    }

    // ============================================================ se consulta desde las pantallas

    public function test_la_bitacora_se_lee_del_archivo_con_el_interruptor(): void
    {
        $vieja = $this->bitacora('CAMBIO_PRECIO', '2024-03-05 10:00:00', ['codigo' => 'P-VIEJO']);
        $reciente = $this->bitacora('CAMBIO_PRECIO', now()->subDay()->toDateTimeString(), ['codigo' => 'P-NUEVO']);
        Archivador::archivar($this->corte());
        $admin = $this->u('admin');

        $normal = $this->actingAs($admin)->get(route('bitacora.index', ['accion' => 'CAMBIO_PRECIO']))->assertOk();
        $normal->assertSee('P-NUEVO')->assertDontSee('P-VIEJO')
            ->assertSee('data-archivo', false)->assertSee('lo anterior al 01/01/2025 está archivado');

        $this->actingAs($admin)->get(route('bitacora.index', ['accion' => 'CAMBIO_PRECIO', 'archivo' => 1]))->assertOk()
            ->assertSee('P-VIEJO')->assertDontSee('P-NUEVO');
    }

    public function test_sin_nada_archivado_la_bitacora_no_ofrece_el_interruptor(): void
    {
        $this->actingAs($this->u('admin'))->get(route('bitacora.index'))->assertOk()->assertDontSee('data-archivo', false);
    }

    public function test_el_excel_de_la_bitacora_puede_ser_del_archivo(): void
    {
        $this->bitacora('CAMBIO_PRECIO', '2024-03-05 10:00:00', ['codigo' => 'P-VIEJO']);
        Archivador::archivar($this->corte());

        $respuesta = $this->actingAs($this->u('admin'))->get(route('bitacora.excel', ['archivo' => 1, 'accion' => 'CAMBIO_PRECIO']))->assertOk();
        $temporal = tempnam(sys_get_temp_dir(), 'arc').'.xlsx';
        file_put_contents($temporal, $respuesta->streamedContent());

        try {
            $celdas = collect(IOFactory::load($temporal)->getAllSheets())
                ->flatMap(fn ($h) => $h->toArray(null, true, false, false))->flatten()->filter()->values();
        } finally {
            @unlink($temporal);
        }

        $this->assertTrue($celdas->contains(fn ($c) => is_string($c) && str_contains($c, '(archivo)')));
        $this->assertTrue($celdas->contains(fn ($c) => is_string($c) && str_contains($c, 'P-VIEJO')));
    }

    public function test_el_kardex_general_se_lee_del_archivo_y_avisa(): void
    {
        $p = $this->productoNuevo('P-9805');
        $viejo = $this->movimiento($p, '2024-01-10 09:00:00', 0, 10);
        $this->movimiento($p, now()->subDay()->toDateTimeString(), 10, 1);
        Archivador::archivar($this->corte());
        $admin = $this->u('admin');

        $normal = $this->actingAs($admin)->get(route('inventario.movimientos', ['producto' => $p->id]))->assertOk();
        $normal->assertSee('data-hay-archivo', false)->assertSee('Ver el archivo')->assertDontSee('data-viendo-archivo', false);
        $this->assertSame(1, substr_count($normal->getContent(), 'data-movimiento'), 'solo el reciente');

        $archivo = $this->actingAs($admin)->get(route('inventario.movimientos', ['producto' => $p->id, 'archivo' => 1]))->assertOk();
        $archivo->assertSee('data-viendo-archivo', false)->assertSee('Volver a lo reciente');
        $this->assertTrue(DB::table('movimientos_inventario_archivo')->where('id', $viejo)->exists());
    }

    public function test_el_aviso_de_que_hay_archivo_no_sale_si_el_rango_es_posterior(): void
    {
        $this->bitacora('LOGIN', '2024-03-05 10:00:00');
        $this->productoNuevo('P-9806');
        Archivador::archivar($this->corte());

        $this->actingAs($this->u('admin'))
            ->get(route('inventario.movimientos', ['desde' => '2025-06-01']))->assertOk()
            ->assertDontSee('data-hay-archivo', false);
    }

    // ============================================================ el comando

    public function test_el_comando_simula_sin_mover(): void
    {
        $id = $this->bitacora('LOGIN', '2024-03-05 10:00:00');

        $this->artisan('archivar:anual', ['--hasta' => '2025-01-01', '--simular' => true])
            ->expectsOutputToContain('Simulación: no se movió nada.')->assertExitCode(0);

        $this->assertTrue($this->enLaBitacora($id));
    }

    public function test_el_comando_mueve_con_si_y_dice_cuanto(): void
    {
        $id = $this->bitacora('LOGIN', '2024-03-05 10:00:00');

        $this->artisan('archivar:anual', ['--hasta' => '2025-01-01', '--si' => true])
            ->expectsOutputToContain('Archivado:')->assertExitCode(0);

        $this->assertTrue($this->enElArchivo($id));
    }

    public function test_el_comando_pide_confirmacion_y_si_se_dice_que_no_no_mueve(): void
    {
        $id = $this->bitacora('LOGIN', '2024-03-05 10:00:00');

        $this->artisan('archivar:anual', ['--hasta' => '2025-01-01'])
            ->expectsConfirmation('¿Los muevo al archivo? Se copian, se comprueba y recién entonces se sacan de las tablas de uso diario.', 'no')
            ->assertExitCode(1);

        $this->assertTrue($this->enLaBitacora($id));
    }

    public function test_el_comando_rechaza_un_corte_reciente_y_una_fecha_mal_escrita(): void
    {
        $this->artisan('archivar:anual', ['--hasta' => now()->subMonth()->toDateString(), '--si' => true])
            ->expectsOutputToContain('más de un año')->assertExitCode(1);

        $this->artisan('archivar:anual', ['--hasta' => '15/03/2025'])
            ->expectsOutputToContain('AAAA-MM-DD')->assertFailed();
    }

    public function test_el_comando_sin_nada_que_archivar_lo_dice(): void
    {
        $this->artisan('archivar:anual', ['--hasta' => '2001-01-01', '--si' => true])
            ->expectsOutputToContain('No hay nada que archivar.')->assertExitCode(0);
    }

    public function test_el_corte_sugerido_es_el_primero_de_enero_del_ano_pasado(): void
    {
        Carbon::setTestNow('2027-03-20 10:00:00');
        try {
            $this->assertSame('2026-01-01', Archivador::corteSugerido()->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_la_clave_de_configuracion_es_la_que_lee_config(): void
    {
        DB::table('configuracion')->updateOrInsert(['clave' => Archivador::CLAVE], ['valor' => '2025-01-01']);
        Config::olvidar();

        $this->assertSame('2025-01-01', Archivador::archivadoHasta()->toDateString());
    }

    // ============================================================ la base

    public function test_las_tablas_del_archivo_tienen_las_columnas_de_las_originales_mas_archivado_en(): void
    {
        foreach (['auditoria', 'movimientos_inventario'] as $tabla) {
            $columnas = fn (string $t) => DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $t)->orderBy('ORDINAL_POSITION')->pluck('COLUMN_NAME')->all();

            $this->assertSame([...$columnas($tabla), 'archivado_en'], $columnas($tabla.'_archivo'), $tabla);
        }
    }

    public function test_el_archivo_no_tiene_llaves_foraneas(): void
    {
        $this->assertSame(0, (int) DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->whereIn('TABLE_NAME', ['auditoria_archivo', 'movimientos_inventario_archivo'])->count(), 'son historia: no dependen de nada');
    }
}
