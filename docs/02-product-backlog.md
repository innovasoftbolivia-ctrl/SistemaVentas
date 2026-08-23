# 2. Product Backlog e Historias de Usuario

**Proyecto:** Sistema de Venta de Productos
**Documento:** 02 — Product Backlog
**Versión:** 1.0

---

## 2.1 Visión del Producto

> Para el **dueño y el personal de una tienda minorista** que hoy registra sus ventas en
> papel, el **Sistema de Venta de Productos** es una aplicación web de punto de venta que
> registra cada venta en el momento, mantiene el inventario actualizado en tiempo real y
> entrega reportes de gestión. A diferencia de la libreta y la calculadora, garantiza
> trazabilidad, cuadre de caja y decisiones basadas en datos.

## 2.2 Épicas

| ID | Épica | Descripción | Valor |
|----|-------|-------------|-------|
| EP1 | Personal y seguridad | Empleados, cargos, autenticación, roles y permisos | Alto |
| EP2 | Catálogo de productos | Categorías, unidades, productos, precios | Muy alto |
| EP3 | Punto de venta | Carrito, cobro, comprobante | Crítico |
| EP4 | Inventario | Stock, movimientos, kardex, alertas | Muy alto |
| EP5 | Clientes | Persona natural y jurídica, historial de compras | Alto |
| EP6 | Caja y turnos | Apertura, movimientos, cierre y arqueo | Alto |
| EP7 | Devoluciones y anulaciones | Reversión auditable de venta y stock | Alto |
| EP8 | Reportes y dashboard | Información para la gestión | Alto |
| EP9 | Configuración y auditoría | Parámetros del negocio y bitácora | Medio |

## 2.3 Criterios de Priorización

- **MoSCoW:** `M` = Must (imprescindible para el MVP), `S` = Should, `C` = Could, `W` = Won't (esta versión).
- **Estimación:** puntos de historia (Fibonacci: 1, 2, 3, 5, 8, 13).
- **Velocidad estimada del equipo:** 26 puntos por sprint de 2 semanas.

---

## 2.4 Product Backlog

