-- =============================================================================
--  !!  DESTRUCTIVO  !!   BORRA LA BASE `ventas_db` ENTERA Y LA VUELVE A CREAR
--
--  Solo para DESARROLLO y PRUEBAS. Nunca en el servidor de un cliente: ahí no hay
--  forma de recuperar lo vendido salvo un respaldo.
--
--  No va en la carpeta `docs/sql` a propósito: MySQL ejecuta solos los archivos de
--  esa carpeta al crear su base por primera vez, y las subcarpetas las ignora.
--  Lo normal es no correrlo a mano sino con
--
--      scripts/recrear-base-desarrollo.sh [base]
--
--  que pide confirmación, se niega a tocar el contenedor de producción y vuelve a
--  cargar el esquema y los datos de demostración.
-- =============================================================================

SET NAMES utf8mb4;

DROP DATABASE IF EXISTS ventas_db;
CREATE DATABASE ventas_db
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_0900_ai_ci;
