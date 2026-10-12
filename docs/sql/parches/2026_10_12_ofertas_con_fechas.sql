-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Ofertas con fechas (2026-10-12)
--
--  Un producto puede tener UNA oferta: un precio rebajado que rige desde un día
--  hasta otro (los dos inclusive) y que se apaga sola al pasar la fecha final.
--  El precio de lista (`precio_venta`) no se toca: es el que vuelve el día
--  siguiente, sin que nadie tenga que acordarse de subirlo.
--
--    precio_oferta   en la misma base que `precio_venta` (con o sin impuesto,
--                    según Configuración); cambiar de modo la convierte igual
--    oferta_desde    primer día de la oferta
--    oferta_hasta    último día de la oferta
--
--  ck_productos_oferta: o no hay oferta (las tres columnas en NULL) o están las
--  tres, con precio no negativo y el final no antes del inicio.
--
--  Una base con los datos viejos no incumple nada: las columnas nuevas nacen en
--  NULL. Idempotente.
-- =============================================================================

SET NAMES utf8mb4;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'precio_oferta');
SET @sql := IF(@falta,
    'ALTER TABLE productos ADD COLUMN precio_oferta DECIMAL(12,2) NULL AFTER precio_venta',
    'SELECT ''productos ya tiene precio_oferta'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'oferta_desde');
SET @sql := IF(@falta,
    'ALTER TABLE productos ADD COLUMN oferta_desde DATE NULL AFTER precio_oferta',
    'SELECT ''productos ya tiene oferta_desde'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'oferta_hasta');
SET @sql := IF(@falta,
    'ALTER TABLE productos ADD COLUMN oferta_hasta DATE NULL AFTER oferta_desde',
    'SELECT ''productos ya tiene oferta_hasta'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND CONSTRAINT_NAME = 'ck_productos_oferta');
SET @sql := IF(@falta,
    'ALTER TABLE productos ADD CONSTRAINT ck_productos_oferta CHECK ((precio_oferta IS NULL AND oferta_desde IS NULL AND oferta_hasta IS NULL) OR (precio_oferta IS NOT NULL AND precio_oferta >= 0 AND oferta_desde IS NOT NULL AND oferta_hasta IS NOT NULL AND oferta_hasta >= oferta_desde))',
    'SELECT ''productos ya tiene ck_productos_oferta'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
