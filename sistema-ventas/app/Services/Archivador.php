<?php

namespace App\Services;

use App\Support\Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * El archivado anual de la bitácora y del kardex.
 *
 * `auditoria` y `movimientos_inventario` solo crecen: no se editan ni se borran, ni
 * siquiera con la cuenta de administración. En un minimarket se vuelven lo más
 * grande de la base a partir del tercer año, y cada respaldo los lleva enteros.
 * Archivar es MOVER lo viejo a una tabla gemela (`*_archivo`), no borrarlo: la
 * historia sigue ahí y se consulta desde las mismas pantallas.
 *
 * Reglas, todas para que nada se pierda ni se descuadre:
 *
 *   - Solo lo que tiene más de un año ({@see MINIMO_DIAS}), salvo `$forzar`.
 *   - Cada lote de filas se COPIA, se comprueba que llegó completo y recién entonces
 *     se borra, todo en una transacción: si algo falla no se pierde ninguna fila.
 *   - Del kardex nunca se mueve el ÚLTIMO movimiento de cada producto: es contra el
 *     que se revisa que el stock cuadre (`v_descuadres`) y la cadena de stock
 *     anterior/resultante. Tampoco el que usa una toma de inventario.
 *   - El borrado lo hace una cuenta con DELETE sobre esas tablas, que `ventas_app`
 *     no tiene; los triggers de borrado dejan pasar solo a una sesión declarada
 *     (`@archivando = 1`), y declararlo sin el privilegio no sirve de nada.
 */
class Archivador
{
    /** Filas por transacción: lotes chicos para no bloquear el mostrador. */
    public const LOTE = 2000;

    /** Filas por transacción de verdad (cambia solo en las pruebas, para ver varios lotes con pocas filas). */
    public static int $lote = self::LOTE;

    /** Lo más reciente que se puede archivar, en días: nada de lo del último año. */
    public const MINIMO_DIAS = 365;

    /** La clave de configuración donde queda hasta cuándo se archivó. */
    public const CLAVE = 'archivado_hasta';

    /** El corte de siempre: el 1 de enero del año pasado, para dejar al menos un año y pico a mano. */
    public static function corteSugerido(): Carbon
    {
        return now()->subYear()->startOfYear();
    }

    /** Hasta cuándo se archivó la última vez, o null si nunca. */
    public static function archivadoHasta(): ?Carbon
    {
        $valor = Config::get(self::CLAVE, '');

        return $valor !== '' ? Carbon::parse($valor)->startOfDay() : null;
    }

    /**
     * @param  string|null  $conexion  la conexión con permiso de borrado (null: la normal)
     * @return array{hasta: Carbon, simulado: bool, auditoria: int, movimientos: int}
     */
    public static function archivar(Carbon $hasta, ?string $conexion = null, bool $simular = false, bool $forzar = false): array
    {
        $hasta = $hasta->copy()->startOfDay();

        if (! $forzar && $hasta->gt(now()->subDays(self::MINIMO_DIAS)->startOfDay())) {
            throw new RuntimeException(
                'Solo se archiva lo que tiene más de un año ('.now()->subDays(self::MINIMO_DIAS)->format('d/m/Y').' o antes): '
                .'lo reciente es lo que se mira todos los días.'
            );
        }

        $db = DB::connection($conexion);
        $resultado = ['hasta' => $hasta, 'simulado' => $simular, 'auditoria' => 0, 'movimientos' => 0];

        foreach (['auditoria' => 'auditoria', 'movimientos_inventario' => 'movimientos'] as $tabla => $clave) {
            $resultado[$clave] = $simular
                ? self::candidatas($db, $tabla, $hasta)->count()
                : self::moverTabla($db, $conexion, $tabla, $hasta);
        }

        if (! $simular && ($resultado['auditoria'] + $resultado['movimientos']) > 0) {
            $anterior = self::archivadoHasta();

            if (! $anterior || $hasta->gt($anterior)) {
                DB::table('configuracion')->updateOrInsert(
                    ['clave' => self::CLAVE],
                    ['valor' => $hasta->toDateString(), 'descripcion' => 'Bitácora y kardex archivados hasta esta fecha (archivar:anual)'],
                );
                Config::olvidar();
            }

            Auditor::registrar('ARCHIVADO', null, null, [
                'hasta' => $hasta->toDateString(),
                'bitacora' => $resultado['auditoria'],
                'kardex' => $resultado['movimientos'],
            ]);
        }

        return $resultado;
    }

    /** Las filas que se pueden archivar de una tabla, de las más viejas a las más nuevas. */
    private static function candidatas(ConnectionInterface $db, string $tabla, Carbon $hasta): Builder
    {
        $q = $db->table($tabla.' as m')->where('m.fecha', '<', $hasta)->orderBy('m.id');

        if ($tabla === 'movimientos_inventario') {
            $q->whereRaw('m.id <> (SELECT MAX(x.id) FROM movimientos_inventario x WHERE x.producto_id = m.producto_id)')
                ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('toma_inventario_detalle as t')->whereColumn('t.movimiento_id', 'm.id'));
        }

        return $q;
    }

    private static function moverTabla(ConnectionInterface $db, ?string $conexion, string $tabla, Carbon $hasta): int
    {
        $columnas = implode(', ', array_map(fn ($c) => "`{$c}`", Schema::connection($conexion)->getColumnListing($tabla)));
        $total = 0;

        // Declara la sesión: sin esto los triggers de borrado no dejan pasar.
        $db->statement('SET @archivando = 1');

        try {
            do {
                $ids = self::candidatas($db, $tabla, $hasta)->limit(self::$lote)->pluck('m.id')->all();

                if ($ids === []) {
                    break;
                }

                $total += $db->transaction(function () use ($db, $tabla, $columnas, $ids) {
                    $marcas = implode(', ', array_fill(0, count($ids), '?'));

                    // IGNORE: si una corrida anterior se cortó entre copiar y borrar, la fila ya está.
                    $db->insert("INSERT IGNORE INTO `{$tabla}_archivo` ({$columnas}) SELECT {$columnas} FROM `{$tabla}` WHERE id IN ({$marcas})", $ids);

                    if ($db->table("{$tabla}_archivo")->whereIn('id', $ids)->count() !== count($ids)) {
                        throw new RuntimeException("El archivo de {$tabla} no recibió todas las filas: no se borra nada.");
                    }

                    return $db->delete("DELETE FROM `{$tabla}` WHERE id IN ({$marcas})", $ids);
                });
            } while (count($ids) === self::$lote);
        } finally {
            $db->statement('SET @archivando = NULL');
        }

        return $total;
    }
}