| ID | Épica | Historia (resumen) | Prio | Pts | Sprint |
|------|-----|--------------------------------------------------------|:--:|:--:|:--:|
| HU-01 | EP1 | Iniciar sesión con usuario y contraseña | M | 3 | 1 |
| **HU-44** | EP1 | **Registrar empleados con su cargo y vínculo laboral** | **M** | **5** | **1** |
| HU-02 | EP1 | Crear la cuenta de acceso de un empleado y asignarle un rol | M | 5 | 1 |
| HU-03 | EP1 | Restringir el acceso a los módulos según el rol | M | 3 | 1 |
| HU-04 | EP2 | Registrar y administrar categorías de productos | M | 2 | 1 |
| HU-05 | EP2 | Registrar y administrar productos con precio y stock | M | 5 | 1 |
| HU-06 | EP2 | Buscar productos por nombre, código interno o código de barras | M | 3 | 1 |
| HU-07 | EP2 | Activar o desactivar un producto sin eliminarlo | S | 2 | 2 |
| HU-08 | EP3 | Agregar productos a un carrito de venta | M | 5 | 2 |
| HU-09 | EP3 | Modificar cantidad y quitar un ítem del carrito | M | 3 | 2 |
| HU-10 | EP3 | Calcular automáticamente subtotal, impuesto y total | M | 3 | 2 |
| HU-11 | EP3 | Cobrar la venta y calcular el vuelto | M | 5 | 2 |
| HU-17 | EP4 | Descontar el stock automáticamente al confirmar la venta | M | 5 | 2 |
| HU-18 | EP4 | Bloquear la venta de un producto sin stock suficiente | M | 3 | 2 |
| HU-23 | EP5 | Registrar y administrar clientes | M | 3 | 3 |
| **HU-39** | EP5 | **Registrar el cliente como persona natural o jurídica, con sus datos obligatorios** | **M** | **5** | **3** |
| HU-13 | EP3 | Emitir comprobante numerado con serie y correlativo | M | 5 | 3 |
| **HU-40** | EP3 | **Emitir factura a persona jurídica y recibo a persona natural** | **M** | **5** | **3** |
| **HU-43** | EP3 | **Vender y emitir recibo sin registrar al cliente (venta al paso)** | **M** | **2** | **3** |
| HU-22 | EP4 | Ver alerta de productos en o por debajo del stock mínimo | M | 3 | 3 |
| HU-25 | EP6 | Abrir caja con un monto inicial | M | 3 | 3 |
| HU-28 | EP6 | Impedir vender si el usuario no tiene una caja abierta | M | 2 | 3 |
| HU-12 | EP3 | Registrar pago con más de un método (pago mixto) | S | 5 | 4 |
| HU-14 | EP3 | Imprimir o descargar el comprobante en PDF | S | 3 | 4 |
| HU-15 | EP3 | Aplicar un descuento a un ítem o a la venta completa | S | 5 | 4 |
| HU-19 | EP4 | Registrar ingreso de mercadería al inventario | M | 5 | 4 |
| HU-20 | EP4 | Registrar un ajuste de inventario con motivo | S | 3 | 4 |
| HU-24 | EP5 | Asociar un cliente a la venta y ver su historial de compras | S | 3 | 4 |
| HU-26 | EP6 | Registrar ingresos y egresos de efectivo durante el turno | S | 3 | 4 |
| HU-27 | EP6 | Cerrar caja con arqueo y cálculo de diferencia | M | 5 | 5 |
| HU-29 | EP7 | Anular una venta del día con motivo y autorización | M | 5 | 5 |
| HU-30 | EP7 | Registrar una devolución total o parcial que reingrese el stock | S | 8 | 5 |
| HU-32 | EP8 | Consultar reporte de ventas por rango de fechas con filtros | M | 5 | 5 |
| **HU-42** | EP3 | **Sustituir el recibo por una factura cuando el cliente la solicita después** | **S** | **5** | **5** |
| HU-21 | EP4 | Consultar el kardex (historial de movimientos) de un producto | S | 5 | 6 |
| HU-16 | EP3 | Guardar una venta en espera y retomarla después | C | 5 | 6 |
| HU-31 | EP8 | Ver dashboard con ventas del día, ticket promedio y top productos | S | 5 | 6 |
| HU-33 | EP8 | Consultar reporte de productos más vendidos | S | 3 | 6 |
| HU-34 | EP8 | Exportar reportes a Excel/CSV | C | 3 | 6 |
| HU-35 | EP9 | Configurar datos del negocio, moneda e impuesto | S | 3 | 6 |
| HU-36 | EP9 | Consultar la bitácora de auditoría de operaciones sensibles | C | 5 | 6 |
| HU-37 | EP2 | Manejar productos con más de una unidad de medida | W | 8 | — |
| HU-38 | EP3 | Vender a crédito con cuentas por cobrar | W | 13 | — |
| HU-41 | EP7 | Emitir nota de crédito por la devolución de una factura | W | 8 | — |

**Total planificado (Sprints 1 a 6): 164 puntos. MVP operativo al cierre del Sprint 5: 135 puntos.**

### Resumen por sprint

| Sprint | Objetivo | Pts |
|:--:|-------------------------------------------------------------|:--:|
| 1 | Base: **empleados y cargos**, seguridad, usuarios y catálogo de productos | 26 |
| 2 | Venta funcional end-to-end con descuento de stock | 26 |
| 3 | **Clientes natural/jurídica, factura, recibo y venta al paso**, alertas y apertura de caja | 28 |
| 4 | Descuentos, pago mixto, PDF, ingresos y ajustes de inventario | 27 |
| 5 | Cierre de caja, anulaciones, devoluciones, **sustitución de comprobante** y reporte de ventas | 28 |
| 6 | Kardex, dashboard, exportaciones, configuración y auditoría | 29 |

> El Sprint 1 abre con HU-44 porque `empleados` es prerrequisito de `usuarios`: sin la
> persona registrada no hay a quién crearle una cuenta.

---

## 2.5 Historias de Usuario Más Resaltantes

Se detallan las historias de mayor valor y mayor riesgo técnico. Los criterios de
aceptación se expresan en formato **Gherkin** (Dado / Cuando / Entonces).

---

