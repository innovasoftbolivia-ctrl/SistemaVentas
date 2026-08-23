-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Catalogo real de Abarrotes  (2026-08-23)
--
--  Que hace
--  --------
--  Carga en la categoria «Abarrotes» (id 1) los 70 productos del catalogo del
--  negocio, con sus precios de estante, y les deja la carga inicial de stock
--  en el kardex. No toca los 12 productos de demostracion que ya estaban.
--
--  Criterios usados
--  ----------------
--  * `precio_venta` es el precio del catalogo tal cual, sin cuentas de por
--    medio: el sistema queda con `tasa_impuesto` = 0, asi que lo que se carga
--    es exactamente lo que paga el cliente. El precio lo pone el dueno del
--    negocio; el sistema no le suma nada encima.
--  * `precio_compra` = 85% del precio de venta (margen del 15%, en linea con
--    los productos que ya estaban). Es una estimacion: ajustar con el costo real.
--  * `unidad_medida_id` = 1 (UND): son envases sellados que se venden por pieza,
--    no a granel.
--  * `proveedor_id` NULL y `codigo_barras` NULL: no se conocen. El codigo de
--    barras conviene cargarlo escaneando el producto en la ficha.
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
--          < docs/sql/parches/2026_08_23_abarrotes_catalogo_real.sql
--
--  Los instaladores nuevos NO cargan este parche solos: docker compose solo
--  recorre docs/sql, no el subdirectorio parches.
-- =============================================================================

SET NAMES utf8mb4;

