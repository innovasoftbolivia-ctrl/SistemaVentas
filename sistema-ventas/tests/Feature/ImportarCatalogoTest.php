<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\ImportadorCatalogo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * La carga del catálogo inicial desde CSV o Excel.
 *
 * Lo que importa: que revise todo antes de tocar la base, que sea todo o nada,
 * que el stock entre por el kardex y que no se invente nada en silencio.
 */
class ImportarCatalogoTest extends TestCase
{
    use DatabaseTransactions;

    private const CABECERA = 'codigo_barras;nombre;categoria;unidad;precio_compra;precio_venta;stock;stock_minimo;proveedor;afecto_impuesto;controla_vencimiento;fecha_vencimiento;codigo;nombre_empaque;contenido_empaque';

    /** @var list<string> */
    private array $archivos = [];

    protected function tearDown(): void
    {
        foreach ($this->archivos as $a) {
            @unlink($a);
        }

        parent::tearDown();
    }

    /** @param  list<string>  $lineas */
    private function csv(array $lineas, string $extension = 'csv', string $cabecera = self::CABECERA): string
    {
        $ruta = sys_get_temp_dir().'/catalogo-'.bin2hex(random_bytes(4)).'.'.$extension;
        file_put_contents($ruta, implode("\r\n", array_merge([$cabecera], $lineas))."\r\n");

        return $this->archivos[] = $ruta;
    }

    private function categoria(): string
    {
        return Categoria::activas()->orderBy('id')->firstOrFail()->nombre;
    }

    private function importar(string $ruta, bool $aplicar = false, bool $crear = false): array
    {
        return app(ImportadorCatalogo::class)->importar($ruta, $aplicar, $crear);
    }

    private function unico(): string
    {
        return (string) random_int(100000000000, 999999999999);
    }

    // ============================================================ revisar no toca nada

    public function test_por_omision_solo_revisa_y_no_carga_nada(): void
    {
        $antes = Producto::count();
        $ruta = $this->csv([$this->unico().';Producto de prueba A;'.$this->categoria().';UND;5;8;10;2;;si;no;;;;']);

        $r = $this->importar($ruta);

        $this->assertSame(1, $r['validas']);
        $this->assertSame([], $r['errores']);
        $this->assertFalse($r['aplicado']);
        $this->assertSame($antes, Producto::count());
    }

    // ============================================================ carga

    public function test_carga_el_producto_con_su_stock_en_el_kardex(): void
    {
        $barras = $this->unico();
        $ruta = $this->csv([$barras.';Galletas de prueba;'.$this->categoria().';UND;5,50;8,00;24;6;;si;no;;;Caja;12']);

        $r = $this->importar($ruta, aplicar: true);

        $this->assertTrue($r['aplicado']);
        $this->assertSame(1, $r['creados']);

        $p = Producto::where('codigo_barras', $barras)->firstOrFail();
        $this->assertSame('Galletas de prueba', $p->nombre);
        $this->assertEquals(5.50, (float) $p->precio_compra, 'la coma decimal se entiende');
        $this->assertEquals(24, (float) $p->stock_actual);
        $this->assertSame('Caja', $p->nombre_empaque);
        $this->assertMatchesRegularExpression('/^P-\d{4,}$/', $p->codigo, 'sin código escrito, el correlativo');

        $mov = MovimientoInventario::where('producto_id', $p->id)->sole();
        $this->assertSame('INICIAL', $mov->origen);
        $this->assertEquals(0, (float) $mov->stock_anterior);
        $this->assertEquals(24, (float) $mov->stock_resultante);
        $this->assertEquals(5.50, (float) $mov->costo_unitario);

        $this->assertNotNull(Auditoria::where('accion', 'CATALOGO_IMPORTADO')->latest('id')->first());
    }

    public function test_un_perecedero_con_stock_crea_su_lote_con_fecha(): void
    {
        $vence = date('Y-m-d', strtotime('+60 days'));
        $barras = $this->unico();
        $ruta = $this->csv([$barras.';Yogur de prueba;'.$this->categoria().';UND;3;5;12;4;;si;si;'.date('d/m/Y', strtotime('+60 days')).';;;']);

        $this->importar($ruta, aplicar: true);

        $p = Producto::where('codigo_barras', $barras)->firstOrFail();
        $lote = Lote::where('producto_id', $p->id)->sole();
        $this->assertEquals(12, (float) $lote->cantidad_actual);
        $this->assertSame($vence, $lote->fecha_vencimiento->format('Y-m-d'));
    }

