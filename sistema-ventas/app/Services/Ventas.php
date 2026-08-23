<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SerieComprobante;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Models\VentaPago;
use App\Support\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registro y anulación de ventas.
 *
 * Buena parte de las reglas vive en la base y aquí no se repite:
 *   - al insertar una línea, un trigger valida el stock, lo descuenta y
 *     escribe el kardex;
 *   - `sp_recalcular_venta` calcula subtotal e impuesto desde el detalle;
 *   - `sp_emitir_comprobante` toma el correlativo con bloqueo de fila y
 *     congela los datos del cliente;
 *   - `sp_anular_venta` revierte stock, marca la venta y anula el documento.
 *
 * Todo ocurre dentro de una transacción: o se guarda la venta completa, o no
 * se guarda nada (RNF5).
 */
class Ventas
{
    /**
     * @param  array<int, array{producto_id: int, cantidad: float, precio_unitario?: float, descuento?: float}>  $lineas
     * @param  array<int, array{metodo_pago_id: int, monto: float, monto_recibido?: float|null, referencia?: string|null}>  $pagos
     */
    public static function registrar(
        SesionCaja $sesion,
        Usuario $usuario,
        array $lineas,
        array $pagos,
        ?Cliente $cliente = null,
        float $descuento = 0,
        ?string $observacion = null,
    ): Venta {
        if ($lineas === []) {
            throw new RuntimeException('La venta no tiene productos.');
        }

        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('No hay una caja abierta para registrar la venta.');
        }

