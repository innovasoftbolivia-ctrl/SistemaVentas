<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * La bitácora, en un Excel: la que se le entrega a quien audita o se guarda como
 * prueba de un incidente. Mismos filtros que la pantalla.
 */
class BitacoraExcelTest extends TestCase
{
    use DatabaseTransactions;

    private function u(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    /** @param  array<string, mixed>  $datos */
    private function registro(array $datos): Auditoria
    {
        return Auditoria::create(array_merge([
            'usuario_id' => $this->u('admin')->id, 'accion' => 'PRODUCTO_ACTUALIZADO', 'entidad' => 'productos',
            'entidad_id' => 1, 'detalle' => null, 'ip' => '10.0.0.5', 'fecha' => now(),
        ], $datos));
    }

    /** Las filas de la tabla, sin las celdas de cabecera del negocio. */
    private function filas(array $parametros = []): Collection
    {
        $respuesta = $this->actingAs($this->u('admin'))->get(route('bitacora.excel', $parametros))->assertOk();
        $temporal = tempnam(sys_get_temp_dir(), 'bit').'.xlsx';
        file_put_contents($temporal, $respuesta->streamedContent());

        try {
            return collect(IOFactory::load($temporal)->getAllSheets())
                ->flatMap(fn ($hoja) => $hoja->toArray(null, true, false, false))
                ->map(fn ($fila) => array_values(array_filter($fila, fn ($c) => $c !== null && $c !== '')))
                ->filter(fn ($fila) => $fila !== [])
                ->values();
        } finally {
            @unlink($temporal);
        }
    }

    private function celdas(Collection $filas): Collection
    {
        return $filas->flatten()->values();
    }

    public function test_solo_quien_ve_la_bitacora_la_descarga(): void
    {
        $this->actingAs($this->u('cajero1'))->get(route('bitacora.excel'))->assertForbidden();
        $this->actingAs($this->u('almacen'))->get(route('bitacora.excel'))->assertForbidden();
        $this->actingAs($this->u('admin'))->get(route('bitacora.excel'))->assertOk();
    }

    public function test_el_excel_lleva_quien_cuando_que_y_el_detalle(): void
    {
        $this->registro([
            'accion' => 'PRODUCTO_ACTUALIZADO', 'entidad_id' => 77, 'ip' => '192.168.1.9',
            'detalle' => ['codigo' => 'P-77', 'cambios' => ['precio venta' => ['antes' => '8.00', 'despues' => '9.50']]],
        ]);

        $celdas = $this->celdas($this->filas(['buscar' => 'P-77']));

        $this->assertTrue($celdas->contains('admin'));
        $this->assertTrue($celdas->contains('192.168.1.9'));
        $this->assertTrue($celdas->contains('Producto actualizado'));
        $this->assertTrue($celdas->contains('Producto #77'));
        $this->assertTrue($celdas->contains(fn ($c) => is_string($c) && str_contains($c, 'cambió precio venta: 8.00 → 9.50')));
        $this->assertTrue($celdas->contains(fn ($c) => is_string($c) && preg_match('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}:\d{2}$#', $c) === 1), 'la fecha con hora');
    }

    public function test_respeta_los_mismos_filtros_que_la_pantalla(): void
    {
        $this->registro(['accion' => 'CAMBIO_PRECIO', 'entidad_id' => 501]);
        $this->registro(['accion' => 'VENTA_REGISTRADA', 'entidad' => 'ventas', 'entidad_id' => 502]);
        $this->registro(['accion' => 'CAMBIO_PRECIO', 'entidad_id' => 503, 'usuario_id' => $this->u('cajero1')->id]);
        $this->registro(['accion' => 'CAMBIO_PRECIO', 'entidad_id' => 504, 'fecha' => now()->subDays(40)]);

        $porAccion = $this->celdas($this->filas(['accion' => 'CAMBIO_PRECIO']));
        $this->assertTrue($porAccion->contains('Producto #501'));
        $this->assertFalse($porAccion->contains('Venta #502'));

        $porUsuario = $this->celdas($this->filas(['usuario' => $this->u('cajero1')->id, 'accion' => 'CAMBIO_PRECIO']));
        $this->assertTrue($porUsuario->contains('Producto #503'));
        $this->assertFalse($porUsuario->contains('Producto #501'));

        $porFecha = $this->celdas($this->filas(['accion' => 'CAMBIO_PRECIO', 'desde' => now()->subDays(5)->toDateString()]));
        $this->assertTrue($porFecha->contains('Producto #501'));
        $this->assertFalse($porFecha->contains('Producto #504'), 'el de hace 40 días queda fuera');

        $porTexto = $this->celdas($this->filas(['buscar' => '502']));
        $this->assertTrue($porTexto->contains('Venta #502'));
        $this->assertFalse($porTexto->contains('Producto #501'));
    }

    public function test_va_de_lo_mas_nuevo_a_lo_mas_viejo(): void
    {
        $this->registro(['entidad_id' => 601, 'fecha' => now()->subDays(2)]);
        $this->registro(['entidad_id' => 602, 'fecha' => now()->subDay()]);
        $this->registro(['entidad_id' => 603, 'fecha' => now()]);

        $ids = $this->celdas($this->filas(['accion' => 'PRODUCTO_ACTUALIZADO']))
            ->filter(fn ($c) => is_string($c) && preg_match('/^Producto #60\d$/', $c))->values()->all();

        $this->assertSame(['Producto #603', 'Producto #602', 'Producto #601'], $ids);
    }

    public function test_un_rango_al_reves_se_rechaza_igual_que_en_la_pantalla(): void
    {
        $this->actingAs($this->u('admin'))
            ->get(route('bitacora.excel', ['desde' => '2026-10-10', 'hasta' => '2026-10-01']))
            ->assertSessionHasErrors('hasta');
    }

    public function test_si_hay_mas_filas_que_el_tope_avisa_en_vez_de_descargar(): void
    {
        config(['ventas.excel_max_filas' => 3]);
        foreach (range(1, 5) as $i) {
            $this->registro(['accion' => 'CAMBIO_PRECIO', 'entidad_id' => 700 + $i]);
        }

        $this->actingAs($this->u('admin'))->get(route('bitacora.excel', ['accion' => 'CAMBIO_PRECIO']))
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_descargar_la_bitacora_queda_anotado_en_la_bitacora(): void
    {
        $this->actingAs($this->u('admin'))->get(route('bitacora.excel'))->assertOk();

        $registro = Auditoria::where('accion', 'REPORTE_EXPORTADO')->latest('id')->firstOrFail();
        $this->assertSame('Bitácora de operaciones', $registro->detalle['reporte']);
        $this->assertSame('Excel', $registro->detalle['formato']);
    }

    public function test_la_pantalla_ofrece_la_descarga_con_los_filtros_puestos(): void
    {
        $this->actingAs($this->u('admin'))->get(route('bitacora.index', ['accion' => 'CAMBIO_PRECIO', 'buscar' => '12']))->assertOk()
            ->assertSee('Descargar Excel')
            ->assertSee('bitacora/excel?accion=CAMBIO_PRECIO&amp;buscar=12', false);
    }

    public function test_la_pantalla_sigue_filtrando_igual_despues_de_compartir_los_filtros(): void
    {
        $this->registro(['accion' => 'CAMBIO_PRECIO', 'entidad_id' => 801]);
        $this->registro(['accion' => 'VENTA_REGISTRADA', 'entidad' => 'ventas', 'entidad_id' => 802]);

        $this->actingAs($this->u('admin'))->get(route('bitacora.index', ['accion' => 'CAMBIO_PRECIO']))->assertOk()
            ->assertSee('Producto #801')->assertDontSee('Venta #802');
    }
}
