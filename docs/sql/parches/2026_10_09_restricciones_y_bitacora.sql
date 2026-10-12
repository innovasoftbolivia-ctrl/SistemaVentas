-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Restricciones, turno abierto y bitácora inmutable (2026-10-09)
--
--  Un descuadre se vuelve IMPOSIBLE de escribir, en vez de descubrirse meses
--  después.
--
--  1. RESTRICCIONES (CHECK)
--       ck_movinv_cadena         cada movimiento del kardex cumple
--                                stock_resultante = stock_anterior ± cantidad
--       ck_devol_total           una devolución no puede tener total negativo
--       ck_ventas_anulacion      una venta ANULADA lleva fecha, responsable y motivo,
--                                y una que no lo está, no
--       ck_sesion_cierre         un turno CERRADO tiene fecha y quién lo cerró;
--                                uno ABIERTO no
--       ck_comprobante_anulado   un comprobante ANULADO lleva su fecha
--     Si alguna fila de una base vieja las incumple, el parche SE DETIENE con un
--     mensaje que dice cuántas son (una restricción a medias es peor que ninguna).
--
--  2. TURNO ABIERTO
--     Las ventas ya solo entraban en un turno abierto. Ahora también las
--     devoluciones y los movimientos de caja: ningún movimiento de dinero queda
--     fuera de un arqueo ni se agrega a uno que ya se firmó.
--
--  3. BITÁCORA INMUTABLE
--     `auditoria` y `movimientos_inventario` no se editan ni se borran, ni
--     siquiera con la cuenta de administración: son la prueba de lo que pasó.
--     Un error del kardex se corrige con un movimiento nuevo.
--
--  Idempotente.
-- =============================================================================

SET NAMES utf8mb4;

