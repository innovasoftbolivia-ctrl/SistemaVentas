-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - El costo con cuatro decimales (2026-10-15)
--
--  El costo por unidad se guardaba al centavo. Una caja de 24 a Bs 70 da
--  2,9166… por unidad: redondeado a 2,92, la compra sumaba 24 × 2,92 = 70,08
--  contra los 70,00 de la factura del proveedor, y cada venta de ese producto
--  arrastraba el mismo error a la ganancia.
--
--  Con cuatro decimales (2,9167) la compra da 70,00 y la ganancia de cada venta
--  se calcula sobre el costo real. Los importes de las líneas (cantidad × costo)
--  siguen redondeándose al centavo, que es lo que se paga y se declara.
--
--  Columnas que pasan de DECIMAL(12,2) a DECIMAL(12,4):
--    productos.precio_compra            el costo de referencia
--    compra_detalle.costo_unitario      lo que dice la factura
--    devolucion_compra_detalle.costo_unitario
--    movimientos_inventario.costo_unitario   el kardex
--    toma_inventario_detalle.costo_unitario  valoriza la diferencia del conteo
--    venta_detalle.costo_unitario       el costo del día de la venta
--
--  Los valores que ya existen no cambian: 4,50 pasa a 4,5000. Nada se redondea
--  hacia atrás. Idempotente: cada columna se cambia solo si todavía tiene 2 decimales.
-- =============================================================================

SET NAMES utf8mb4;

-- productos.precio_compra
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                  AND COLUMN_NAME = 'precio_compra' AND NUMERIC_SCALE = 2);
SET @sql := IF(@viejo > 0,
    'ALTER TABLE productos MODIFY COLUMN precio_compra DECIMAL(12,4) NOT NULL DEFAULT 0.0000',
    'SELECT ''productos.precio_compra ya tiene 4 decimales'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- compra_detalle.costo_unitario (de él depende `importe`, columna generada)
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compra_detalle'
                  AND COLUMN_NAME = 'costo_unitario' AND NUMERIC_SCALE = 2);
SET @sql := IF(@viejo > 0,
    'ALTER TABLE compra_detalle MODIFY COLUMN costo_unitario DECIMAL(12,4) NOT NULL',
    'SELECT ''compra_detalle.costo_unitario ya tiene 4 decimales'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- devolucion_compra_detalle.costo_unitario (también con `importe` generada)
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devolucion_compra_detalle'
                  AND COLUMN_NAME = 'costo_unitario' AND NUMERIC_SCALE = 2);
SET @sql := IF(@viejo > 0,
    'ALTER TABLE devolucion_compra_detalle MODIFY COLUMN costo_unitario DECIMAL(12,4) NOT NULL',
    'SELECT ''devolucion_compra_detalle.costo_unitario ya tiene 4 decimales'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- movimientos_inventario.costo_unitario
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_inventario'
                  AND COLUMN_NAME = 'costo_unitario' AND NUMERIC_SCALE = 2);
SET @sql := IF(@viejo > 0,
    'ALTER TABLE movimientos_inventario MODIFY COLUMN costo_unitario DECIMAL(12,4) NULL',
    'SELECT ''movimientos_inventario.costo_unitario ya tiene 4 decimales'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- toma_inventario_detalle.costo_unitario
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toma_inventario_detalle'
                  AND COLUMN_NAME = 'costo_unitario' AND NUMERIC_SCALE = 2);
SET @sql := IF(@viejo > 0,
    'ALTER TABLE toma_inventario_detalle MODIFY COLUMN costo_unitario DECIMAL(12,4) NULL',
    'SELECT ''toma_inventario_detalle.costo_unitario ya tiene 4 decimales'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- venta_detalle.costo_unitario
SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle'
                  AND COLUMN_NAME = 'costo_unitario' AND NUMERIC_SCALE = 2);
SET @sql := IF(@viejo > 0,
    'ALTER TABLE venta_detalle MODIFY COLUMN costo_unitario DECIMAL(12,4) NULL',
    'SELECT ''venta_detalle.costo_unitario ya tiene 4 decimales'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