    public function test_las_filas_sin_codigo_reciben_correlativos_distintos(): void
    {
        $ruta = $this->csv([
            $this->unico().';Uno;'.$this->categoria().';UND;1;2;0;0;;si;no;;;;',
            $this->unico().';Dos;'.$this->categoria().';UND;1;2;0;0;;si;no;;;;',
            $this->unico().';Tres;'.$this->categoria().';UND;1;2;0;0;;si;no;;;;',
        ]);

        $this->importar($ruta, aplicar: true);

        $codigos = Producto::whereIn('nombre', ['Uno', 'Dos', 'Tres'])->pluck('codigo');
        $this->assertCount(3, $codigos->unique());
    }

    // ============================================================ todo o nada

    public function test_con_una_sola_fila_mala_no_se_carga_ninguna(): void
    {
        $antes = Producto::count();
        $ruta = $this->csv([
            $this->unico().';Producto bueno;'.$this->categoria().';UND;5;8;10;2;;si;no;;;;',
            $this->unico().';Producto malo;'.$this->categoria().';UND;abc;8;10;2;;si;no;;;;',
        ]);

        $r = $this->importar($ruta, aplicar: true);

        $this->assertFalse($r['aplicado']);
        $this->assertSame(1, $r['validas']);
        $this->assertSame(3, $r['errores'][0]['fila'] ?? null, 'la fila se numera como en la hoja del usuario');
        $this->assertSame($antes, Producto::count(), 'ni siquiera el producto bueno entró');
    }

    // ============================================================ lo que se rechaza

    /** @return array<string, array{0: string, 1: string}> */
    public static function filasMalas(): array
    {
        return [
            'unidad inexistente' => ['{b};Prod;{c};CAJOTE;5;8;1;0;;si;no;;;;', 'unidad'],
            'categoría que no existe' => ['{b};Prod;Categoría Fantasma;UND;5;8;1;0;;si;no;;;;', 'categoría'],
            'proveedor que no existe' => ['{b};Prod;{c};UND;5;8;1;0;Proveedor Fantasma SRL;si;no;;;;', 'proveedor'],
            'vende bajo el costo' => ['{b};Prod;{c};UND;10;8;1;0;;si;no;;;;', 'menor que el de compra'],
            'sin nombre' => ['{b};;{c};UND;5;8;1;0;;si;no;;;;', 'nombre'],
            'precio negativo' => ['{b};Prod;{c};UND;-5;8;1;0;;si;no;;;;', 'precio de compra'],
            'código de barras con letras' => ['12AB34;Prod;{c};UND;5;8;1;0;;si;no;;;;', 'solo admite dígitos'],
            'EAN estropeado por Excel' => ['7,77123E+12;Prod;{c};UND;5;8;1;0;;si;no;;;;', 'solo admite dígitos'],
            'stock con decimales en una unidad entera' => ['{b};Prod;{c};UND;5;8;2,5;0;;si;no;;;;', 'decimales'],
            'perecedero con stock y sin fecha' => ['{b};Prod;{c};UND;5;8;3;0;;si;si;;;;', 'fecha de vencimiento'],
            'fecha pasada' => ['{b};Prod;{c};UND;5;8;3;0;;si;si;2020-01-01;;;', 'ya pasó'],
            'fecha imposible' => ['{b};Prod;{c};UND;5;8;3;0;;si;si;31/02/2030;;;', 'ya pasó'],
            'fecha sin controlar vencimiento' => ['{b};Prod;{c};UND;5;8;3;0;;si;no;2030-01-01;;;', 'no está marcado'],
            'empaque a medias' => ['{b};Prod;{c};UND;5;8;1;0;;si;no;;;Caja;', 'cuántas unidades'],
            'empaque de una unidad' => ['{b};Prod;{c};UND;5;8;1;0;;si;no;;;Caja;1', 'una sola unidad'],
            'código interno con caracteres raros' => ['{b};Prod;{c};UND;5;8;1;0;;si;no;;AB CD/1;;', 'código admite'],
        ];
    }

