<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\Usuario;
use App\Support\Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Qué cajero se sale de lo normal.
 *
 * La bitácora y las ventas lo guardan todo, pero nadie las lee a diario. Esto
 * hace la lectura: por cada persona, sus anulaciones, descuentos, devoluciones,
 * egresos de caja, diferencias de arqueo y accesos denegados del período, y
 * marca a quien se aparta del resto.
 *
 * Una marca NO es una acusación: una devolución grande puede ser una caja de
 * mercadería fallada. Es el motivo para mirar, que es lo que hace falta en el
 * minimarket donde nadie tiene tiempo de revisar mil filas.
 *
 * La regla de cada una (anulaciones, descuentos y devoluciones):
 *   - hace falta volumen —{@see MIN_VENTAS} ventas— para que un porcentaje diga algo;
 *   - se marca si supera su piso Y es al menos {@see VECES} veces lo que hace el
 *     resto del equipo;
 *   - si es la única persona, no hay con quién compararla: se usa un piso más alto.
 */
class ControlDeCajeros
{
    /** Ventas mínimas en el período para juzgar un porcentaje. */
    public const MIN_VENTAS = 20;

    /** Cuántas veces el promedio del resto hace falta para marcar. */
    public const VECES = 2.0;

    /** Piso de cada porcentaje con equipo con quién comparar, y piso cuando se trabaja solo. */
    public const PISOS = [
        'anulaciones' => ['equipo' => 0.05, 'solo' => 0.10],
        'descuentos' => ['equipo' => 0.03, 'solo' => 0.08],
        'devoluciones' => ['equipo' => 0.05, 'solo' => 0.10],
    ];

    /** Turnos cerrados con diferencia en el arqueo a partir de los cuales se marca. */
    public const MIN_TURNOS_CON_DIFERENCIA = 2;

    /** Accesos denegados en el período a partir de los cuales se marca. */
    public const MIN_DENEGADOS = 5;

