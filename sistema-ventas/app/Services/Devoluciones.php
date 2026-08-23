<?php

namespace App\Services;

use App\Models\Devolucion;
use App\Models\DevolucionDetalle;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Support\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Devolución de mercadería de una venta ya cobrada.
 *
 * Igual que en las ventas, el trabajo pesado está en la base: al insertar
 * cada línea, `trg_devolucion_detalle_after_insert` acumula lo devuelto en la
 * venta original, recalcula el total de la devolución, mueve la venta a
 * DEVUELTA o DEVUELTA_PARCIAL y —si la mercadería vuelve al estante—
 * reingresa el stock con su movimiento de kardex.
 *
 * Aquí queda lo que la base no puede saber: que no se devuelva más de lo que
 * se vendió, y que el dinero salga de un cajón abierto.
 */
class Devoluciones
{
    /**
     * @param  array<int, array{venta_detalle_id: int, cantidad: float, reingresa_stock?: bool}>  $lineas
     */
    public static function registrar(
        Venta $venta,
        Usuario $usuario,
        SesionCaja $sesion,
        array $lineas,
        string $motivo,
    ): Devolucion {
        if (! $venta->admiteDevolucion()) {
            throw new RuntimeException(match ($venta->estado) {
                'ANULADA' => 'La venta está anulada: su stock y su dinero ya se revirtieron.',
                'DEVUELTA' => 'Esta venta ya fue devuelta por completo.',
                default => 'Esta venta no admite devoluciones.',
            });
        }

        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('Necesitas una caja abierta: el dinero de la devolución sale del cajón.');
        }

        $lineas = self::limpiar($lineas);

        if ($lineas === []) {
            throw new RuntimeException('No se indicó ninguna cantidad a devolver.');
        }

        return DB::transaction(function () use ($venta, $usuario, $sesion, $lineas, $motivo) {
            $devolucion = Devolucion::create([
                'venta_id' => $venta->id,
                'usuario_id' => $usuario->id,
                // Quien registra es quien autoriza: llegar aquí ya exige el
                // permiso `devoluciones.registrar`.
                'autorizado_por' => $usuario->id,
                'sesion_caja_id' => $sesion->id,
                'fecha' => now(),
                // Provisional: se corrige abajo, cuando el trigger ya sabe si
                // quedó algo pendiente en la venta.
                'tipo' => 'PARCIAL',
                'motivo' => $motivo,
            ]);

            foreach ($lineas as $linea) {
                self::agregarLinea($venta, $devolucion, $linea);
            }

            // El trigger deja la venta en DEVUELTA si ya no queda nada por devolver.
            $devolucion->update([
                'tipo' => $venta->fresh()->estado === 'DEVUELTA' ? 'TOTAL' : 'PARCIAL',
            ]);

            $devolucion->refresh();

            // El responsable se pasa explícito: el servicio también se usa
            // fuera de una petición HTTP, donde no hay sesión de la que sacarlo.
            Auditor::registrar('DEVOLUCION_REGISTRADA', 'devoluciones', $devolucion->id, [
                'venta_id' => $venta->id,
                'tipo' => $devolucion->tipo,
                'total' => $devolucion->total,
                'motivo' => $motivo,
            ], $usuario->id);

            return $devolucion->load('detalle.producto');
        });
    }

    /**
     * @param  array{venta_detalle_id: int, cantidad: float, reingresa_stock?: bool}  $linea
     */
    private static function agregarLinea(Venta $venta, Devolucion $devolucion, array $linea): void
    {
        /** @var VentaDetalle $original */
        $original = VentaDetalle::where('venta_id', $venta->id)
            ->whereKey($linea['venta_detalle_id'])
            ->lockForUpdate()
            ->first();

        if (! $original) {
            throw new RuntimeException('Una de las líneas no pertenece a esta venta.');
        }

        $cantidad = round((float) $linea['cantidad'], 3);
        $pendiente = $original->pendiente_devolucion;

        if ($cantidad > $pendiente) {
            throw new RuntimeException(
                "De «{$original->descripcion}» solo quedan ".Config::cantidad($pendiente).
                ' sin devolver.'
            );
        }

        $original->loadMissing('producto.unidadMedida');

        if (! $original->producto?->unidadMedida?->permite_decimal && fmod($cantidad, 1.0) !== 0.0) {
            throw new RuntimeException("«{$original->descripcion}» se devuelve por unidad entera.");
        }

        DevolucionDetalle::create([
            'devolucion_id' => $devolucion->id,
            'venta_detalle_id' => $original->id,
            'producto_id' => $original->producto_id,
            'cantidad' => $cantidad,
            // Se devuelve al precio al que se vendió, no al precio de hoy.
            'precio_unitario' => $original->precio_unitario,
            'reingresa_stock' => $linea['reingresa_stock'] ?? true,
        ]);
    }

    /**
     * Descarta las líneas con cantidad cero: el formulario manda todas las de
     * la venta y el cajero solo llena las que devuelve.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     * @return array<int, array{venta_detalle_id: int, cantidad: float, reingresa_stock?: bool}>
     */
    private static function limpiar(array $lineas): array
    {
        return array_values(array_filter(
            $lineas,
            fn ($l) => isset($l['venta_detalle_id']) && (float) ($l['cantidad'] ?? 0) > 0,
        ));
    }
}
