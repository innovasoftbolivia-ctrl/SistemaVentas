-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Catalogo real de Bebidas  (2026-08-23)
--
--  Que hace
--  --------
--  Carga en la categoria «Bebidas» (id 2) los 70 productos del catalogo del
--  negocio, con sus precios de estante, y les deja la carga inicial de stock
--  en el kardex. Continua la numeracion del parche de Abarrotes.
--
--  Ojo: el catalogo de origen lista los cigarrillos dentro de bebidas, asi que
--  entran aqui igual. El parche 2026_08_23_categoria_cigarrillos.sql, que va
--  despues, los saca a su propia categoria.
--
--  Criterios usados (los mismos que en el parche de Abarrotes)
--  ----------------------------------------------------------
--  * `precio_venta` es el precio del catalogo tal cual, sin cuentas de por
--    medio: el sistema queda con `tasa_impuesto` = 0, asi que lo que se carga
--    es exactamente lo que paga el cliente. El precio lo pone el dueno del
--    negocio; el sistema no le suma nada encima.
--  * `precio_compra` = 85% del precio de venta (margen del 15%). Es una
--    estimacion: ajustar con el costo real.
--  * `unidad_medida_id` = 1 (UND): son envases sellados que se venden por pieza.
--  * `proveedor_id` NULL y `codigo_barras` NULL: no se conocen.
--  * Stock inicial 24 y minimo 6 para todos: son valores de arranque,
--    no un conteo real. Corregir con «Ajuste por conteo fisico».
--
--  Es re-ejecutable, y ademas repone precios: si el producto ya existe (mismo
--  `codigo`) se le vuelven a escribir `precio_venta` y `precio_compra` con los
--  de este archivo. Util para recargar el catalogo de golpe; ojo si el negocio
--  ya retoco precios a mano, porque los pisa. El stock no se toca, y el
--  movimiento inicial solo se crea si el producto aun no lo tiene.
--
--      docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 \
--          -uroot -pventas123 ventas_db \
--          < docs/sql/parches/2026_08_23_bebidas_catalogo_real.sql
--
--  Los instaladores nuevos NO cargan este parche solos: docker compose solo
--  recorre docs/sql, no el subdirectorio parches.
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------- Bebidas ----------------------------------
--                                                                                        costo  venta  stock min
INSERT INTO productos
    (categoria_id, unidad_medida_id, proveedor_id, codigo, nombre, descripcion,
     precio_compra, precio_venta, stock_actual, stock_minimo) VALUES
    (2, 1, NULL, 'P-0083', 'Cerveza Paceña Pilsener Lata 269 ml CBN', NULL,   6.38,   7.50, 24, 6),
    (2, 1, NULL, 'P-0084', 'Don Hielo Cubitos 3 kg', NULL,   8.08,   9.50, 24, 6),
    (2, 1, NULL, 'P-0085', 'Cerveza Huari Lager 269 ml CBN', NULL,   8.08,   9.50, 24, 6),
    (2, 1, NULL, 'P-0086', 'Gaseosa Coca Cola Original 3 L', NULL,  17.00,  20.00, 24, 6),
    (2, 1, NULL, 'P-0087', 'Gaseosa Coca Cola Clasica Pack 6 un 300 ml', NULL,  17.00,  20.00, 24, 6),
    (2, 1, NULL, 'P-0088', 'Gaseosa Coca Cola Zero 2 L', NULL,   9.95,  11.70, 24, 6),
    (2, 1, NULL, 'P-0089', 'Gaseosa Coca Cola Sin Azucar 3 L', NULL,  12.75,  15.00, 24, 6),
    (2, 1, NULL, 'P-0090', 'Cerveza Paceña Roja 330 ml CBN', 'En el catalogo de origen esta con -13% OFF: precio anterior Bs 9.20.',   6.80,   8.00, 24, 6),
    (2, 1, NULL, 'P-0091', 'Fernet Branca 1 L', NULL, 106.25, 125.00, 24, 6),
    (2, 1, NULL, 'P-0092', 'Gaseosa Coca Cola Original 2.5 L', NULL,  12.58,  14.80, 24, 6),
    (2, 1, NULL, 'P-0093', 'Hielo Hipermaxi Cubos 3 kg', NULL,   6.80,   8.00, 24, 6),
    (2, 1, NULL, 'P-0094', 'Agua Vital Sin Gas 600 ml', NULL,   3.74,   4.40, 24, 6),
    (2, 1, NULL, 'P-0095', 'Maltin 440 ml CBN', NULL,   6.80,   8.00, 24, 6),
    (2, 1, NULL, 'P-0096', 'Gaseosa Coca Cola Original 2 L', 'Tamaño por confirmar: en el catalogo de origen el nombre aparece recortado. Esta con -13% OFF: precio anterior Bs 13.80.',  10.20,  12.00, 24, 6),
    (2, 1, NULL, 'P-0097', 'Agua Vital Con Gas 2 L', NULL,   6.55,   7.70, 24, 6),
    (2, 1, NULL, 'P-0098', 'Gaseosa Coca Cola Sin Azucar Mini Six Pack 300 ml', NULL,  17.00,  20.00, 24, 6),
    (2, 1, NULL, 'P-0099', 'Cerveza Paceña Roja 440 ml CBN', NULL,   9.10,  10.70, 24, 6),
    (2, 1, NULL, 'P-0100', 'Gaseosa Coca Cola Original 500 ml', NULL,   4.51,   5.30, 24, 6),
    (2, 1, NULL, 'P-0101', 'Agua Vital Con Gas 600 ml', NULL,   3.83,   4.50, 24, 6),
    (2, 1, NULL, 'P-0102', 'Energizante Red Bull 250 ml', NULL,  16.15,  19.00, 24, 6),
    (2, 1, NULL, 'P-0103', 'Cerveza Huari 440 ml CBN', NULL,  11.05,  13.00, 24, 6),
    (2, 1, NULL, 'P-0104', 'Vino Campos de Solana Trivarietal Reserva 750 ml', NULL,  92.65, 109.00, 24, 6),
    (2, 1, NULL, 'P-0105', 'Agua Vital Sin Gas 3 L', NULL,   8.08,   9.50, 24, 6),
    (2, 1, NULL, 'P-0106', 'Energizante Monster Zero 473 ml', NULL,  17.85,  21.00, 24, 6),
    (2, 1, NULL, 'P-0107', 'Gaseosa Coca Cola Sin Azucar 500 ml', NULL,   4.51,   5.30, 24, 6),
    (2, 1, NULL, 'P-0108', 'Fernet Branca 750 ml', NULL,  83.30,  98.00, 24, 6),
    (2, 1, NULL, 'P-0109', 'Pack Fernet Branca 1 L + Vaso', NULL, 159.80, 188.00, 24, 6),
    (2, 1, NULL, 'P-0110', 'Gaseosa Coca Cola Original 1.5 L', 'Nombre por confirmar: en el catalogo de origen aparece recortado.',   8.08,   9.50, 24, 6),
    (2, 1, NULL, 'P-0111', 'Cigarrillo Camel Amarillo 20 un', NULL,  17.85,  21.00, 24, 6),
    (2, 1, NULL, 'P-0112', 'Gaseosa Sprite 2 L', NULL,  11.65,  13.70, 24, 6),
    (2, 1, NULL, 'P-0113', 'Agua Villasanta Natural 7 L', NULL,  18.70,  22.00, 24, 6),
    (2, 1, NULL, 'P-0114', 'Agua Vital Sin Gas 6 L', NULL,  21.25,  25.00, 24, 6),
    (2, 1, NULL, 'P-0115', 'Gran Singani Casa Real Etiqueta Negra 750 ml', NULL,  79.05,  93.00, 24, 6),
    (2, 1, NULL, 'P-0116', 'Paq Cerveza Amstel Lata 12x10 269 ml', NULL,  63.75,  75.00, 24, 6),
    (2, 1, NULL, 'P-0117', 'Agua Vital Sin Gas 2 L', NULL,   6.55,   7.70, 24, 6),
    (2, 1, NULL, 'P-0118', 'Gaseosa Sprite Mini Six Pack 300 ml', NULL,  17.00,  20.00, 24, 6),
    (2, 1, NULL, 'P-0119', 'Vino Esther Ortiz Campos de Solana 750 ml', NULL, 276.25, 325.00, 24, 6),
    (2, 1, NULL, 'P-0120', 'Cigarrillo Camel Activate 20 un', NULL,  19.98,  23.50, 24, 6),
    (2, 1, NULL, 'P-0121', 'Ron Abuelo Añejo 1750 ml', NULL, 194.65, 229.00, 24, 6),
    (2, 1, NULL, 'P-0122', 'Singani Casa Real Etiqueta Negra 1 L', NULL,  92.65, 109.00, 24, 6),
    (2, 1, NULL, 'P-0123', 'Singani Casa Real Don Lucho XO Premium 750 ml', NULL, 641.75, 755.00, 24, 6),
    (2, 1, NULL, 'P-0124', 'Gaseosa Coca Cola Original Two Pack 3 L', 'Nombre por confirmar: en el catalogo de origen aparece recortado.',  32.30,  38.00, 24, 6),
    (2, 1, NULL, 'P-0125', 'Vino Aranjuez Tannat 750 ml', NULL,  63.75,  75.00, 24, 6),
    (2, 1, NULL, 'P-0126', 'Gaseosa Sprite 3 L', NULL,  17.00,  20.00, 24, 6),
    (2, 1, NULL, 'P-0127', 'Vino Aranjuez Terruño Tinto 700 ml', NULL,  22.95,  27.00, 24, 6),
    (2, 1, NULL, 'P-0128', 'Pack Isotonico Powerade 990 ml', NULL,  16.58,  19.50, 24, 6),
    (2, 1, NULL, 'P-0129', 'Jugo Del Valle Manzana Six Pack 200 ml', NULL,  12.75,  15.00, 24, 6),
    (2, 1, NULL, 'P-0130', 'Energizante Monster Green 473 ml', NULL,  17.85,  21.00, 24, 6),
    (2, 1, NULL, 'P-0131', 'Vino Aranjuez Duo Tannat Merlot 750 ml', NULL,  39.95,  47.00, 24, 6),
    (2, 1, NULL, 'P-0132', 'Vino Campos de Solana Tinto 700 ml', NULL,  22.53,  26.50, 24, 6),
    (2, 1, NULL, 'P-0133', 'Cigarrillo Camel Azul 20 un', NULL,  17.85,  21.00, 24, 6),
    (2, 1, NULL, 'P-0134', 'Agua Vital Sport Sin Gas 990 ml', NULL,   5.70,   6.70, 24, 6),
    (2, 1, NULL, 'P-0135', 'Vino Aranjuez Tannat Origen 750 ml', NULL, 102.85, 121.00, 24, 6),
    (2, 1, NULL, 'P-0136', 'Jugo Ades Manzana Tetra 1 L', NULL,  10.88,  12.80, 24, 6),
    (2, 1, NULL, 'P-0137', 'Jugo Ades Manzana Tetra Six Pack 200 ml', NULL,  15.73,  18.50, 24, 6),
    (2, 1, NULL, 'P-0138', 'Cerveza Corona Extra 330 ml CBN', 'Nombre por confirmar: en el catalogo de origen aparece recortado.',  11.73,  13.80, 24, 6),
    (2, 1, NULL, 'P-0139', 'Gaseosa Fanta Berry 12 oz', NULL,  17.85,  21.00, 24, 6),
    (2, 1, NULL, 'P-0140', 'Gaseosa Pepsi 3 L CBN', NULL,  12.75,  15.00, 24, 6),
    (2, 1, NULL, 'P-0141', 'Gaseosa Fanta Naranja Mini Six Pack 300 ml', NULL,  17.00,  20.00, 24, 6),
    (2, 1, NULL, 'P-0142', 'Cerveza Stella Artois 330 ml CBN', NULL,  11.73,  13.80, 24, 6),
    (2, 1, NULL, 'P-0143', 'Aperitivo Aperol 750 ml', NULL, 131.75, 155.00, 24, 6),
    (2, 1, NULL, 'P-0144', 'Gaseosa Guarana Antartica 3 L CBN', NULL,  15.73,  18.50, 24, 6),
    (2, 1, NULL, 'P-0145', 'Gaseosa Coca Cola Classica USA 355 ml', NULL,  18.11,  21.30, 24, 6),
    (2, 1, NULL, 'P-0146', 'Jugo Del Valle Durazno Six Pack 200 ml', NULL,  12.75,  15.00, 24, 6),
    (2, 1, NULL, 'P-0147', 'Vino Kohlberg Tinto 700 ml', NULL,  22.53,  26.50, 24, 6),
    (2, 1, NULL, 'P-0148', 'Vino Catena Malbec 750 ml', NULL, 102.85, 121.00, 24, 6),
    (2, 1, NULL, 'P-0149', 'Gaseosa Pepsi Black 3 L CBN', NULL,  10.20,  12.00, 24, 6),
    (2, 1, NULL, 'P-0150', 'Cerveza Huari Miel 330 ml CBN', NULL,  11.05,  13.00, 24, 6),
    (2, 1, NULL, 'P-0151', 'Jugo Aquarius Pera 3 L', NULL,  17.00,  20.00, 24, 6),
    (2, 1, NULL, 'P-0152', 'Gaseosa Fanta Guarana 2 L', NULL,  11.90,  14.00, 24, 6)
