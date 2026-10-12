-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Vista de descuadres y peso de los QR (2026-10-09)
--
--  1. `v_descuadres`: una sola consulta que reúne los invariantes del sistema.
--     Si devuelve filas, algo no cuadra: stock contra kardex, saltos en la cadena
--     del kardex, stock contra lotes, ventas contra sus pagos, QR pagados sin
--     venta. La corre `php artisan descuadres:revisar` todas las noches, junto
--     con el respaldo.
--
--  2. `cobros_qr.payload` guarda la imagen del QR (unos 33 KB) y solo hace falta
--     mientras el cobro está PENDIENTE. Al pagarse, vencer o anularse se vacía:
--     antes quedaba para siempre, unos 360 MB por año con 30 cobros diarios, y
--     cada respaldo los cargaba. Aquí se vacían los que ya quedaron.
--
--  Idempotente: la vista se reemplaza y el UPDATE no toca lo que ya está vacío.
-- =============================================================================

SET NAMES utf8mb4;

CREATE OR REPLACE VIEW v_descuadres AS
-- 1. El stock de un producto no es el resultado de su último movimiento del kardex.
SELECT 'STOCK_DISTINTO_DEL_KARDEX' AS regla, 'productos' AS entidad, p.id AS entidad_id,
       CONCAT(p.codigo, ' · ', p.nombre) AS detalle,
       m.stock_resultante AS esperado, p.stock_actual AS actual
  FROM productos p
  JOIN movimientos_inventario m
    ON m.id = (SELECT MAX(x.id) FROM movimientos_inventario x WHERE x.producto_id = p.id)
 WHERE m.stock_resultante <> p.stock_actual
UNION ALL
-- 2. Hay stock pero ningún movimiento que lo respalde.
SELECT 'STOCK_SIN_KARDEX', 'productos', p.id, CONCAT(p.codigo, ' · ', p.nombre), 0, p.stock_actual
  FROM productos p
 WHERE p.stock_actual > 0
   AND NOT EXISTS (SELECT 1 FROM movimientos_inventario x WHERE x.producto_id = p.id)
UNION ALL
-- 3. La cadena del kardex tiene un salto: el movimiento no parte de donde terminó el anterior
--    (alguien tocó el stock por fuera, o se perdió un movimiento).
SELECT 'KARDEX_CON_SALTO', 'movimientos_inventario', c.id, CONCAT('Movimiento #', c.id, ' del producto #', c.producto_id),
       c.previo, c.stock_anterior
  FROM (SELECT m.id, m.producto_id, m.stock_anterior,
               LAG(m.stock_resultante) OVER (PARTITION BY m.producto_id ORDER BY m.id) AS previo
          FROM movimientos_inventario m) c
 WHERE c.previo IS NOT NULL AND c.previo <> c.stock_anterior
UNION ALL
-- 4. Un producto que se controla por vencimiento: su stock tiene que ser la suma de sus lotes.
SELECT 'STOCK_DISTINTO_DE_LOS_LOTES', 'productos', p.id, CONCAT(p.codigo, ' · ', p.nombre),
       COALESCE(SUM(l.cantidad_actual), 0), p.stock_actual
  FROM productos p
  LEFT JOIN lotes l ON l.producto_id = p.id
 WHERE p.controla_vencimiento = 1
 GROUP BY p.id, p.codigo, p.nombre, p.stock_actual
HAVING COALESCE(SUM(l.cantidad_actual), 0) <> p.stock_actual
UNION ALL
-- 5. Lo cobrado no coincide con el total de la venta.
SELECT 'VENTA_NO_CUADRA_CON_SUS_PAGOS', 'ventas', v.id, CONCAT('Venta #', v.id, ' del ', DATE(v.fecha)),
       v.total, COALESCE(SUM(vp.monto), 0)
  FROM ventas v
  LEFT JOIN venta_pagos vp ON vp.venta_id = v.id
 WHERE v.estado <> 'ANULADA'
 GROUP BY v.id, v.fecha, v.total
HAVING ROUND(COALESCE(SUM(vp.monto), 0), 2) <> v.total
UNION ALL
-- 6. Un QR que el cliente pagó hace más de un día y que nunca terminó en una venta:
--    el dinero entró al banco y no hay venta que lo respalde.
SELECT 'QR_PAGADO_SIN_VENTA', 'cobros_qr', c.id, CONCAT('QR #', c.id, ' pagado el ', DATE(c.pagado_en)), c.monto, 0
  FROM cobros_qr c
 WHERE c.estado = 'PAGADO' AND c.venta_id IS NULL AND c.pagado_en < NOW() - INTERVAL 1 DAY;

UPDATE cobros_qr SET payload = NULL WHERE estado <> 'PENDIENTE' AND payload IS NOT NULL;

SELECT COUNT(*) AS descuadres_hoy FROM v_descuadres;