### HU-44 — Registrar empleados con su cargo y vínculo laboral

> **Como** administrador
> **quiero** registrar a mi personal con su cargo y sus datos laborales, tenga o no cuenta en el sistema
> **para** saber quién trabaja en el negocio y desde cuándo, aparte de quién usa el sistema.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 1

**Criterios de aceptación**

1. **Escenario: alta de empleado**
   - **Cuando** registro a un empleado con documento, nombres, apellidos, cargo y fecha de ingreso
   - **Entonces** queda registrado con estado "Activo"
   - **Y** aparece en el listado de personal con su cargo.

2. **Escenario: empleado sin cuenta de sistema**
   - **Dado** que registro a un ayudante que no usará el sistema
   - **Cuando** guardo sin crear usuario
   - **Entonces** el empleado queda registrado igual
   - **Y** el listado muestra "Sin cuenta" en la columna de acceso.

3. **Escenario: cargo distinto del rol**
   - **Dado** un empleado con cargo "Cajero"
   - **Cuando** le creo una cuenta con rol "Administrador"
   - **Entonces** el sistema lo permite
   - **Y** el empleado conserva su cargo de Cajero.

4. **Escenario: cese**
   - **Cuando** registro el cese de un empleado con su fecha y motivo
   - **Entonces** su estado pasa a "Cesado"
   - **Y** su cuenta de acceso queda desactivada automáticamente
   - **Y** no puede iniciar sesión.

5. **Escenario: el cese exige fecha**
   - **Cuando** intento marcar a alguien como cesado sin indicar la fecha
   - **Entonces** el sistema no lo permite
   - **Y** tampoco acepta una fecha de cese anterior a la de ingreso.

6. **Escenario: las ventas del empleado cesado no se pierden**
   - **Dado** un cajero cesado que registró 300 ventas
   - **Entonces** esas ventas siguen mostrando su nombre
   - **Y** los reportes por vendedor lo siguen incluyendo.

7. **Escenario: documento duplicado**
   - **Cuando** registro un documento de identidad que ya existe
   - **Entonces** el sistema muestra "Ya existe un empleado con ese documento".

**Definición de Terminado**

- Un empleado no puede tener dos cuentas de usuario (índice único sobre `empleado_id`).
- El estado laboral (`empleados.estado`) y el acceso (`usuarios.activo`) son campos
  distintos; el cese desactiva el acceso, pero desactivar el acceso no cesa a nadie.
- La baja de un empleado es lógica: nunca se elimina, porque su historial de operaciones
  debe seguir siendo consultable.

---

### HU-08 — Agregar productos al carrito de venta

> **Como** cajero
> **quiero** agregar productos al carrito escaneando su código de barras o buscándolos por nombre
> **para** armar la venta rápidamente sin escribir precios a mano.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 2

**Criterios de aceptación**

1. **Escenario: agregar por código de barras**
   - **Dado** que tengo la pantalla de venta abierta y el cursor en el campo de búsqueda
   - **Cuando** escaneo el código de barras de un producto activo con stock disponible
   - **Entonces** el producto se agrega al carrito con cantidad 1 y su precio vigente
   - **Y** el cursor vuelve al campo de búsqueda listo para el siguiente escaneo.

2. **Escenario: agregar por nombre**
   - **Dado** que escribo al menos 3 caracteres en el campo de búsqueda
   - **Cuando** el sistema muestra la lista de coincidencias y selecciono una
   - **Entonces** el producto se agrega al carrito con su precio vigente.

3. **Escenario: producto repetido**
   - **Dado** que el producto "Arroz 1kg" ya está en el carrito con cantidad 2
   - **Cuando** lo agrego nuevamente
   - **Entonces** la línea existente pasa a cantidad 3 y no se crea una línea duplicada.

4. **Escenario: producto inexistente o inactivo**
   - **Cuando** busco un código que no existe o corresponde a un producto inactivo
   - **Entonces** el sistema muestra "Producto no encontrado o inactivo" y no agrega nada.

**Definición de Terminado**

- El precio se toma siempre del catálogo, nunca se digita.
- La operación es 100% operable con teclado.
- Pruebas unitarias del servicio de carrito y prueba de integración de la búsqueda.