-- --------------------------------- Productos ---------------------------------
--                                                                                        costo  venta  stock min
INSERT INTO productos
    (categoria_id, unidad_medida_id, proveedor_id, codigo, nombre, descripcion,
     precio_compra, precio_venta, stock_actual, stock_minimo) VALUES
    (1, 1, NULL, 'P-0013', 'Cafe Nescafe Tradicional Tapa Roja 160 gr', NULL,  70.55,  83.00, 24, 6),
    (1, 1, NULL, 'P-0014', 'Cafe Iguazu Soluble 200 gr', NULL,  75.65,  89.00, 24, 6),
    (1, 1, NULL, 'P-0015', 'Cafe Nescafe Original Tapa Negra 160 gr', NULL,  70.55,  83.00, 24, 6),
    (1, 1, NULL, 'P-0016', 'Baton Garoto de Leche 16 gr', NULL,   4.25,   5.00, 24, 6),
    (1, 1, NULL, 'P-0017', 'Aceite Fino Light 900 ml', NULL,  20.23,  23.80, 24, 6),
    (1, 1, NULL, 'P-0018', 'Galleta Oreo Original 216 gr', NULL,  17.43,  20.50, 24, 6),
    (1, 1, NULL, 'P-0019', 'Galleta Club Social Original 216 gr', NULL,  22.95,  27.00, 24, 6),
    (1, 1, NULL, 'P-0020', 'Achocolatado Chocolike 1 kg', NULL,  33.15,  39.00, 24, 6),
    (1, 1, NULL, 'P-0021', 'Arroz Caisy Grano de Oro 5 kg', NULL,  47.60,  56.00, 24, 6),
    (1, 1, NULL, 'P-0022', 'Aceite Fino Light 1.8 L', NULL,  38.25,  45.00, 24, 6),
    (1, 1, NULL, 'P-0023', 'Arroz Grano de Oro Caisy 1 kg', NULL,   9.35,  11.00, 24, 6),
    (1, 1, NULL, 'P-0024', 'Aceite Fino Vegetal 900 ml', NULL,  16.15,  19.00, 24, 6),
    (1, 1, NULL, 'P-0025', 'Mayonesa Kris Limon 485 ml', NULL,  19.13,  22.50, 24, 6),
    (1, 1, NULL, 'P-0026', 'Te Mate Windsor Manzanilla 100 un', NULL,  29.75,  35.00, 24, 6),
    (1, 1, NULL, 'P-0027', 'Cafe Iguazu Gourmet 200 gr', NULL, 143.65, 169.00, 24, 6),
    (1, 1, NULL, 'P-0028', 'Azucar Guabira Blanca 1 kg', NULL,   5.87,   6.90, 24, 6),
    (1, 1, NULL, 'P-0029', 'Cafe Nescafe Gold Blend 100 gr', NULL,  90.10, 106.00, 24, 6),
    (1, 1, NULL, 'P-0030', 'Galleta Risoffiato de Arroz Chocolate 40 gr', NULL,  11.05,  13.00, 24, 6),
    (1, 1, NULL, 'P-0031', 'Ketchup Kris 490 gr', NULL,  16.58,  19.50, 24, 6),
    (1, 1, NULL, 'P-0032', 'Galleta Arcor Serranita Al Agua 315 gr', NULL,  13.60,  16.00, 24, 6),
    (1, 1, NULL, 'P-0033', 'Leche Pil Polvo Deslactosada 760 gr', NULL,  77.35,  91.00, 24, 6),
    (1, 1, NULL, 'P-0034', 'Arroz Caisy Favorito 5 kg', NULL,  40.80,  48.00, 24, 6),
    (1, 1, NULL, 'P-0035', 'Mayonesa Kris 980 ml', NULL,  33.58,  39.50, 24, 6),
    (1, 1, NULL, 'P-0036', 'Cafe Buena Vista A Toda Hora 500 gr', NULL,  70.13,  82.50, 24, 6),
    (1, 1, NULL, 'P-0037', 'Azucar Guabira Blanca 5 kg', NULL,  28.48,  33.50, 24, 6),
    (1, 1, NULL, 'P-0038', 'Atun Van Camps Aceite Oliva 3 un 80 gr', NULL,  39.10,  46.00, 24, 6),
    (1, 1, NULL, 'P-0039', 'Galleta Choco Soda 240 gr', NULL,  21.85,  25.70, 24, 6),
    (1, 1, NULL, 'P-0040', 'Galleta Noel Ducales', 'Nombre por confirmar: en el catalogo de origen aparece recortado.',  16.58,  19.50, 24, 6),
    (1, 1, NULL, 'P-0041', 'Chocolate Nestle Sublime Clasico 28 gr', NULL,   5.95,   7.00, 24, 6),
    (1, 1, NULL, 'P-0042', 'Bombon Bon o Bon Supreme 144 gr', NULL,  33.15,  39.00, 24, 6),
    (1, 1, NULL, 'P-0043', 'Leche Instantanea Pil Polvo Bolsa 2 kg', NULL, 167.45, 197.00, 24, 6),
    (1, 1, NULL, 'P-0044', 'Galleta Mabel Rosquita Duo 460 gr', NULL,  16.83,  19.80, 24, 6),
    (1, 1, NULL, 'P-0045', 'Chocolate Cofler Block Mani 38 gr', NULL,   6.80,   8.00, 24, 6),
    (1, 1, NULL, 'P-0046', 'Galleta Chips Ahoy Nabisco 222 gr', NULL,  22.78,  26.80, 24, 6),
    (1, 1, NULL, 'P-0047', 'Nacho Mexicamba Surtido 120 gr', NULL,   7.65,   9.00, 24, 6),
    (1, 1, NULL, 'P-0048', 'Sardina Robinson Crusoe Al Aceite 125 gr', NULL,  15.98,  18.80, 24, 6),
    (1, 1, NULL, 'P-0049', 'Chocolate Kit Kat Nestle 41.5 gr', 'En el catalogo de origen esta con -18% OFF: precio anterior Bs 11.00.',   7.65,   9.00, 24, 6),
    (1, 1, NULL, 'P-0050', 'Aceite De Soya Inolsa 2 L', NULL,  33.58,  39.50, 24, 6),
    (1, 1, NULL, 'P-0051', 'Arroz Caisy Favorito 1 kg', NULL,   8.25,   9.70, 24, 6),
    (1, 1, NULL, 'P-0052', 'Chocolate Snickers Barra 52.7 gr', NULL,  10.20,  12.00, 24, 6),
    (1, 1, NULL, 'P-0053', 'Arroz Caisy Japones 5 kg', NULL,  85.00, 100.00, 24, 6),
    (1, 1, NULL, 'P-0054', 'Arroz Caisy Especial 5 kg', 'Nombre por confirmar: en el catalogo de origen aparece recortado.',  39.95,  47.00, 24, 6),
    (1, 1, NULL, 'P-0055', 'Llajua B&R Churrasquera 220 gr', NULL,  20.40,  24.00, 24, 6),
    (1, 1, NULL, 'P-0056', 'Arroz Caisy Especial 1 kg', NULL,   8.08,   9.50, 24, 6),
    (1, 1, NULL, 'P-0057', 'Cereal Kelloggs Froot Loops 350 gr', NULL,  36.55,  43.00, 24, 6),
    (1, 1, NULL, 'P-0058', 'Durazno al Jugo Norte 820 gr', NULL,  18.70,  22.00, 24, 6),
    (1, 1, NULL, 'P-0059', 'Arroz Caisy Favorito 2 kg', NULL,  15.30,  18.00, 24, 6),
    (1, 1, NULL, 'P-0060', 'Nacho Mexican Food de Maiz 400 gr', NULL,  16.15,  19.00, 24, 6),
    (1, 1, NULL, 'P-0061', 'Choclo Cajamar Conserva 280 gr', NULL,   7.06,   8.30, 24, 6),
    (1, 1, NULL, 'P-0062', 'Mayonesa Kris 225 Cm', NULL,  10.20,  12.00, 24, 6),
    (1, 1, NULL, 'P-0063', 'Azucar Blanca Aguai 1,1 kg', NULL,   5.61,   6.60, 24, 6),
    (1, 1, NULL, 'P-0064', 'Avena Instantanea Princesa Caja 300 gr', NULL,  12.33,  14.50, 24, 6),
    (1, 1, NULL, 'P-0065', 'Lasaña Carozzi Tradicional 400 gr', NULL,  22.70,  26.70, 24, 6),
    (1, 1, NULL, 'P-0066', 'Galleta Sublime Rellena 276 gr', NULL,  13.18,  15.50, 24, 6),
    (1, 1, NULL, 'P-0067', 'Galleta Costa Frac Clasica 246 gr', NULL,  15.98,  18.80, 24, 6),
    (1, 1, NULL, 'P-0068', 'Papa Mexibol Frita (bolsa grande)', 'Nombre por confirmar: en el catalogo de origen aparece recortado.',  26.35,  31.00, 24, 6),
    (1, 1, NULL, 'P-0069', 'Leche Condensada Bella Holandesa 397 gr', NULL,  21.25,  25.00, 24, 6),
    (1, 1, NULL, 'P-0070', 'Cafe Sello Rojo Tradicional 425 gr', NULL,  91.80, 108.00, 24, 6),
    (1, 1, NULL, 'P-0071', 'Chocolate Cofler Block 110 gr', NULL,  15.30,  18.00, 24, 6),
    (1, 1, NULL, 'P-0072', 'Pistacho Maya Entero con Cascara 230 gr', NULL,  93.50, 110.00, 24, 6),
    (1, 1, NULL, 'P-0073', 'Chicle Topline Seven Red Berry 24 gr', NULL,  12.75,  15.00, 24, 6),
    (1, 1, NULL, 'P-0074', 'Cafe Chiriguano 500 gr', NULL,  37.40,  44.00, 24, 6),
    (1, 1, NULL, 'P-0075', 'Galleta Oreo Cookies and Cream 216 gr', NULL,  17.43,  20.50, 24, 6),
    (1, 1, NULL, 'P-0076', 'Leche Pil Polvo Instantanea 760 gr', NULL,  66.73,  78.50, 24, 6),
    (1, 1, NULL, 'P-0077', 'Mostaza Kris 1000 gr', NULL,  30.60,  36.00, 24, 6),
    (1, 1, NULL, 'P-0078', 'Papa Mexibol Frita 200 gr', NULL,  13.60,  16.00, 24, 6),
    (1, 1, NULL, 'P-0079', 'Pipoca Johns Pop Sweet Salty 100 gr', NULL,   8.50,  10.00, 24, 6),
    (1, 1, NULL, 'P-0080', 'Atun Van Camps Al Agua 160 gr', NULL,  20.40,  24.00, 24, 6),
    (1, 1, NULL, 'P-0081', 'Bombon Garoto Surtido 250 gr', NULL,  36.98,  43.50, 24, 6),
    (1, 1, NULL, 'P-0082', 'Arroz Caisy Super Economico 5 kg', NULL,  35.28,  41.50, 24, 6)
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
 WHERE p.codigo BETWEEN 'P-0013' AND 'P-0082'
   AND p.stock_actual > 0
   AND NOT EXISTS (
        SELECT 1 FROM movimientos_inventario m
         WHERE m.producto_id = p.id AND m.origen = 'INICIAL'
   );

-- Verificacion: el precio de estante debe coincidir con el del catalogo.
-- SELECT codigo, nombre, precio_venta,
--        ROUND(precio_venta * (1 + (SELECT CAST(valor AS DECIMAL(6,4))
--                                     FROM configuracion WHERE clave = 'tasa_impuesto')), 2) AS precio_estante
--   FROM productos WHERE categoria_id = 1 ORDER BY codigo;
