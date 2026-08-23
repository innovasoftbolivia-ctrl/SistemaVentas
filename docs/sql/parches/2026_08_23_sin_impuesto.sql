-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - El precio del producto es el precio final  (2026-08-23)
--
--  Por que
--  -------
--  El sistema nacio con datos de Peru: IGV del 18%, RUC, soles. Despues se
--  cambio la moneda a bolivianos, pero la tasa se quedo. El resultado era que
--  el negocio cargaba un precio y el sistema le sumaba un 18% encima al vender.
--
--  El criterio pasa a ser el de una tienda de barrio: el precio que el dueno
--  pone en el producto es el que paga el cliente. Ni el POS ni el comprobante
--  le agregan nada. Si mas adelante el negocio necesita desglosar el IVA (13%
--  en Bolivia) en la factura, se vuelve a poner la tasa aqui y se recalculan
--  los precios base; los comprobantes ya emitidos no cambian, porque cada
--  linea de venta guarda su propia `tasa_impuesto`.
--
--  Que hace
--  --------
--  1. `tasa_impuesto` = 0.
--  2. Sube los 12 productos de demostracion a su precio de estante (el que ya
--     mostraba el sistema), para que ni el precio ni el margen cambien al
--     dejar de sumarse el impuesto.
--  3. Alinea el costo anotado en las cargas iniciales del kardex.
--
--  No toca las ventas ya emitidas: `venta_detalle` congela `precio_unitario` y
--  `tasa_impuesto` linea por linea, asi que los comprobantes viejos siguen
--  cuadrando con su total impreso.
--
--  Los productos del catalogo real (P-0013 en adelante) llevan su precio final
--  en sus propios parches; este no necesita tocarlos. Por eso va DESPUES de
--  ellos: el paso 3 alinea los costos que esos parches acaban de escribir.
--
--  Es re-ejecutable: con la tasa ya en 0 el paso 2 multiplica por 1 y no mueve
--  nada, y el paso 3 vuelve a alinear los costos.
--
--      docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 \
--          -uroot -pventas123 ventas_db \
--          < docs/sql/parches/2026_08_23_sin_impuesto.sql
--
--  Los instaladores nuevos NO cargan este parche solos: docker compose solo
--  recorre docs/sql, no el subdirectorio parches.
-- =============================================================================

SET NAMES utf8mb4;

-- Se guarda la tasa vigente ANTES de tocarla: es el factor con el que hay que
-- subir los precios de demostracion. Si el parche ya se aplico vale 0 y el
-- UPDATE de mas abajo no mueve nada.
SET @tasa := (SELECT CAST(valor AS DECIMAL(6,4)) FROM configuracion WHERE clave = 'tasa_impuesto');

UPDATE productos
   SET precio_venta  = ROUND(precio_venta  * (1 + @tasa), 2),
       precio_compra = ROUND(precio_compra * (1 + @tasa), 2)
 WHERE codigo BETWEEN 'P-0001' AND 'P-0012';

UPDATE configuracion
   SET valor = '0.0000',
       descripcion = 'Tasa del impuesto a las ventas. En 0: el precio del producto ya es el precio final'
 WHERE clave = 'tasa_impuesto';

UPDATE configuracion
   SET valor = '1',
       descripcion = '1 = el precio de venta ya es el final. Es documentacion: ningun codigo lee esta clave'
 WHERE clave = 'precio_incluye_impuesto';

-- El costo anotado en la carga inicial debe coincidir con el del producto.
UPDATE movimientos_inventario m
  JOIN productos p ON p.id = m.producto_id
   SET m.costo_unitario = p.precio_compra
 WHERE m.origen = 'INICIAL';

-- Verificacion: `precio_venta` y el precio de estante tienen que ser iguales.
-- SELECT codigo, nombre, precio_compra, precio_venta,
--        ROUND(precio_venta * (1 + (SELECT CAST(valor AS DECIMAL(6,4))
--                                     FROM configuracion WHERE clave = 'tasa_impuesto')), 2) AS precio_estante
--   FROM productos ORDER BY codigo;
