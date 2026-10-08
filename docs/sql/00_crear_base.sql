-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Script 00 - Crear la base (si no existe)
--
--  No borra nada: CREATE DATABASE IF NOT EXISTS. Va antes de 01_schema_mysql.sql
--  en la primera carga. Para borrar y volver a crear una base de DESARROLLO o de
--  pruebas, ver desarrollo/borrar_y_crear_base_DESTRUCTIVO.sql.
-- =============================================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS ventas_db
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_0900_ai_ci;
