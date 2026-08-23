# 1. Definición de la Problemática

**Proyecto:** Sistema de Venta de Productos
**Documento:** 01 — Problemática y planteamiento
**Versión:** 1.0

---

## 1.1 Contexto

El negocio es un comercio minorista que vende productos físicos al público en un local
propio (tienda de abarrotes, minimarket, ferretería, librería o similar). Atiende entre
80 y 200 clientes por día, maneja alrededor de 1 500 productos distintos y trabaja con
dos o tres personas por turno.

Hoy **toda la operación de venta es manual**:

- El vendedor anota los productos en una boleta de papel o en un cuaderno.
- El precio se consulta de memoria, mirando la etiqueta o preguntando al dueño.
- La suma del total se hace con calculadora.
- El stock "se sabe" mirando el estante; no existe un registro de existencias.
- Al final del día se cuenta el efectivo de la caja y se compara con las notas escritas.
- Las compras a proveedores se deciden por intuición ("creo que ya se está acabando").

## 1.2 Problema Central

> **El negocio no cuenta con un registro confiable, oportuno y trazable de sus ventas ni
> de su inventario, lo que genera pérdidas económicas, quiebres de stock, atención lenta
> al cliente y decisiones comerciales tomadas sin información.**

## 1.3 Causas Identificadas

| #  | Causa | Descripción |
|----|-------|-------------|
| C1 | Registro manual de la venta | La boleta en papel no alimenta ningún sistema. Se pierde, se moja o nunca se transcribe. |
| C2 | Inexistencia de control de inventario | No hay forma de saber cuántas unidades quedan de un producto sin ir a contarlas. |
| C3 | Precios no centralizados | Cada vendedor puede cobrar un precio distinto por el mismo producto. |
| C4 | Sin control de caja por turno | No se sabe quién abrió o cerró la caja ni cuánto dinero debería haber. |
| C5 | Sin historial de clientes | No se puede saber qué compró un cliente, ni emitir un comprobante a su nombre. |
| C6 | Ausencia de reportes | No hay datos de productos más vendidos, ventas por día, ni margen de ganancia. |
| C7 | Devoluciones informales | Una devolución se resuelve "de palabra"; ni el dinero ni el stock se corrigen. |
| C8 | Cálculo manual del total | Sumas y vueltos hechos a mano, propensos a error. |

## 1.4 Efectos (Consecuencias)

- **Pérdida económica por faltantes:** mercadería que desaparece sin que nadie pueda
  explicar cuándo ni quién la manejó.
- **Quiebres de stock:** productos de alta rotación se agotan sin aviso, y el cliente
  compra en la competencia.
- **Sobrestock:** capital inmovilizado en productos de baja rotación comprados por
  intuición.
- **Atención lenta y colas:** cada venta toma más tiempo del necesario buscando precios y
  sumando a mano.
- **Errores de cobro:** montos mal sumados y vueltos mal calculados que salen del bolsillo
  del negocio.
- **Descuadres de caja:** al cierre no se puede determinar si la diferencia fue un error de
  suma, un descuento no autorizado o un faltante.
- **Decisiones a ciegas:** no se sabe qué producto deja más ganancia ni cuál conviene dejar
  de vender.

## 1.5 Árbol de Problemas

```
   EFECTOS    Pérdidas económicas · Quiebres y sobrestock · Colas y clientes
              insatisfechos · Descuadres de caja · Decisiones sin información
                                     ▲
                                     │
  PROBLEMA    No existe un registro confiable, oportuno y trazable de las
              ventas ni del inventario del negocio
                                     ▲
                                     │
    CAUSAS    Venta en papel · Sin control de stock · Precios dispersos ·
              Sin control de caja · Sin reportes · Devoluciones informales
```

## 1.6 Solución Propuesta

Desarrollar un **Sistema de Venta de Productos (POS web)** que cubra el ciclo completo
de la operación comercial del negocio:

1. **Punto de venta ágil:** búsqueda de productos por nombre, código interno o código de
   barras, carrito, cálculo automático de subtotal, impuesto, descuento y vuelto.
2. **Inventario en tiempo real:** al confirmar una venta el stock se descuenta
   automáticamente y queda registrado el movimiento (trazabilidad producto ↔ venta).
3. **Catálogo y precios centralizados:** un solo precio vigente por producto; los
   descuentos por encima de un umbral requieren autorización.
4. **Comprobantes numerados:** **factura** para clientes persona jurídica y **recibo** para
   clientes persona natural, con serie y correlativo automático, imprimible en A4 o en
   ticket de 80 mm. El sistema elige el documento según el tipo de cliente y no permite
   emitir uno que no le corresponda.
5. **Control de caja por turno:** apertura con monto inicial, registro de ingresos y
   egresos, cierre con arqueo y cálculo de diferencia.
6. **Clientes:** registro **opcional** y diferenciado de **persona natural** (nombres,
   apellidos, DNI/CE) y **persona jurídica** (razón social, RUC, dirección fiscal,
   representante legal), con historial de compras. La venta de mostrador se cobra y se
   documenta con recibo sin pedir ningún dato; solo la factura exige identificar al cliente.
7. **Devoluciones y anulaciones:** operaciones auditables que revierten stock y dinero.
8. **Reportes y dashboard:** ventas por período, por producto, por vendedor y por método
   de pago; alertas de stock mínimo.

## 1.7 Alcance

### Dentro del alcance