---

### HU-11 — Cobrar la venta y calcular el vuelto

> **Como** cajero
> **quiero** registrar el cobro de la venta y ver el vuelto calculado
> **para** cerrar la operación sin errores de aritmética.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 2

**Criterios de aceptación**

1. **Escenario: cobro en efectivo con vuelto**
   - **Dado** un carrito con total 45.50 y método de pago "Efectivo"
   - **Cuando** ingreso 50.00 como monto recibido y confirmo
   - **Entonces** el sistema registra la venta, muestra vuelto = 4.50 y limpia el carrito.

2. **Escenario: monto insuficiente**
   - **Dado** un carrito con total 45.50
   - **Cuando** ingreso 40.00 como monto recibido
   - **Entonces** el sistema muestra "El monto recibido es menor al total" y **no** registra la venta.

3. **Escenario: carrito vacío**
   - **Cuando** intento cobrar sin ítems en el carrito
   - **Entonces** el botón de cobro está deshabilitado.

4. **Escenario: atomicidad**
   - **Dado** que ocurre un error al descontar el stock durante el cobro
   - **Entonces** la venta completa se revierte (no queda venta, ni detalle, ni movimiento de stock)
   - **Y** se muestra un mensaje de error al usuario.

**Definición de Terminado**

- La venta, su detalle, sus pagos y los movimientos de inventario se guardan dentro de una
  única transacción de base de datos.
- La venta queda asociada al usuario, a la sesión de caja y a la fecha/hora del servidor.
- El total contra el que se compara el monto recibido es el total **con impuesto agregado**:
  el precio del producto es la base imponible y el impuesto se suma al final
  (`total = subtotal − descuento + impuesto`).

---

### HU-17 — Descontar el stock automáticamente al confirmar la venta

> **Como** administrador
> **quiero** que el stock se descuente en el momento en que se confirma la venta
> **para** que el inventario del sistema refleje siempre la realidad del estante.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 2

**Criterios de aceptación**

1. **Escenario: descuento correcto**
   - **Dado** que el producto "Aceite 1L" tiene stock 20
   - **Cuando** se confirma una venta de 3 unidades de ese producto
   - **Entonces** el stock del producto queda en 17
   - **Y** se registra un movimiento de inventario de tipo `SALIDA`, cantidad 3, referenciando el ID de la venta.

2. **Escenario: trazabilidad**
   - **Dado** un movimiento de inventario generado por una venta
   - **Cuando** consulto el kardex del producto
   - **Entonces** veo la fecha, el tipo de movimiento, la cantidad, el stock resultante, el usuario y el número de comprobante.

3. **Escenario: venta anulada**
   - **Cuando** se anula una venta ya registrada
   - **Entonces** se genera un movimiento de tipo `ENTRADA` por las mismas cantidades
   - **Y** el stock vuelve a su valor previo.

**Definición de Terminado**

- El descuento ocurre en la misma transacción que la venta.
- El registro de movimientos es de solo inserción (nunca se actualiza ni se borra).

---

### HU-18 — Bloquear la venta de un producto sin stock suficiente

> **Como** administrador
> **quiero** que el sistema impida vender más unidades de las que hay en inventario
> **para** evitar comprometer mercadería que no existe y no descuadrar el stock.

**Prioridad:** Must · **Puntos:** 3 · **Sprint:** 2

**Criterios de aceptación**

1. **Escenario: cantidad mayor al stock**
   - **Dado** que el producto "Leche 1L" tiene stock 4
   - **Cuando** intento poner cantidad 5 en el carrito
   - **Entonces** el sistema muestra "Stock insuficiente. Disponible: 4" y mantiene la cantidad en 4.

2. **Escenario: stock en cero**
   - **Cuando** agrego al carrito un producto con stock 0
   - **Entonces** el sistema no lo agrega y muestra "Producto sin stock".

3. **Escenario: validación en el servidor**
   - **Dado** que otro cajero vendió las últimas unidades mientras yo armaba mi carrito
   - **Cuando** confirmo el cobro
   - **Entonces** el servidor revalida el stock, rechaza la venta e indica qué ítem quedó sin stock.

---