-- ---- 1. Restricciones -------------------------------------------------------

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_inventario' AND CONSTRAINT_NAME = 'ck_movinv_cadena');
SET @sql := IF(@falta, CONCAT('SELECT COUNT(*) INTO @mal FROM movimientos_inventario WHERE (', 'stock_anterior >= 0 AND stock_resultante >= 0 AND ((tipo = ''ENTRADA'' AND stock_resultante = stock_anterior + cantidad) OR (tipo = ''SALIDA'' AND stock_resultante = stock_anterior - cantidad) OR (tipo = ''AJUSTE'' AND ABS(stock_resultante - stock_anterior) = cantidad))', ') IS FALSE'), 'SET @mal := 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(@falta AND @mal > 0,
    CONCAT('SELECT 1 FROM `DETENIDO: ', @mal, ' fila(s) incumplen ck_movinv_cadena`'),
    IF(@falta,
        'ALTER TABLE movimientos_inventario ADD CONSTRAINT ck_movinv_cadena CHECK (stock_anterior >= 0 AND stock_resultante >= 0 AND ((tipo = ''ENTRADA'' AND stock_resultante = stock_anterior + cantidad) OR (tipo = ''SALIDA'' AND stock_resultante = stock_anterior - cantidad) OR (tipo = ''AJUSTE'' AND ABS(stock_resultante - stock_anterior) = cantidad)))',
        'SELECT ''movimientos_inventario ya tiene ck_movinv_cadena'' AS aviso'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devoluciones' AND CONSTRAINT_NAME = 'ck_devol_total');
SET @sql := IF(@falta, CONCAT('SELECT COUNT(*) INTO @mal FROM devoluciones WHERE (', 'total >= 0', ') IS FALSE'), 'SET @mal := 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(@falta AND @mal > 0,
    CONCAT('SELECT 1 FROM `DETENIDO: ', @mal, ' fila(s) incumplen ck_devol_total`'),
    IF(@falta,
        'ALTER TABLE devoluciones ADD CONSTRAINT ck_devol_total CHECK (total >= 0)',
        'SELECT ''devoluciones ya tiene ck_devol_total'' AS aviso'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND CONSTRAINT_NAME = 'ck_ventas_anulacion');
SET @sql := IF(@falta, CONCAT('SELECT COUNT(*) INTO @mal FROM ventas WHERE (', '(estado = ''ANULADA'' AND anulada_en IS NOT NULL AND anulada_por IS NOT NULL AND motivo_anulacion IS NOT NULL) OR (estado <> ''ANULADA'' AND anulada_en IS NULL)', ') IS FALSE'), 'SET @mal := 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(@falta AND @mal > 0,
    CONCAT('SELECT 1 FROM `DETENIDO: ', @mal, ' fila(s) incumplen ck_ventas_anulacion`'),
    IF(@falta,
        'ALTER TABLE ventas ADD CONSTRAINT ck_ventas_anulacion CHECK ((estado = ''ANULADA'' AND anulada_en IS NOT NULL AND anulada_por IS NOT NULL AND motivo_anulacion IS NOT NULL) OR (estado <> ''ANULADA'' AND anulada_en IS NULL))',
        'SELECT ''ventas ya tiene ck_ventas_anulacion'' AS aviso'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesiones_caja' AND CONSTRAINT_NAME = 'ck_sesion_cierre');
SET @sql := IF(@falta, CONCAT('SELECT COUNT(*) INTO @mal FROM sesiones_caja WHERE (', '(estado = ''CERRADA'' AND fecha_cierre IS NOT NULL AND usuario_cierre_id IS NOT NULL) OR (estado = ''ABIERTA'' AND fecha_cierre IS NULL)', ') IS FALSE'), 'SET @mal := 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(@falta AND @mal > 0,
    CONCAT('SELECT 1 FROM `DETENIDO: ', @mal, ' fila(s) incumplen ck_sesion_cierre`'),
    IF(@falta,
        'ALTER TABLE sesiones_caja ADD CONSTRAINT ck_sesion_cierre CHECK ((estado = ''CERRADA'' AND fecha_cierre IS NOT NULL AND usuario_cierre_id IS NOT NULL) OR (estado = ''ABIERTA'' AND fecha_cierre IS NULL))',
        'SELECT ''sesiones_caja ya tiene ck_sesion_cierre'' AS aviso'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comprobantes' AND CONSTRAINT_NAME = 'ck_comprobante_anulado');
SET @sql := IF(@falta, CONCAT('SELECT COUNT(*) INTO @mal FROM comprobantes WHERE (', 'estado <> ''ANULADO'' OR anulado_en IS NOT NULL', ') IS FALSE'), 'SET @mal := 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(@falta AND @mal > 0,
    CONCAT('SELECT 1 FROM `DETENIDO: ', @mal, ' fila(s) incumplen ck_comprobante_anulado`'),
    IF(@falta,
        'ALTER TABLE comprobantes ADD CONSTRAINT ck_comprobante_anulado CHECK (estado <> ''ANULADO'' OR anulado_en IS NOT NULL)',
        'SELECT ''comprobantes ya tiene ck_comprobante_anulado'' AS aviso'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---- 2. Turno abierto ---------------------------------------------------------

DROP TRIGGER IF EXISTS trg_devoluciones_before_insert;

DELIMITER $$

CREATE TRIGGER trg_devoluciones_before_insert
BEFORE INSERT ON devoluciones
FOR EACH ROW
BEGIN
    IF NEW.sesion_caja_id IS NULL OR NOT EXISTS (SELECT 1 FROM sesiones_caja
                                                  WHERE id = NEW.sesion_caja_id AND estado = 'ABIERTA') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La devolución debe registrarse en un turno de caja abierto';
    END IF;
END$$

DELIMITER ;

DROP TRIGGER IF EXISTS trg_movimientos_caja_before_insert;

DELIMITER $$

CREATE TRIGGER trg_movimientos_caja_before_insert
BEFORE INSERT ON movimientos_caja
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM sesiones_caja
                    WHERE id = NEW.sesion_caja_id AND estado = 'ABIERTA') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El movimiento de caja debe registrarse en un turno de caja abierto';
    END IF;
END$$

DELIMITER ;

-- ---- 3. Bitácora inmutable ----------------------------------------------------

DROP TRIGGER IF EXISTS trg_auditoria_before_update;

DELIMITER $$

CREATE TRIGGER trg_auditoria_before_update
BEFORE UPDATE ON auditoria
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La bitácora no se edita: lo que pasó, pasó.';
END$$

DELIMITER ;

DROP TRIGGER IF EXISTS trg_auditoria_before_delete;

DELIMITER $$

CREATE TRIGGER trg_auditoria_before_delete
BEFORE DELETE ON auditoria
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La bitácora no se borra: lo que pasó, pasó.';
END$$

DELIMITER ;

DROP TRIGGER IF EXISTS trg_movimientos_inventario_before_update;

DELIMITER $$

CREATE TRIGGER trg_movimientos_inventario_before_update
BEFORE UPDATE ON movimientos_inventario
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El kardex no se edita: un error se corrige con un movimiento nuevo.';
END$$

DELIMITER ;

DROP TRIGGER IF EXISTS trg_movimientos_inventario_before_delete;

DELIMITER $$

CREATE TRIGGER trg_movimientos_inventario_before_delete
BEFORE DELETE ON movimientos_inventario
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El kardex no se borra: un error se corrige con un movimiento nuevo.';
END$$

DELIMITER ;

SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
 WHERE TRIGGER_SCHEMA = DATABASE()
   AND TRIGGER_NAME IN ('trg_devoluciones_before_insert', 'trg_movimientos_caja_before_insert',
                        'trg_auditoria_before_update', 'trg_auditoria_before_delete',
                        'trg_movimientos_inventario_before_update', 'trg_movimientos_inventario_before_delete')
 ORDER BY TRIGGER_NAME;