    #[DataProvider('filasMalas')]
    public function test_rechaza_la_fila_mala_y_dice_por_que(string $plantilla, string $fragmento): void
    {
        $linea = str_replace(['{b}', '{c}'], [$this->unico(), $this->categoria()], $plantilla);
        $antes = Producto::count();

        $r = $this->importar($this->csv([$linea]), aplicar: true);

        $this->assertSame(0, $r['validas']);
        $this->assertFalse($r['aplicado']);
        $this->assertNotEmpty($r['errores'], 'tendría que haberla rechazado');
        $this->assertStringContainsStringIgnoringCase($fragmento, implode(' | ', array_column($r['errores'], 'mensaje')));
        $this->assertSame($antes, Producto::count());
    }

    public function test_rechaza_un_codigo_de_barras_que_ya_existe_en_la_base(): void
    {
        $existente = Producto::whereNotNull('codigo_barras')->firstOrFail();

        $r = $this->importar($this->csv([$existente->codigo_barras.';Otro;'.$this->categoria().';UND;5;8;1;0;;si;no;;;;']));

        $this->assertStringContainsString('ya está asignado', $r['errores'][0]['mensaje']);
    }

    public function test_rechaza_un_codigo_interno_que_ya_existe_en_la_base(): void
    {
        $existente = Producto::firstOrFail();

        $r = $this->importar($this->csv([$this->unico().';Otro;'.$this->categoria().';UND;5;8;1;0;;si;no;;'.$existente->codigo.';;']));

        $this->assertStringContainsString('ya existe en el sistema', $r['errores'][0]['mensaje']);
    }

    public function test_rechaza_repetidos_dentro_del_mismo_archivo(): void
    {
        $barras = $this->unico();

        $r = $this->importar($this->csv([
            $barras.';Primero;'.$this->categoria().';UND;5;8;1;0;;si;no;;;;',
            $barras.';Segundo;'.$this->categoria().';UND;5;8;1;0;;si;no;;;;',
        ]));

        $this->assertSame(1, $r['validas']);
        $this->assertStringContainsString('fila 2 de este archivo', $r['errores'][0]['mensaje']);
        $this->assertSame(3, $r['errores'][0]['fila']);
    }

    // ============================================================ no inventa en silencio

    public function test_no_crea_categorias_ni_proveedores_sin_pedirlo(): void
    {
        $antesC = Categoria::count();
        $antesP = Proveedor::count();

        $r = $this->importar($this->csv([$this->unico().';Prod;Categoría Nueva;UND;5;8;1;0;Proveedor Nuevo SRL;si;no;;;;']), aplicar: true);

        $this->assertFalse($r['aplicado']);
        $this->assertSame($antesC, Categoria::count());
        $this->assertSame($antesP, Proveedor::count());
    }

    public function test_con_crear_categorias_las_crea_una_sola_vez(): void
    {
        $r = $this->importar($this->csv([
            $this->unico().';Prod 1;Categoría Nueva Z;UND;5;8;1;0;Proveedor Nuevo Z SRL;si;no;;;;',
            $this->unico().';Prod 2;categoría  nueva z;UND;5;8;1;0;proveedor nuevo z srl;si;no;;;;',
        ]), aplicar: true, crear: true);

        $this->assertTrue($r['aplicado']);
        $this->assertSame(1, Categoria::where('nombre', 'Categoría Nueva Z')->count());
        $this->assertSame(1, Proveedor::where('razon_social', 'Proveedor Nuevo Z SRL')->count());
        $this->assertSame(2, Producto::whereIn('nombre', ['Prod 1', 'Prod 2'])->count());
    }

    public function test_reconoce_la_categoria_sin_importar_mayusculas_ni_espacios(): void
    {
        $nombre = '  '.mb_strtoupper($this->categoria()).'  ';

        $r = $this->importar($this->csv([$this->unico().';Prod;'.$nombre.';UND;5;8;1;0;;si;no;;;;']));

        $this->assertSame([], $r['errores']);
    }

    // ============================================================ formatos

    public function test_acepta_comas_como_separador_encabezados_con_acentos_y_en_otro_orden(): void
    {
        $cabecera = 'Nombre,Categoría,Unidad,Precio de compra,Precio de venta,Stock';
        $r = $this->importar($this->csv(['Producto coma,'.$this->categoria().',UND,4.5,7,3'], 'csv', $cabecera), aplicar: true);

        $this->assertTrue($r['aplicado']);
        $this->assertEquals(3, (float) Producto::where('nombre', 'Producto coma')->firstOrFail()->stock_actual);
    }

