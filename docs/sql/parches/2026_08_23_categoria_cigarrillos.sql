-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Categoria «Cigarrillos»  (2026-08-23)
--
--  Que hace
--  --------
--  Crea la categoria «Cigarrillos» y saca de «Bebidas» los tres Camel que
--  habian entrado ahi por venir asi en el catalogo de origen. Un cigarrillo no
--  es una bebida, y tenerlos aparte permite ademas filtrar el reporte de
--  ventas por un rubro con reglas propias (venta a mayores de edad, impuesto
--  especifico si el negocio lo separa mas adelante).
--
--  Depende de 2026_08_23_bebidas_catalogo_real.sql: los productos que mueve
--  entran con ese parche. Si aun no se aplico, este no encuentra nada que
--  mover y no falla: solo crea la categoria.
--
--  Es re-ejecutable: la categoria entra con INSERT IGNORE sobre el nombre
--  unico, y el UPDATE filtra por los codigos concretos.
--
--      docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 \
--          -uroot -pventas123 ventas_db \
--          < docs/sql/parches/2026_08_23_categoria_cigarrillos.sql
--
--  Los instaladores nuevos NO cargan este parche solos: docker compose solo
--  recorre docs/sql, no el subdirectorio parches.
-- =============================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO categorias (nombre, descripcion) VALUES
    ('Cigarrillos', 'Tabaco y cigarrillos: venta restringida a mayores de edad');

UPDATE productos
   SET categoria_id = (SELECT id FROM categorias WHERE nombre = 'Cigarrillos')
 WHERE codigo IN ('P-0111', 'P-0120', 'P-0133');

-- Verificacion: los tres Camel deben quedar en «Cigarrillos» y ninguno en «Bebidas».
-- SELECT p.codigo, p.nombre, c.nombre AS categoria
--   FROM productos p JOIN categorias c ON c.id = p.categoria_id
--  WHERE p.nombre LIKE 'Cigarrillo%' ORDER BY p.codigo;
