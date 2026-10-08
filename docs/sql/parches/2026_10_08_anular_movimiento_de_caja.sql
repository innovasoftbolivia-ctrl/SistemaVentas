-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Anular un movimiento de caja (2026-10-08)
--
--  Un egreso de Bs 500 tecleado en vez de Bs 50 solo se corregía con otro
--  movimiento «compensatorio» escrito a mano, sin ninguna relación con el
--  original, y el arqueo firmado quedaba ilegible: dos líneas sueltas que
--  había que adivinar que se anulaban entre sí.
--
--  Ahora la corrección es un contra-asiento ENLAZADO: el movimiento original no
--  se toca ni se borra (el dinero que entró o salió quedó escrito), y su
--  anulación apunta a él con `anula_a_id`. La base garantiza que un movimiento
--  se anula UNA sola vez (índice único sobre `anula_a_id`) y que apunta a uno
--  que existe (llave foránea). Que sea del mismo turno, que no sea ya una
--  anulación y quién puede hacerlo lo decide `Cajas::anularMovimiento` (MySQL
--  no admite un CHECK sobre la columna autoincremental `id`).
--
--  El efectivo esperado no cambia de fórmula: el contra-asiento suma o resta
--  como cualquier otro ingreso o egreso, así que los totales siguen cuadrando.
--
--  Idempotente: cada pieza se agrega solo si falta.
-- =============================================================================

SET NAMES utf8mb4;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_caja' AND COLUMN_NAME = 'anula_a_id');
SET @sql := IF(@falta,
    'ALTER TABLE movimientos_caja ADD COLUMN anula_a_id INT UNSIGNED NULL AFTER monto',
    'SELECT ''movimientos_caja ya tiene anula_a_id'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_caja' AND INDEX_NAME = 'uq_movcaja_anula');
SET @sql := IF(@falta,
    'ALTER TABLE movimientos_caja ADD UNIQUE KEY uq_movcaja_anula (anula_a_id)',
    'SELECT ''movimientos_caja ya tiene uq_movcaja_anula'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_caja' AND CONSTRAINT_NAME = 'fk_movcaja_anula');
SET @sql := IF(@falta,
    'ALTER TABLE movimientos_caja ADD CONSTRAINT fk_movcaja_anula FOREIGN KEY (anula_a_id) REFERENCES movimientos_caja (id)',
    'SELECT ''movimientos_caja ya tiene fk_movcaja_anula'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_caja' AND COLUMN_NAME = 'anula_a_id';