### HU-13 — Emitir comprobante numerado con serie y correlativo

> **Como** cajero
> **quiero** que cada venta genere un comprobante con serie y número correlativo
> **para** entregar al cliente un documento formal y mantener el orden contable.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 3

**Criterios de aceptación**

1. **Escenario: correlativo automático**
   - **Dado** que la serie "R001" de recibos tiene como último número el 000125
   - **Cuando** se emite el comprobante de una nueva venta con ese tipo de documento
   - **Entonces** el comprobante queda con número "R001-000126"
   - **Y** el correlativo de la serie se incrementa.

2. **Escenario: sin saltos ni duplicados**
   - **Dado** que dos cajeros confirman una venta en el mismo instante
   - **Entonces** cada comprobante obtiene un número distinto y consecutivo, sin huecos.

3. **Escenario: la serie determina el documento**
   - **Dado** que cada serie pertenece a un tipo de comprobante (F001 → Factura, R001 → Recibo)
   - **Cuando** el cajero elige el tipo de documento
   - **Entonces** el sistema usa la serie configurada para ese tipo
   - **Y** no es posible emitir una factura numerada con una serie de recibos.

4. **Escenario: contenido del comprobante**
   - **Entonces** el comprobante muestra: datos del negocio, tipo y número de documento,
     fecha y hora, cajero, cliente (o "Cliente varios"), detalle de ítems con cantidad,
     precio unitario e importe, subtotal, impuesto, descuento, total, método de pago y vuelto.

**Definición de Terminado**

- La asignación del correlativo se realiza con bloqueo de fila (`SELECT ... FOR UPDATE`)
  dentro de la transacción de la venta.
- Una venta no puede tener más de un comprobante emitido.

---

### HU-39 — Registrar el cliente como persona natural o jurídica

> **Como** administrador
> **quiero** registrar a mis clientes diferenciando persona natural de persona jurídica
> **para** pedirle a cada uno los datos que corresponden y poder emitirle el documento correcto.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 3

**Criterios de aceptación**

1. **Escenario: alta de persona natural**
   - **Dado** que elijo el tipo de persona "Natural"
   - **Entonces** el formulario pide **nombres, apellidos** y documento DNI, CE o pasaporte
   - **Y** oculta razón social, nombre comercial y representante legal
   - **Cuando** guardo con nombres y apellidos completos
   - **Entonces** el cliente queda registrado y se muestra como "Carlos Mendoza Ríos".

2. **Escenario: alta de persona jurídica**
   - **Dado** que elijo el tipo de persona "Jurídica"
   - **Entonces** el formulario pide **razón social, RUC y dirección fiscal** como obligatorios,
     y opcionalmente nombre comercial y representante legal
   - **Y** oculta nombres, apellidos y fecha de nacimiento
   - **Cuando** guardo, el cliente se muestra por su razón social.

3. **Escenario: datos incompletos**
   - **Cuando** intento guardar una persona jurídica sin RUC o sin dirección fiscal
   - **Entonces** el sistema no guarda y señala los campos faltantes.

4. **Escenario: documento duplicado**
   - **Cuando** registro un RUC o DNI que ya existe
   - **Entonces** el sistema muestra "Ya existe un cliente con ese documento".

5. **Escenario: búsqueda unificada**
   - **Cuando** busco un cliente desde la pantalla de venta
   - **Entonces** puedo encontrarlo por documento o por nombre, sin importar su tipo de persona
   - **Y** el resultado muestra una etiqueta que indica si es Natural o Jurídica.

**Definición de Terminado**

- Las reglas de obligatoriedad se validan en el cliente, en el servidor **y** en la base de
  datos (`ck_clientes_natural`, `ck_clientes_juridica`).
- El listado de clientes permite filtrar por tipo de persona.

---

### HU-40 — Emitir factura a persona jurídica y recibo a persona natural

> **Como** cajero
> **quiero** que el sistema emita el documento que corresponde al tipo de cliente
> **para** entregar factura a las empresas y recibo a los clientes comunes, sin equivocarme.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 3

**Criterios de aceptación**

