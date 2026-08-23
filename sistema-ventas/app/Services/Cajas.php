<?php

namespace App\Services;

use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\SesionCaja;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Apertura, movimientos y cierre del turno de caja.
 *
 * El arqueo lo calcula `sp_cerrar_caja` en la base: es la misma fórmula para
 * todos y no depende de que la aplicación la recuerde bien.
 */
class Cajas
{
    /** La sesión abierta del usuario, si la tiene. */
    public static function sesionDe(Usuario $usuario): ?SesionCaja
    {
        return SesionCaja::abiertas()
            ->where('usuario_apertura_id', $usuario->id)
            ->with('caja')
            ->first();
    }

    public static function abrir(Caja $caja, Usuario $usuario, float $montoInicial, ?string $observacion = null): SesionCaja
    {
        if ($montoInicial < 0) {
            throw new RuntimeException('El monto inicial no puede ser negativo.');
        }

        if (self::sesionDe($usuario)) {
            throw new RuntimeException('Ya tienes una caja abierta. Ciérrala antes de abrir otra.');
        }

        // La base tiene un índice único que impide dos sesiones abiertas en la
        // misma caja; aquí se traduce a un mensaje entendible.
        if ($caja->sesionAbierta()->exists()) {
            throw new RuntimeException("La {$caja->nombre} ya está abierta por otro usuario.");
        }

        $sesion = SesionCaja::create([
            'caja_id' => $caja->id,
            'usuario_apertura_id' => $usuario->id,
            'fecha_apertura' => now(),
            'monto_inicial' => $montoInicial,
            'estado' => 'ABIERTA',
            'observacion' => $observacion,
        ]);

        Auditor::registrar('CAJA_ABIERTA', 'sesiones_caja', $sesion->id, [
            'caja' => $caja->nombre,
            'monto_inicial' => $montoInicial,
        ], $usuario->id);

        return $sesion;
    }

    public static function movimiento(
        SesionCaja $sesion,
        Usuario $usuario,
        string $tipo,
        string $concepto,
        float $monto,
    ): MovimientoCaja {
        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('La caja ya está cerrada: no admite más movimientos.');
        }

        if ($monto <= 0) {
            throw new RuntimeException('El monto del movimiento debe ser mayor que cero.');
        }

        $movimiento = MovimientoCaja::create([
            'sesion_caja_id' => $sesion->id,
            'usuario_id' => $usuario->id,
            'tipo' => $tipo,
            'concepto' => $concepto,
            'monto' => $monto,
            'fecha' => now(),
        ]);

        Auditor::registrar('CAJA_MOVIMIENTO', 'movimientos_caja', $movimiento->id, [
            'tipo' => $tipo,
            'concepto' => $concepto,
            'monto' => $monto,
        ], $usuario->id);

        return $movimiento;
    }

    /**
     * Cierra el turno con el efectivo contado. El procedimiento calcula el
     * esperado y la base deriva la diferencia.
     */
    public static function cerrar(
        SesionCaja $sesion,
        Usuario $usuario,
        float $declarado,
        ?string $observacion = null,
    ): SesionCaja {
        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('Esta caja ya fue cerrada.');
        }

        if ($declarado < 0) {
            throw new RuntimeException('El efectivo contado no puede ser negativo.');
        }

        // `DB::select` y no `DB::statement`: el procedimiento termina con un
        // SELECT del arqueo, y ese resultado hay que consumirlo o la siguiente
        // consulta de la conexión falla.
        DB::select('CALL sp_cerrar_caja(?, ?, ?, ?)', [
            $sesion->id, $usuario->id, $declarado, $observacion,
        ]);

        $sesion->refresh();

        Auditor::registrar('CAJA_CERRADA', 'sesiones_caja', $sesion->id, [
            'esperado' => $sesion->monto_esperado,
            'declarado' => $sesion->monto_declarado,
            'diferencia' => $sesion->diferencia,
        ], $usuario->id);

        return $sesion;
    }
}
