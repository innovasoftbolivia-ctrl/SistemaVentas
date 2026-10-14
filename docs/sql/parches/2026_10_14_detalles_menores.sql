-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Detalles menores de la auditoría de octubre (2026-10-14)
--
--  1. `usuarios.intentos_fallidos` pasa de TINYINT (máximo 255) a SMALLINT: al
--     intento 256 seguido el ingreso daba error 500 y dejaba de quedar en la
--     bitácora justo cuando más interesa.
--  2. `cobros_qr.moneda` pasa de CHAR(3) a VARCHAR(3), como `comprobantes.moneda`:
--     el mismo dato con dos tipos obligaba a convertir al compararlos.
--
--  Idempotente: cada cambio se hace solo si la columna todavía tiene el tipo viejo.
-- =============================================================================

SET NAMES utf8mb4;

SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'
                  AND COLUMN_NAME = 'intentos_fallidos' AND DATA_TYPE = 'tinyint');
SET @sql := IF(@viejo > 0,
    'ALTER TABLE usuarios MODIFY COLUMN intentos_fallidos SMALLINT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT ''usuarios.intentos_fallidos ya es SMALLINT'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @viejo := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cobros_qr'
                  AND COLUMN_NAME = 'moneda' AND DATA_TYPE = 'char');
SET @sql := IF(@viejo > 0,
    'ALTER TABLE cobros_qr MODIFY COLUMN moneda VARCHAR(3) NOT NULL DEFAULT ''BOB''',
    'SELECT ''cobros_qr.moneda ya es VARCHAR(3)'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