    public function test_acepta_un_csv_de_excel_en_windows_1252(): void
    {
        $ruta = sys_get_temp_dir().'/catalogo-'.bin2hex(random_bytes(4)).'.csv';
        $linea = $this->unico().';Café molido;'.$this->categoria().';UND;10;15;2;1;;si;no;;;;';
        file_put_contents($ruta, mb_convert_encoding(self::CABECERA."\r\n".$linea."\r\n", 'Windows-1252', 'UTF-8'));
        $this->archivos[] = $ruta;

        $this->importar($ruta, aplicar: true);

        $this->assertNotNull(Producto::where('nombre', 'Café molido')->first(), 'la tilde llegó bien');
    }

    public function test_acepta_un_xlsx(): void
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->fromArray(['nombre', 'categoria', 'unidad', 'precio_compra', 'precio_venta', 'stock'], null, 'A1');
        $hoja->fromArray(['Producto excel', $this->categoria(), 'UND', 4.5, 7, 9], null, 'A2');
        $ruta = sys_get_temp_dir().'/catalogo-'.bin2hex(random_bytes(4)).'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $this->archivos[] = $ruta;

        $r = $this->importar($ruta, aplicar: true);

        $this->assertTrue($r['aplicado']);
        $this->assertEquals(9, (float) Producto::where('nombre', 'Producto excel')->firstOrFail()->stock_actual);
    }

    public function test_salta_las_filas_vacias_del_final(): void
    {
        $ruta = $this->csv([
            $this->unico().';Real;'.$this->categoria().';UND;5;8;1;0;;si;no;;;;',
            ';;;;;;;;;;;;;;',
            '',
        ]);

        $r = $this->importar($ruta);

        $this->assertSame(1, $r['filas']);
    }

    // ============================================================ archivos mal armados

    public function test_dice_que_columnas_faltan(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('precio_venta');

        $this->importar($this->csv(['x;y;z'], 'csv', 'nombre;categoria;unidad;precio_compra'));
    }

    public function test_un_archivo_sin_productos_o_de_otro_tipo_se_rechaza(): void
    {
        try {
            $this->importar($this->csv([]));
            $this->fail('un archivo sin productos no es válido');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no tiene productos', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('.csv o .xlsx');
        $this->importar($this->csv(['x'], 'pdf'));
    }

    // ============================================================ el comando

    public function test_la_plantilla_se_vuelve_a_leer_sin_errores(): void
    {
        $ruta = sys_get_temp_dir().'/plantilla-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($ruta, ImportadorCatalogo::plantilla());
        $this->archivos[] = $ruta;

        $r = $this->importar($ruta, crear: true);

        $this->assertSame(2, $r['filas']);
        $this->assertSame([], $r['errores'], json_encode($r['errores']));
    }

    public function test_el_comando_revisa_por_omision_y_carga_con_aplicar(): void
    {
        $ruta = $this->csv([$this->unico().';Producto comando;'.$this->categoria().';UND;5;8;4;0;;si;no;;;;']);

        $this->assertSame(0, Artisan::call('catalogo:importar', ['archivo' => $ruta]));
        $this->assertStringContainsString('No se cargó nada todavía', Artisan::output());
        $this->assertNull(Producto::where('nombre', 'Producto comando')->first());

        $this->assertSame(0, Artisan::call('catalogo:importar', ['archivo' => $ruta, '--aplicar' => true]));
        $this->assertStringContainsString('1 productos cargados', Artisan::output());
        $this->assertNotNull(Producto::where('nombre', 'Producto comando')->first());
    }

    public function test_el_comando_falla_con_un_archivo_con_errores_y_los_muestra(): void
    {
        $ruta = $this->csv([$this->unico().';Mal;'.$this->categoria().';CAJOTE;5;8;1;0;;si;no;;;;']);

        $this->assertSame(1, Artisan::call('catalogo:importar', ['archivo' => $ruta, '--aplicar' => true]));
        $salida = Artisan::output();

        $this->assertStringContainsString('fila 2', $salida);
        $this->assertStringContainsString('No se cargó nada', $salida);
    }

    public function test_el_comando_sin_archivo_orienta(): void
    {
        $this->assertSame(1, Artisan::call('catalogo:importar'));
        $this->assertStringContainsString('--plantilla', Artisan::output());
    }
}
