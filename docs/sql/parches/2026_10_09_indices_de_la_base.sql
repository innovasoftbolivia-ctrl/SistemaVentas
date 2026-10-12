-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Índices que sobran y los que faltan (2026-10-09)
--
--  Cada índice cuesta una escritura extra en cada INSERT y UPDATE de su tabla.
--  Los que no ayudan a ninguna consulta se quitan; los que faltan para los
--  reportes se agregan.
--
--  SOBRAN
--    * venta_detalle.ix_detalle_venta (venta_id): es el prefijo de
--      uq_detalle_venta_producto (venta_id, producto_id), que ya sirve para lo
--      mismo y para la llave foránea. Una escritura menos por línea de venta.
--    * clientes.ix_clientes_persona y empleados.ix_empleados_estado: columnas con
--      dos o tres valores distintos, en tablas de decenas de filas: el optimizador
--      nunca los elige.
--    * ventas.ix_ventas_fecha: lo reemplaza ix_ventas_fecha_cubre, que empieza por
--      la misma columna.
--    * productos.ix_productos_activo: lo reemplaza ix_productos_activo_nombre.
--
--  FALTAN
--    * productos (activo, nombre): el catálogo pide «los activos, por nombre».
--    * ventas (fecha, estado, total, impuesto): las sumas por día y por mes se
--      resuelven dentro del índice, sin leer cada fila de la venta.
--    * movimientos_caja (fecha): el reporte de efectivo a caja filtra por rango
--      de fechas, y hoy recorría la tabla entera.
--
--  Idempotente: cada cambio se hace solo si hace falta.
-- =============================================================================

SET NAMES utf8mb4;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND INDEX_NAME = 'ix_productos_activo_nombre');
SET @sql := IF(@falta,
    'ALTER TABLE productos ADD INDEX ix_productos_activo_nombre (activo, nombre)',
    'SELECT ''productos ya tiene ix_productos_activo_nombre'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND INDEX_NAME = 'ix_ventas_fecha_cubre');
SET @sql := IF(@falta,
    'ALTER TABLE ventas ADD INDEX ix_ventas_fecha_cubre (fecha, estado, total, impuesto)',
    'SELECT ''ventas ya tiene ix_ventas_fecha_cubre'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_caja' AND INDEX_NAME = 'ix_movcaja_fecha');
SET @sql := IF(@falta,
    'ALTER TABLE movimientos_caja ADD INDEX ix_movcaja_fecha (fecha)',
    'SELECT ''movimientos_caja ya tiene ix_movcaja_fecha'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sobra := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND INDEX_NAME = 'ix_productos_activo');
SET @sql := IF(@sobra,
    'ALTER TABLE productos DROP INDEX ix_productos_activo',
    'SELECT ''productos ya no tiene ix_productos_activo'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sobra := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND INDEX_NAME = 'ix_ventas_fecha');
SET @sql := IF(@sobra,
    'ALTER TABLE ventas DROP INDEX ix_ventas_fecha',
    'SELECT ''ventas ya no tiene ix_ventas_fecha'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sobra := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle' AND INDEX_NAME = 'ix_detalle_venta');
SET @sql := IF(@sobra,
    'ALTER TABLE venta_detalle DROP INDEX ix_detalle_venta',
    'SELECT ''venta_detalle ya no tiene ix_detalle_venta'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sobra := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND INDEX_NAME = 'ix_clientes_persona');
SET @sql := IF(@sobra,
    'ALTER TABLE clientes DROP INDEX ix_clientes_persona',
    'SELECT ''clientes ya no tiene ix_clientes_persona'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sobra := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empleados' AND INDEX_NAME = 'ix_empleados_estado');
SET @sql := IF(@sobra,
    'ALTER TABLE empleados DROP INDEX ix_empleados_estado',
    'SELECT ''empleados ya no tiene ix_empleados_estado'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND INDEX_NAME IN ('ix_productos_activo_nombre', 'ix_ventas_fecha_cubre', 'ix_movcaja_fecha')
 GROUP BY TABLE_NAME, INDEX_NAME;