1. **Escenario: cliente jurídico → factura**
   - **Dado** que la venta tiene asociado un cliente de tipo Jurídica
   - **Cuando** confirmo la venta
   - **Entonces** el sistema propone **Factura** con la serie F001
   - **Y** el documento se emite con la razón social, el RUC, la dirección fiscal y el
     desglose de impuesto por ítem.

2. **Escenario: cliente natural → recibo**
   - **Dado** que la venta tiene asociado un cliente de tipo Natural, o ningún cliente
   - **Cuando** confirmo la venta
   - **Entonces** el sistema propone **Recibo** con la serie R001
   - **Y** el documento se emite con el nombre completo del cliente, o "Cliente varios" si no
     se registró ninguno.

3. **Escenario: combinación inválida**
   - **Cuando** intento emitir una factura a un cliente de tipo Natural
   - **Entonces** el sistema rechaza la emisión con el mensaje "El tipo de comprobante no
     corresponde al tipo de persona del cliente"
   - **Y** no se consume el correlativo de la serie ni se descuenta stock.

4. **Escenario: factura sin cliente identificado**
   - **Cuando** intento emitir una factura sin haber seleccionado un cliente
   - **Entonces** el sistema exige seleccionar o registrar un cliente jurídico con RUC.

5. **Escenario: datos congelados en el documento**
   - **Dado** una factura ya emitida a "Servicios Generales Perú S.A.C."
   - **Cuando** después se corrige la razón social o la dirección de ese cliente
   - **Entonces** la factura emitida conserva los datos que tenía al momento de emitirse.

6. **Escenario: anulación**
   - **Cuando** se anula la venta
   - **Entonces** el comprobante queda en estado "Anulado", conserva su número y no se
     reutiliza el correlativo.

**Definición de Terminado**

- La correspondencia documento ↔ tipo de persona se valida en la base de datos
  (`trg_comprobantes_before_insert`), no solo en la interfaz.
- Una venta no puede tener dos comprobantes (índice único sobre `venta_id`).
- La emisión ocurre dentro de la misma transacción de la venta.

---

### HU-43 — Vender y emitir recibo sin registrar al cliente

> **Como** cajero
> **quiero** cobrar y entregar el recibo sin tener que registrar al cliente
> **para** no demorar la atención en el mostrador, que es la mayoría de las ventas.

**Prioridad:** Must · **Puntos:** 2 · **Sprint:** 3

**Criterios de aceptación**

1. **Escenario: venta al paso (camino por defecto)**
   - **Dado** que tengo productos en el carrito y **no** seleccioné ningún cliente
   - **Cuando** cobro la venta
   - **Entonces** el sistema registra la venta sin cliente y emite el **recibo**
   - **Y** el recibo sale a nombre de "Cliente varios"
   - **Y** en ningún momento se me pide un dato del cliente.

2. **Escenario: el campo cliente nunca bloquea**
   - **Dado** que estoy en la pantalla de venta
   - **Entonces** el campo de cliente aparece vacío con el texto "Cliente varios"
   - **Y** el botón de cobro está habilitado sin haberlo tocado.

3. **Escenario: el cliente pide factura**
   - **Cuando** cambio el tipo de documento a Factura
   - **Entonces** recién ahí el sistema exige seleccionar o registrar un cliente jurídico con
     RUC y dirección fiscal
   - **Y** si cancelo, la venta vuelve a recibo sin cliente y puedo cobrar de inmediato.

4. **Escenario: cliente opcional identificado**
   - **Cuando** sí selecciono un cliente persona natural
   - **Entonces** el recibo sale a su nombre y la venta aparece en su historial de compras.

5. **Escenario: reportes**
   - **Entonces** las ventas sin cliente se contabilizan normalmente en los totales del día
   - **Y** no aparece ningún "cliente" ficticio encabezando el reporte de mejores clientes.

**Definición de Terminado**

- La venta sin cliente se guarda con `cliente_id = NULL`; **no** existe un registro de
  cliente genérico en el maestro.
- El texto impreso proviene de `configuracion.cliente_generico_nombre`, es editable y no
  está escrito en el código.
- Todas las consultas que muestran el cliente de una venta usan `LEFT JOIN`.

---

### HU-42 — Sustituir el recibo por una factura cuando el cliente la solicita después