AS nuevo ON DUPLICATE KEY UPDATE
    precio_compra = nuevo.precio_compra,
    precio_venta  = nuevo.precio_venta;

-- ----------------------- Carga inicial en el kardex --------------------------
-- El stock nunca queda sin respaldo en movimientos_inventario (RNF6).
INSERT INTO movimientos_inventario
    (producto_id, usuario_id, tipo, origen, cantidad, stock_anterior, stock_resultante,
     costo_unitario, motivo)
SELECT p.id, NULL, 'ENTRADA', 'INICIAL', p.stock_actual, 0, p.stock_actual,
       p.precio_compra, 'Carga inicial de inventario'
  FROM productos p
 WHERE p.codigo BETWEEN 'P-0083' AND 'P-0152'
   AND p.stock_actual > 0
   AND NOT EXISTS (
        SELECT 1 FROM movimientos_inventario m
         WHERE m.producto_id = p.id AND m.origen = 'INICIAL'
   );

-- Verificacion: el precio de estante debe coincidir con el del catalogo.
-- SELECT codigo, nombre, precio_venta,
--        ROUND(precio_venta * (1 + (SELECT CAST(valor AS DECIMAL(6,4))
--                                     FROM configuracion WHERE clave = 'tasa_impuesto')), 2) AS precio_estante
--   FROM productos WHERE categoria_id = 2 ORDER BY codigo;
