-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Archivado anual de la bitácora y del kardex (2026-10-16)
--
--  La bitácora (`auditoria`) y el kardex (`movimientos_inventario`) solo crecen:
--  no se editan ni se borran, ni siquiera con la cuenta de administración. En un
--  minimarket se vuelven lo más grande de la base a partir del tercer año, y cada
--  respaldo los lleva enteros.
--
--  Archivar es MOVER lo viejo a una tabla gemela, no borrarlo:
--
--    auditoria                -> auditoria_archivo
--    movimientos_inventario   -> movimientos_inventario_archivo
--
--  Las tablas del archivo tienen las mismas columnas más `archivado_en`, y son tan
--  inmutables como las originales: ni se editan ni se borran.
--
--  El único camino para mover filas es el comando `archivar:anual`, con una cuenta
--  de MySQL que tenga DELETE sobre esas dos tablas (`ventas_app` no lo tiene, a
--  propósito). Los triggers de BORRADO de las originales dejan pasar el borrado solo
--  si la sesión se declaró archivando (`@archivando = 1`); declararlo sin tener el
--  privilegio DELETE no sirve de nada.
--
--  Idempotente: las tablas y los triggers se crean o se reemplazan.
-- =============================================================================

SET NAMES utf8mb4;

-- ---- Las tablas del archivo -------------------------------------------------

CREATE TABLE IF NOT EXISTS auditoria_archivo LIKE auditoria;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria_archivo' AND COLUMN_NAME = 'archivado_en');
SET @sql := IF(@falta,
    'ALTER TABLE auditoria_archivo ADD COLUMN archivado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'SELECT ''auditoria_archivo ya tiene archivado_en'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS movimientos_inventario_archivo LIKE movimientos_inventario;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_inventario_archivo' AND COLUMN_NAME = 'archivado_en');
SET @sql := IF(@falta,
    'ALTER TABLE movimientos_inventario_archivo ADD COLUMN archivado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'SELECT ''movimientos_inventario_archivo ya tiene archivado_en'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---- El archivo no se edita ni se borra --------------------------------------

DROP TRIGGER IF EXISTS trg_auditoria_archivo_before_update;
DROP TRIGGER IF EXISTS trg_auditoria_archivo_before_delete;
DROP TRIGGER IF EXISTS trg_movimientos_inventario_archivo_before_update;
DROP TRIGGER IF EXISTS trg_movimientos_inventario_archivo_before_delete;

DELIMITER $$

CREATE TRIGGER trg_auditoria_archivo_before_update
BEFORE UPDATE ON auditoria_archivo
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El archivo de la bitácora no se edita.';
END$$

CREATE TRIGGER trg_auditoria_archivo_before_delete
BEFORE DELETE ON auditoria_archivo
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El archivo de la bitácora no se borra.';
END$$

CREATE TRIGGER trg_movimientos_inventario_archivo_before_update
BEFORE UPDATE ON movimientos_inventario_archivo
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El archivo del kardex no se edita.';
END$$

CREATE TRIGGER trg_movimientos_inventario_archivo_before_delete
BEFORE DELETE ON movimientos_inventario_archivo
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El archivo del kardex no se borra.';
END$$

DELIMITER ;

-- ---- Las originales dejan pasar el borrado SOLO al archivar ------------------

DROP TRIGGER IF EXISTS trg_auditoria_before_delete;
DROP TRIGGER IF EXISTS trg_movimientos_inventario_before_delete;

DELIMITER $$

CREATE TRIGGER trg_auditoria_before_delete
BEFORE DELETE ON auditoria
FOR EACH ROW
BEGIN
    IF COALESCE(@archivando, 0) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La bitácora no se borra: lo que pasó, pasó. (Lo viejo se archiva con archivar:anual.)';
    END IF;
END$$

CREATE TRIGGER trg_movimientos_inventario_before_delete
BEFORE DELETE ON movimientos_inventario
FOR EACH ROW
BEGIN
    IF COALESCE(@archivando, 0) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El kardex no se borra: un error se corrige con un movimiento nuevo. (Lo viejo se archiva con archivar:anual.)';
    END IF;
END$$

DELIMITER ;