> **Como** cajero
> **quiero** reemplazar el recibo ya entregado por una factura a nombre de la empresa del cliente
> **para** resolver el pedido sin anular una venta que está correcta.

**Prioridad:** Should · **Puntos:** 5 · **Sprint:** 5

**Contexto:** la venta se cobró al paso y salió con recibo; el cliente vuelve y dice que era
para su empresa. La mercadería salió y el dinero entró: la venta está bien, lo único que
cambia es el documento.

**Criterios de aceptación**

1. **Escenario: sustitución exitosa**
   - **Dado** una venta cobrada con el recibo "R001-000002" emitido hoy
   - **Cuando** busco la venta, elijo "Cambiar comprobante", selecciono Factura y registro o
     selecciono al cliente jurídico con RUC
   - **Entonces** se emite la factura "F001-000002" a nombre de la empresa
   - **Y** el recibo queda en estado "Sustituido" y deja de ser el documento vigente
   - **Y** la venta queda asociada a ese cliente.

2. **Escenario: la venta no se toca**
   - **Entonces** el stock **no** se modifica, los pagos **no** se modifican y el total de
     ventas del día **no** cambia
   - **Y** la venta sigue contando una sola vez en los reportes.

3. **Escenario: correlativo nuevo**
   - **Entonces** la factura toma el siguiente número de su propia serie
   - **Y** el número del recibo sustituido no se reutiliza ni se borra.

4. **Escenario: motivo y trazabilidad obligatorios**
   - **Cuando** confirmo la sustitución
   - **Entonces** el sistema exige un motivo
   - **Y** registra en la bitácora quién la hizo, cuándo, qué documento reemplazó a cuál.

5. **Escenario: fuera de plazo**
   - **Dado** que la venta es de hace más de los días permitidos en la configuración
   - **Cuando** intento sustituir el comprobante
   - **Entonces** el sistema lo rechaza indicando que la venta excede el plazo permitido.

6. **Escenario: venta anulada**
   - **Cuando** intento sustituir el comprobante de una venta anulada o devuelta
   - **Entonces** el sistema lo rechaza: primero se corrige la venta, no el documento.

7. **Escenario: sustituir dos veces**
   - **Cuando** intento sustituir un comprobante que ya fue sustituido
   - **Entonces** el sistema lo rechaza; solo el documento vigente puede reemplazarse
   - **Y** sí puedo sustituir el nuevo documento vigente si hiciera falta.

8. **Escenario: consulta del historial**
   - **Cuando** abro el detalle de la venta
   - **Entonces** veo el documento vigente y, debajo, el historial de documentos anteriores
     con su motivo y su fecha de sustitución.

**Definición de Terminado**

- La operación completa ocurre en una transacción; si la emisión de la factura falla (por
  ejemplo, el cliente no es persona jurídica), el recibo original **sigue siendo el vigente**.
- El invariante "un solo comprobante vigente por venta" lo garantiza la base de datos
  (`uq_comprobante_vigente`), no la aplicación.
- Requiere autorización de un usuario con rol Administrador.
- Ningún comprobante se elimina ni se edita: la cadena queda consultable en
  `v_comprobantes_sustituidos`.

---

### HU-27 — Cerrar caja con arqueo y cálculo de diferencia

> **Como** cajero
> **quiero** cerrar mi caja declarando el efectivo contado
> **para** que quede registrada la diferencia respecto de lo que el sistema esperaba.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 4

**Criterios de aceptación**

1. **Escenario: cierre cuadrado**
   - **Dado** un turno con monto inicial 100.00, ventas en efectivo por 850.00, ingresos por 0.00 y egresos por 50.00
   - **Cuando** declaro 900.00 como efectivo contado
   - **Entonces** el sistema calcula esperado = 900.00, diferencia = 0.00 y marca el cierre como "Cuadrado".

2. **Escenario: faltante**
   - **Cuando** declaro 880.00 sobre un esperado de 900.00
   - **Entonces** la diferencia es -20.00, el cierre se marca como "Faltante" y se exige un comentario obligatorio.

3. **Escenario: caja ya cerrada**
   - **Cuando** intento vender con una sesión de caja cerrada
   - **Entonces** el sistema me obliga a abrir una nueva caja.