- Registro de personal: empleados con su cargo y vínculo laboral (ingreso, contrato, cese).
- Módulo de autenticación, cuentas de usuario y roles de acceso (Administrador, Cajero,
  Almacenero). El **cargo** del empleado y el **rol** de su cuenta son independientes.
- Catálogo: categorías, unidades de medida, productos, precios y stock.
- Punto de venta completo (carrito, cobro, comprobante, pago mixto).
- Inventario: ingresos, salidas, ajustes y kardex por producto.
- Clientes persona natural y persona jurídica; proveedores (datos básicos).
- Emisión de factura y recibo con series y correlativos independientes.
- Sustitución del comprobante cuando el cliente pide factura después de recibido el recibo.
- Caja: apertura, movimientos, cierre y arqueo.
- Devoluciones y anulación de ventas.
- Reportes operativos y dashboard.
- Auditoría de operaciones sensibles.

### Fuera del alcance (versión 1)

- Facturación electrónica ante la administración tributaria. El modelo queda **preparado**
  (tipo de documento, serie, correlativo, datos fiscales del cliente) pero no se integra
  el servicio web del organismo.
- Nota de crédito por devolución de una factura (HU-41): la devolución se registra y revierte
  stock y dinero, pero no genera un documento tributario asociado en esta versión.
- Tienda en línea / e-commerce y pasarelas de pago.
- Contabilidad general, planillas y libros contables. El sistema registra al empleado y su
  vínculo laboral, pero **no** calcula sueldos, asistencia ni horarios.
- Órdenes de compra y gestión de reposición automática con proveedores.
- Multi-sucursal y multi-almacén (se asume un único local).
- Aplicación móvil nativa.

## 1.8 Objetivos

### Objetivo General

Implementar un sistema web de venta de productos que registre cada operación en el momento
en que ocurre, mantenga el inventario actualizado en tiempo real y entregue información
confiable para la gestión del negocio.

### Objetivos Específicos

| #  | Objetivo | Indicador de éxito |
|----|----------|--------------------|
| O1 | Agilizar la atención | Tiempo promedio por venta menor a 1 minuto (hoy ~3 min) |
| O2 | Tener inventario confiable | Desviación entre stock del sistema y stock físico < 2% en el arqueo mensual |
| O3 | Garantizar el cuadre de caja | 100% de los turnos con cierre registrado y diferencia justificada |
| O4 | Centralizar precios | 0 ventas con precio distinto al configurado sin autorización registrada |
| O5 | Disponer de información de gestión | Reporte de ventas y de productos más vendidos disponible en línea en todo momento |
| O6 | Garantizar trazabilidad | Toda venta, anulación, devolución y sustitución de comprobante identifica usuario, fecha, hora y motivo |
| O7 | Evitar quiebres de stock | Alerta automática cuando un producto llega a su stock mínimo |

## 1.9 Actores del Sistema

| Actor | Descripción | Responsabilidad principal |
|-------|-------------|---------------------------|
| **Administrador** | Dueño o gerente del negocio | Configura productos, precios, usuarios y parámetros; consulta reportes; autoriza descuentos, anulaciones y devoluciones. |
| **Cajero / Vendedor** | Atiende al público | Registra ventas, cobra, emite comprobantes, abre y cierra su caja. |
| **Almacenero** | Encargado del depósito | Registra ingresos de mercadería, ajustes de inventario y atiende alertas de stock mínimo. |
| **Cliente persona natural** | Comprador individual | Recibe un **recibo**. Su registro es opcional: la mayoría de las ventas se cobran sin identificarlo (venta al paso) y el recibo sale a nombre de "Cliente varios". |
| **Cliente persona jurídica** | Empresa o institución | Recibe una **factura** a nombre de su razón social; debe estar registrado con RUC y dirección fiscal. |

## 1.10 Requisitos No Funcionales

| # | Requisito | Criterio |
|---|-----------|----------|
| RNF1 | Rendimiento | La pantalla de venta responde en menos de 1 segundo con 5 000 productos en catálogo. |
| RNF2 | Usabilidad | Una venta simple se completa usando solo el teclado (código de barras + Enter + cobro). |
| RNF3 | Disponibilidad | Opera en red local; no depende de conexión a internet permanente. |
| RNF4 | Seguridad | Contraseñas cifradas (hash), sesiones con expiración y control de acceso por rol. |
| RNF5 | Integridad | Las operaciones de venta se ejecutan en transacción: o se guarda todo, o no se guarda nada. |
| RNF6 | Auditabilidad | Ninguna venta ni comprobante se borra físicamente; las correcciones se hacen por anulación, devolución o sustitución del documento. |
| RNF7 | Compatibilidad | Funciona en navegadores modernos sobre equipos de gama baja (4 GB RAM). |
| RNF8 | Respaldo | Respaldo automático diario de la base de datos. |

## 1.11 Restricciones y Supuestos

**Restricciones**

- Base de datos: **MySQL 8** con motor InnoDB y codificación `utf8mb4`.
- Aplicación web accesible desde navegador dentro de la red local del negocio.
- El presupuesto contempla un lector de código de barras e impresora térmica por caja.

**Supuestos**

- El negocio hará una carga inicial del catálogo de productos con su stock físico contado.
- Cada usuario tiene su propia cuenta; no se comparten credenciales entre cajeros.
- Existe un único local y un único almacén en esta versión.
- La moneda es única y se define como parámetro del sistema.