    /**
     * @return array{filas: array<int, array<string, mixed>>, alertas: array<int, array{usuario: string, tipo: string, texto: string}>}
     */
    public static function analizar(Carbon $desde, Carbon $hasta): array
    {
        $datos = [];
        $fila = function (int $id) use (&$datos): void {
            $datos[$id] ??= [
                'ventas' => 0, 'anuladas' => 0, 'vendido' => 0.0, 'descuento' => 0.0, 'base' => 0.0,
                'devoluciones' => 0, 'devuelto' => 0.0,
                'egresos' => 0.0,
                'turnos' => 0, 'turnos_con_diferencia' => 0, 'diferencia' => 0.0,
                'denegados' => 0,
            ];
        };

        foreach (DB::table('ventas')
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw("usuario_id,
                COUNT(*) AS ventas,
                SUM(estado = 'ANULADA') AS anuladas,
                SUM(IF(estado <> 'ANULADA', total, 0)) AS vendido,
                SUM(IF(estado <> 'ANULADA', IF(impuesto_incluido = 1, COALESCE(descuento_precio_final, 0), descuento), 0)) AS descuento,
                SUM(IF(estado <> 'ANULADA', IF(impuesto_incluido = 1, total + COALESCE(descuento_precio_final, 0), subtotal), 0)) AS base")
            ->groupBy('usuario_id')->get() as $v) {
            $fila($v->usuario_id);
            $datos[$v->usuario_id]['ventas'] = (int) $v->ventas;
            $datos[$v->usuario_id]['anuladas'] = (int) $v->anuladas;
            $datos[$v->usuario_id]['vendido'] = (float) $v->vendido;
            $datos[$v->usuario_id]['descuento'] = (float) $v->descuento;
            $datos[$v->usuario_id]['base'] = (float) $v->base;
        }

        foreach (DB::table('devoluciones')->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('usuario_id, COUNT(*) AS n, SUM(total) AS total')->groupBy('usuario_id')->get() as $d) {
            $fila($d->usuario_id);
            $datos[$d->usuario_id]['devoluciones'] = (int) $d->n;
            $datos[$d->usuario_id]['devuelto'] = (float) $d->total;
        }

        // Un egreso que después se anuló no salió de la caja.
        foreach (DB::table('movimientos_caja as m')
            ->where('m.tipo', 'EGRESO')
            ->whereBetween('m.fecha', [$desde, $hasta])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('movimientos_caja as a')->whereColumn('a.anula_a_id', 'm.id'))
            ->selectRaw('m.usuario_id, SUM(m.monto) AS total')->groupBy('m.usuario_id')->get() as $e) {
            $fila($e->usuario_id);
            $datos[$e->usuario_id]['egresos'] = (float) $e->total;
        }

        foreach (DB::table('sesiones_caja')->where('estado', 'CERRADA')->whereBetween('fecha_cierre', [$desde, $hasta])
            ->selectRaw('usuario_apertura_id AS usuario_id, COUNT(*) AS turnos, SUM(diferencia <> 0) AS con_diferencia, SUM(diferencia) AS diferencia')
            ->groupBy('usuario_apertura_id')->get() as $s) {
            $fila($s->usuario_id);
            $datos[$s->usuario_id]['turnos'] = (int) $s->turnos;
            $datos[$s->usuario_id]['turnos_con_diferencia'] = (int) $s->con_diferencia;
            $datos[$s->usuario_id]['diferencia'] = (float) $s->diferencia;
        }

        foreach (Auditoria::where('accion', 'ACCESO_DENEGADO')->whereNotNull('usuario_id')->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('usuario_id, COUNT(*) AS n')->groupBy('usuario_id')->get() as $a) {
            $fila($a->usuario_id);
            $datos[$a->usuario_id]['denegados'] = (int) $a->n;
        }

        $nombres = Usuario::with('empleado:id,nombre_completo')->whereIn('id', array_keys($datos))->get()
            ->mapWithKeys(fn (Usuario $u) => [$u->id => $u->empleado?->nombre_completo ?: $u->usuario]);

        $filas = [];
        foreach ($datos as $id => $d) {
            $filas[$id] = $d + [
                'usuario_id' => $id,
                'nombre' => $nombres[$id] ?? "Usuario #{$id}",
                'pct_anuladas' => $d['ventas'] > 0 ? $d['anuladas'] / $d['ventas'] : 0.0,
                'pct_descuento' => $d['base'] > 0 ? $d['descuento'] / $d['base'] : 0.0,
                'pct_devuelto' => $d['vendido'] > 0 ? $d['devuelto'] / $d['vendido'] : 0.0,
            ];
        }

        uasort($filas, fn ($a, $b) => $b['vendido'] <=> $a['vendido'] ?: strcmp($a['nombre'], $b['nombre']));

        return ['filas' => array_values($filas), 'alertas' => self::alertas($filas)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, array{usuario: string, tipo: string, texto: string}>
     */
    private static function alertas(array $filas): array
    {
        $alertas = [];
        $conVolumen = array_filter($filas, fn ($f) => $f['ventas'] >= self::MIN_VENTAS);

        // Cada porcentaje: de dónde sale el numerador y el denominador.
        $medidas = [
            'anulaciones' => ['anuladas', 'ventas', 'pct_anuladas', fn ($f, $otros) => sprintf(
                'Anuló %d de %d ventas (%s %%); el resto del equipo anula el %s %%.',
                $f['anuladas'], $f['ventas'], self::pct($f['pct_anuladas']), self::pct($otros),
            ), fn ($f) => sprintf('Anuló %d de %d ventas (%s %%).', $f['anuladas'], $f['ventas'], self::pct($f['pct_anuladas']))],
            'descuentos' => ['descuento', 'base', 'pct_descuento', fn ($f, $otros) => sprintf(
                'Dio %s en descuentos (%s %% de lo vendido); el resto del equipo da el %s %%.',
                Config::importe($f['descuento']), self::pct($f['pct_descuento']), self::pct($otros),
            ), fn ($f) => sprintf('Dio %s en descuentos (%s %% de lo vendido).', Config::importe($f['descuento']), self::pct($f['pct_descuento']))],
            'devoluciones' => ['devuelto', 'vendido', 'pct_devuelto', fn ($f, $otros) => sprintf(
                'Devolvió %s (%s %% de lo que vendió); el resto del equipo devuelve el %s %%.',
                Config::importe($f['devuelto']), self::pct($f['pct_devuelto']), self::pct($otros),
            ), fn ($f) => sprintf('Devolvió %s (%s %% de lo que vendió).', Config::importe($f['devuelto']), self::pct($f['pct_devuelto']))],
        ];

        foreach ($medidas as $tipo => [$numerador, $denominador, $porcentaje, $conEquipo, $solo]) {
            foreach ($conVolumen as $id => $f) {
                $otros = array_filter($filas, fn ($o, $oid) => $oid !== $id && $o['ventas'] > 0, ARRAY_FILTER_USE_BOTH);
                $baseOtros = array_sum(array_column($otros, $denominador));
                $pisos = self::PISOS[$tipo];

                if ($otros && $baseOtros > 0) {
                    $tasaOtros = array_sum(array_column($otros, $numerador)) / $baseOtros;
                    $marca = $f[$porcentaje] >= $pisos['equipo'] && $f[$porcentaje] >= self::VECES * $tasaOtros;
                    $texto = $marca ? $conEquipo($f, $tasaOtros) : null;
                } else {
                    $marca = $f[$porcentaje] >= $pisos['solo'];
                    $texto = $marca ? $solo($f) : null;
                }

                if ($marca) {
                    $alertas[] = ['usuario' => $f['nombre'], 'tipo' => $tipo, 'texto' => $texto];
                }
            }
        }

        foreach ($filas as $f) {
            if ($f['turnos_con_diferencia'] >= self::MIN_TURNOS_CON_DIFERENCIA) {
                $alertas[] = [
                    'usuario' => $f['nombre'], 'tipo' => 'arqueos',
                    'texto' => sprintf('%d de %d turnos cerraron con diferencia en el arqueo (neto %s).', $f['turnos_con_diferencia'], $f['turnos'], Config::importe($f['diferencia'])),
                ];
            }

            if ($f['denegados'] >= self::MIN_DENEGADOS) {
                $alertas[] = [
                    'usuario' => $f['nombre'], 'tipo' => 'accesos',
                    'texto' => sprintf('Intentó entrar %d veces a pantallas que su rol no permite.', $f['denegados']),
                ];
            }
        }

        return $alertas;
    }

    private static function pct(float $tasa): string
    {
        return number_format($tasa * 100, 1, '.', '');
    }

    /** Cuántas marcas hay en los últimos `$dias` días: es lo que dice el aviso de la portada. */
    public static function resumenReciente(int $dias = 7): array
    {
        $hasta = now()->endOfDay();
        $desde = now()->subDays($dias - 1)->startOfDay();

        return ['desde' => $desde, 'hasta' => $hasta, 'alertas' => self::analizar($desde, $hasta)['alertas']];
    }
}