4. **Escenario: resumen del turno**
   - **Entonces** el cierre muestra el total vendido desglosado por método de pago, la
     cantidad de ventas, las anulaciones y las devoluciones del turno.

---

### HU-30 — Registrar una devolución total o parcial

> **Como** administrador
> **quiero** registrar la devolución de uno o varios productos de una venta
> **para** devolver el dinero al cliente y reingresar la mercadería al inventario de forma auditable.

**Prioridad:** Should · **Puntos:** 8 · **Sprint:** 5

**Criterios de aceptación**

1. **Escenario: devolución parcial**
   - **Dado** una venta con 5 unidades de "Detergente 1kg"
   - **Cuando** registro la devolución de 2 unidades con motivo "Producto en mal estado"
   - **Entonces** se genera una devolución por el importe de esas 2 unidades
   - **Y** el stock del producto aumenta en 2 con un movimiento de tipo `ENTRADA`
   - **Y** la venta queda con estado "Devolución parcial".

2. **Escenario: devolución total**
   - **Cuando** devuelvo todos los ítems de la venta
   - **Entonces** la venta queda con estado "Devuelta" y el importe devuelto iguala al total.

3. **Escenario: límite de cantidad**
   - **Cuando** intento devolver más unidades de las vendidas (o de las que quedan por devolver)
   - **Entonces** el sistema rechaza la operación indicando la cantidad máxima devolvible.

4. **Escenario: autorización**
   - **Dado** que soy cajero
   - **Cuando** intento registrar una devolución
   - **Entonces** el sistema solicita la autorización de un usuario con rol Administrador
   - **Y** guarda en la bitácora quién autorizó.

---

### HU-22 — Alerta de productos en stock mínimo

> **Como** almacenero
> **quiero** ver qué productos llegaron a su stock mínimo
> **para** reponerlos antes de que se agoten.

**Prioridad:** Must · **Puntos:** 3 · **Sprint:** 3

**Criterios de aceptación**

1. **Escenario: listado de alertas**
   - **Dado** que "Azúcar 1kg" tiene stock 5 y stock mínimo 10
   - **Cuando** ingreso al panel de alertas
   - **Entonces** el producto aparece en la lista con su stock actual, su mínimo y la diferencia a reponer.

2. **Escenario: indicador en el dashboard**
   - **Entonces** el dashboard muestra la cantidad de productos en alerta y permite ir al listado con un clic.

3. **Escenario: salida de la alerta**
   - **Cuando** se registra un ingreso que deja el stock por encima del mínimo
   - **Entonces** el producto desaparece del listado de alertas.

---

### HU-32 — Reporte de ventas por rango de fechas

> **Como** administrador
> **quiero** consultar las ventas de un período con filtros
> **para** conocer el desempeño del negocio y detectar irregularidades.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 5

**Criterios de aceptación**

1. **Escenario: consulta por período**
   - **Cuando** selecciono un rango de fechas
   - **Entonces** veo el listado de ventas con fecha, comprobante, cliente, cajero, método de pago, estado y total
   - **Y** veo los totales: número de ventas, monto total, ticket promedio y monto anulado/devuelto.

2. **Escenario: filtros combinados**
   - **Cuando** filtro además por cajero y por método de pago
   - **Entonces** los resultados y los totales reflejan solo las ventas que cumplen todos los filtros.

3. **Escenario: ventas anuladas**
   - **Entonces** las ventas anuladas se muestran marcadas y **no** se suman al monto total vendido.

4. **Escenario: sin resultados**
   - **Cuando** no hay ventas en el período
   - **Entonces** se muestra "No se encontraron ventas para los filtros seleccionados".

---

## 2.6 Definición de Terminado (global)

Una historia se considera terminada cuando:

1. Cumple todos sus criterios de aceptación.
2. El código fue revisado por otro integrante del equipo.
3. Tiene pruebas automatizadas de la lógica de negocio y estas pasan.
4. Valida los datos tanto en el cliente como en el servidor.
5. Respeta el control de acceso por rol.
6. Las operaciones que tocan dinero o stock se ejecutan en transacción.
7. Está documentada y desplegada en el ambiente de pruebas.