        return DB::transaction(function () use ($sesion, $usuario, $lineas, $pagos, $cliente, $descuento, $observacion) {
            $venta = Venta::create([
                'cliente_id' => $cliente?->id,
                'usuario_id' => $usuario->id,
                'sesion_caja_id' => $sesion->id,
                'fecha' => now(),
                'descuento' => 0, // se aplica después: la base exige descuento <= subtotal
                'estado' => 'COMPLETADA',
                'observacion' => $observacion,
            ]);

            self::agregarLineas($venta, $lineas);

            // Primer recálculo: deja el subtotal, sin descuento todavía.
            DB::statement('CALL sp_recalcular_venta(?)', [$venta->id]);

            if ($descuento > 0) {
                $venta->refresh();

                if ($descuento > (float) $venta->subtotal) {
                    throw new RuntimeException('El descuento no puede superar el subtotal de la venta.');
                }

                $venta->update(['descuento' => $descuento]);

                // Segundo recálculo: el impuesto baja en proporción al descuento.
                DB::statement('CALL sp_recalcular_venta(?)', [$venta->id]);
            }

            $venta->refresh();

            self::registrarPagos($venta, $pagos);
            self::emitirComprobante($venta, $cliente);

            Auditor::registrar('VENTA_REGISTRADA', 'ventas', $venta->id, [
                'total' => $venta->fresh()->total,
                'lineas' => count($lineas),
                'cliente_id' => $cliente?->id,
            ], $usuario->id);

            return $venta->fresh(['detalle', 'pagos', 'comprobante']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private static function agregarLineas(Venta $venta, array $lineas): void
    {
        foreach ($lineas as $linea) {
            $producto = Producto::findOrFail($linea['producto_id']);

            if (! $producto->activo) {
                throw new RuntimeException("«{$producto->nombre}» está descatalogado y no se puede vender.");
            }

            $cantidad = (float) $linea['cantidad'];

            if ($cantidad <= 0) {
                throw new RuntimeException("La cantidad de «{$producto->nombre}» debe ser mayor que cero.");
            }

            if (! $producto->unidadMedida?->permite_decimal && fmod($cantidad, 1.0) !== 0.0) {
                throw new RuntimeException("«{$producto->nombre}» se vende por unidad entera.");
            }

            if ($cantidad > (float) $producto->stock_actual) {
                throw new RuntimeException(
                    "No hay stock suficiente de «{$producto->nombre}»: quedan ".
                    Config::cantidad($producto->stock_actual).' '.$producto->unidadMedida?->codigo.'.'
                );
            }

            VentaDetalle::create([
                'venta_id' => $venta->id,
                'producto_id' => $producto->id,
                // Copia histórica: la venta no cambia si mañana cambia el catálogo.
                'descripcion' => $producto->nombre,
                'cantidad' => $cantidad,
                'precio_unitario' => $linea['precio_unitario'] ?? $producto->precio_venta,
                'descuento' => $linea['descuento'] ?? 0,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $pagos
     */
    private static function registrarPagos(Venta $venta, array $pagos): void
    {
        if ($pagos === []) {
            throw new RuntimeException('La venta no tiene forma de pago.');
        }

        $total = round((float) $venta->total, 2);

        /*
         * Un pago puede venir sin importe: significa «el resto». El mostrador
         * usa esa forma para el cobro simple, de modo que el total lo pone
         * siempre el servidor y un céntimo de diferencia en el redondeo del
         * navegador no puede tumbar la venta.
         */
        $sinMonto = array_filter($pagos, fn ($p) => ! isset($p['monto']) || $p['monto'] === null || $p['monto'] === '');

        if (count($sinMonto) > 1) {
            throw new RuntimeException('Solo una forma de pago puede quedar sin importe.');
        }

        $explicito = round(array_sum(array_map(
            fn ($p) => (float) ($p['monto'] ?? 0),
            $pagos,
        )), 2);

        if ($sinMonto !== []) {
            $resto = round($total - $explicito, 2);

            if ($resto <= 0) {
                throw new RuntimeException('Las formas de pago indicadas ya cubren el total de la venta.');
            }

            $indice = array_key_first($sinMonto);
            $pagos[$indice]['monto'] = $resto;
            $explicito = $total;
        }

        if ($explicito !== $total) {
            throw new RuntimeException(
                'Lo pagado ('.Config::importe($explicito).') no coincide con el total de la venta ('.
                Config::importe($total).').'
            );
        }

        foreach ($pagos as $pago) {
            $metodo = MetodoPago::findOrFail($pago['metodo_pago_id']);
            $monto = round((float) $pago['monto'], 2);
            $recibido = isset($pago['monto_recibido']) ? round((float) $pago['monto_recibido'], 2) : null;

            if ($monto <= 0) {
                throw new RuntimeException('Cada forma de pago debe tener un monto mayor que cero.');
            }

            // El vuelto solo existe en efectivo: en tarjeta se cobra el importe exacto.
            if (! $metodo->esEfectivo()) {
                $recibido = null;
            } elseif ($recibido !== null && $recibido < $monto) {
                throw new RuntimeException('El efectivo recibido es menor que el importe a cobrar.');
            }

            VentaPago::create([
                'venta_id' => $venta->id,
                'metodo_pago_id' => $metodo->id,
                'monto' => $monto,
                'monto_recibido' => $recibido,
                'referencia' => $pago['referencia'] ?? null,
            ]);
        }
    }

    /**
     * Factura para persona jurídica, recibo para el resto. El tipo lo decide
     * la serie, y un trigger comprueba que corresponda al cliente.
     */
    private static function emitirComprobante(Venta $venta, ?Cliente $cliente): Comprobante
    {
        $serie = self::seriePara($cliente);

        DB::statement('CALL sp_emitir_comprobante(?, ?, @comprobante_id, @numero)', [
            $venta->id, $serie->id,
        ]);

        $id = DB::selectOne('SELECT @comprobante_id AS id')->id;

        if (! $id) {
            throw new RuntimeException('No se pudo emitir el comprobante de la venta.');
        }

        return Comprobante::findOrFail($id);
    }

    /** La serie a usar sale de la configuración del negocio. */
    public static function seriePara(?Cliente $cliente): SerieComprobante
    {
        $clave = $cliente?->esJuridica() ? 'serie_factura' : 'serie_recibo';
        $id = (int) Config::get($clave, '0');

        $serie = SerieComprobante::with('tipo')->find($id);

        if (! $serie) {
            throw new RuntimeException("No hay una serie configurada en «{$clave}».");
        }

        return $serie;
    }

    /**
     * Anular revierte el stock y deja el documento anulado, conservando el
     * correlativo. La venta no se borra nunca (RNF6).
     */
    public static function anular(Venta $venta, Usuario $usuario, string $motivo): Venta
    {
        if (! $venta->puedeAnularse()) {
            throw new RuntimeException('Solo se puede anular una venta completada.');
        }

        // sp_anular_venta ya escribe su propia entrada en `auditoria`.
        DB::statement('CALL sp_anular_venta(?, ?, ?)', [$venta->id, $usuario->id, $motivo]);

        return $venta->fresh();
    }
}
