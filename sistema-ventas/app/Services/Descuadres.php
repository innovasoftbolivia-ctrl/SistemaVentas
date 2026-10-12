<?php

namespace App\Services;

use App\Models\Auditoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lo que no cuadra, revisado todas las noches.
 *
 * La base ya hace casi imposible escribir un descuadre (restricciones, triggers,
 * columnas generadas). Esto es la segunda línea: una consulta, `v_descuadres`, que
 * reúne los invariantes del sistema —stock contra kardex, saltos en la cadena del
 * kardex, stock contra lotes, ventas contra sus pagos, QR pagados sin venta— y
 * que, si devuelve filas, avisa. Un descuadre se descubre en la noche en que
 * ocurre, no meses después en un inventario.
 *
 * Cada revisión deja una fila en la bitácora (también cuando todo cuadra: es la
 * prueba de que la revisión corrió). La portada muestra la última si encontró algo.
 */
class Descuadres
{
    public const ACCION = 'DESCUADRES_REVISADOS';

    /** Cómo se lee cada regla en pantalla. */
    public const REGLAS = [
        'STOCK_DISTINTO_DEL_KARDEX' => 'Stock distinto del último movimiento del kardex',
        'STOCK_SIN_KARDEX' => 'Stock sin ningún movimiento del kardex',
        'KARDEX_CON_SALTO' => 'Kardex con un salto (un movimiento no parte de donde terminó el anterior)',
        'STOCK_DISTINTO_DE_LOS_LOTES' => 'Stock distinto de la suma de sus lotes',
        'VENTA_NO_CUADRA_CON_SUS_PAGOS' => 'Venta cuyos pagos no suman su total',
        'QR_PAGADO_SIN_VENTA' => 'QR pagado hace más de un día sin una venta',
    ];

    /**
     * Las filas que hoy no cuadran.
     *
     * @return Collection<int, object{regla: string, entidad: string, entidad_id: int, detalle: string, esperado: string, actual: string}>
     */
    public static function filas(): Collection
    {
        return collect(DB::select(
            'SELECT regla, entidad, entidad_id, detalle, esperado, actual FROM v_descuadres ORDER BY regla, entidad_id'
        ));
    }

    /**
     * Corre la revisión y deja constancia.
     *
     * @return array{cantidad: int, por_regla: array<string, int>, filas: Collection}
     */
    public static function revisar(): array
    {
        $filas = self::filas();
        $porRegla = $filas->countBy('regla')->all();

        Auditor::registrar(self::ACCION, 'v_descuadres', null, [
            'cantidad' => $filas->count(),
            'por_regla' => $porRegla,
        ]);

        if ($filas->isNotEmpty()) {
            Log::error('Hay descuadres en la base de datos.', [
                'cantidad' => $filas->count(),
                'por_regla' => $porRegla,
                'primeros' => $filas->take(20)->map(fn ($f) => "{$f->regla}: {$f->detalle} (esperado {$f->esperado}, hay {$f->actual})")->all(),
            ]);
        }

        return ['cantidad' => $filas->count(), 'por_regla' => $porRegla, 'filas' => $filas];
    }

    /** La última revisión que se hizo, si hubo alguna. */
    public static function ultima(): ?Auditoria
    {
        return Auditoria::where('accion', self::ACCION)->latest('id')->first();
    }
}
