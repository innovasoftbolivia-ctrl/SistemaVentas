# Sistema de Venta de Productos

Aplicación web del sistema descrito en [`../docs`](../docs). Lo entregado hasta ahora:

- **Personal, seguridad y usuarios:** ingreso al sistema, empleados, cargos, cuentas, roles y permisos.
- **Catálogo:** productos con foto, precios y stock; categorías, unidades de medida y
  proveedores, con kardex por producto.
- **Punto de venta:** mostrador con lector de código de barras, carrito, descuento, cobro y
  vuelto; caja por turno con arqueo; comprobantes (factura y recibo) imprimibles; clientes;
  anulación de ventas.
- **Devoluciones:** totales y parciales, con reingreso selectivo de stock.
- **Sustitución de comprobante:** recibo → factura (y al revés) sin tocar la venta.
  La parte **tributaria** (tasa e identificación fiscal) está marcada como en construcción.
- **Reportes:** ventas por día, método de pago y cajero; ranking de productos y alertas de stock.
- **Portada:** turno propio, resumen del día y alertas, con los bloques que cada rol puede ver.

**Stack:** Laravel 13 · Blade · Alpine.js · Tailwind CSS 4 · MySQL 8
La interfaz usa la plantilla [TailAdmin](https://tailadmin.com/) para Laravel.

> Al ingresar, cada cuenta va a **su pantalla de trabajo** (`App\Support\Menu::inicio()`): quien
> lleva la gestión, a la portada; el cajero, directo al mostrador.

---

## Puesta en marcha

Hay dos formas, y basta con una. **Con Docker** no hay que instalar PHP ni Node en la máquina;
**a mano** es más ligero si ya los tienes y prefieres los procesos a la vista.

### Opción A — todo con Docker (recomendada)

Desde la **raíz del repositorio** (un nivel arriba de esta carpeta):

```bash
docker compose up -d --build
```

Y ya está: la aplicación queda en <http://localhost:8100>. La primera vez tarda unos minutos,
porque construye la imagen de PHP, carga `docs/sql` en MySQL e instala las dependencias de
Composer y npm dentro de los contenedores.

| Servicio | Dónde | Qué es |
|----------|-------|--------|
| Aplicación | <http://localhost:8100> | nginx + php-fpm |
| Adminer | <http://localhost:8101> | explorar la base sin instalar nada |
| MySQL | `localhost:3307` | `root` / `ventas123`, base `ventas_db` |
| Vite | puerto 5174 | lo consume el navegador solo; no se abre a mano |

Los puertos van en el rango 81xx para no chocar con los otros proyectos del repositorio.

El contenedor `app` se encarga solo del `composer install`, del `storage:link` y del seeder de
credenciales, así que no hay ningún paso manual detrás. Para seguir el arranque:

```bash
docker compose logs -f app
```

Órdenes de artisan dentro del contenedor:

```bash
docker compose exec app php artisan route:list
```

`docker compose down` detiene y conserva los datos; `docker compose down -v` los borra.

> La configuración del entorno Docker vive en `sistema-ventas/.env.docker`. Llega a los
> contenedores como variables de entorno reales y Laravel les da prioridad sobre el `.env`
> del proyecto, que sigue siendo el bueno para la opción B.

### Opción B — a mano

#### 1. Base de datos

El esquema y los datos de ejemplo viven en `docs/sql` y los carga Docker al primer arranque.
Desde la **raíz del repositorio** (un nivel arriba de esta carpeta):

```bash
docker compose up -d mysql adminer
```

Deja MySQL en `localhost:3307` (usuario `root`, contraseña `ventas123`, base `ventas_db`) y
Adminer en <http://localhost:8101>.

Para volver a cargar el esquema a mano:

```bash
docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 < docs/sql/01_schema_mysql.sql
```

> El `--default-character-set=utf8mb4` no es opcional: sin él los acentos entran doblemente
> codificados y «Díaz» se guarda como «DÃ­az».

Una base creada antes del 2026-08-21 necesita los parches de `docs/sql/parches` (los instaladores
nuevos no: `01_schema_mysql.sql` ya los incorpora). Se aplican en orden y son idempotentes:

```bash
docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 ventas_db < docs/sql/parches/2026_08_21_devolucion_con_impuesto.sql
```

```bash
docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 ventas_db < docs/sql/parches/2026_08_21_mas_vendidos_neto.sql
```

Los cuatro del 2026-08-23 no corrigen nada del esquema: dejan el sistema listo para el negocio
real. Son opcionales —con los 12 productos de demostración el sistema funciona igual— y ninguna
instalación los aplica sola. **El orden importa**: los de catálogo escriben los precios y el
último alinea el resto sobre ellos.

Cargan el catálogo real: 70 productos en «Abarrotes» y otros 70 en «Bebidas». Re-ejecutarlos
repone los precios de este repositorio, así que pisan los que el negocio haya retocado a mano:

```bash
docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 ventas_db < docs/sql/parches/2026_08_23_abarrotes_catalogo_real.sql
```

```bash
docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 ventas_db < docs/sql/parches/2026_08_23_bebidas_catalogo_real.sql
```

Saca los cigarrillos de «Bebidas», donde entran solo porque el catálogo de origen los lista ahí:

```bash
docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 ventas_db < docs/sql/parches/2026_08_23_categoria_cigarrillos.sql
```

Y este pone `tasa_impuesto` en 0: el precio que el negocio carga en el producto es el que paga el
cliente, sin recargo. Va al final, porque ajusta los precios de demostración y los costos del
kardex sobre lo que dejaron los parches anteriores:

```bash
docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 ventas_db < docs/sql/parches/2026_08_23_sin_impuesto.sql
```

Las vistas se adaptan solas a ese cero: el POS deja de mostrar la línea «Impuesto», y la ficha del
producto pide un único precio en vez de base y estante. Si algún día hace falta desglosar el IVA
(13% en Bolivia), basta con volver a poner la tasa en `configuracion` y recalcular los precios
base; los comprobantes ya emitidos no cambian, porque cada línea de venta congela su propia tasa.

#### 2. Aplicación

```bash
composer install
```

```bash
npm install
```

```bash
php artisan db:seed --class=CredencialesSeeder
```

El seeder es necesario porque `docs/sql/02_datos_iniciales.sql` deja un hash de ejemplo que no
corresponde a ninguna contraseña real.

```bash
php artisan storage:link
```

Ese enlace es el que hace visibles las fotos de los productos. Se crea una sola vez.

#### 3. Levantar

En dos terminales:

```bash
php artisan serve
```

```bash
npm run dev
```

La aplicación queda en <http://localhost:8000> y Vite en el 5173. Para trabajar sin Vite en
marcha, `npm run build` y sirve los assets ya compilados.

> Nada de esto lo levanta Docker en la opción B: ahí Docker solo aporta la base de datos. Si
> `localhost:8000` rechaza la conexión, es que falta `php artisan serve`.

### Cuentas de desarrollo

| Usuario   | Contraseña   | Rol           | Empleado           |
|-----------|--------------|---------------|--------------------|
| `admin`   | `admin123`   | Administrador | Ana Quispe Torres  |
| `cajero1` | `cajero123`  | Cajero        | Luis Ramos Vega    |
| `almacen` | `almacen123` | Almacenero    | Marta Flores Díaz  |

---

## Cómo está organizado

### Los tres conceptos que no se mezclan

El esquema separa a propósito tres cosas que suelen confundirse:

| Concepto     | Tabla       | Qué representa                                              |
|--------------|-------------|-------------------------------------------------------------|
| **Cargo**    | `cargos`    | La función laboral: Gerente, Cajero, Almacenero, Ayudante.   |
| **Empleado** | `empleados` | La persona y su vínculo laboral: ingreso, contrato, cese.    |
| **Usuario**  | `usuarios`  | La cuenta con la que se entra al sistema, y su rol de acceso.|

De ahí salen dos reglas que el código respeta en todas partes:

- Un empleado **puede no tener cuenta** (Jorge, el ayudante, trabaja sin usar el sistema).
- El **cargo no determina el rol**: la gerente Ana tiene rol Administrador, pero un cajero de
  confianza también podría tenerlo.

Y una consecuencia importante: `empleados.estado` (vínculo laboral) manda sobre `usuarios.activo`
(acceso). Al cesar o suspender a un empleado, un trigger de la base desactiva su cuenta. Lo
contrario no ocurre: quitarle el acceso a alguien no lo despide.

### Autenticación

- Se entra con **nombre de usuario**, no con correo: la cuenta vive en `usuarios` y la persona en
  `empleados`.
- `App\Models\Usuario` mapea la columna `password_hash` mediante `getAuthPassword()`, así que el
  guard de sesión estándar de Laravel funciona sin cambios.
- El acceso exige dos condiciones: la cuenta activa **y** el empleado con vínculo vigente
  (`Usuario::puedeIngresar()`).
- Tras cinco intentos fallidos, ese usuario e IP quedan bloqueados un minuto.
- `VerificarCuentaVigente` corta la sesión si la cuenta se deshabilita mientras la persona navega.

### Permisos

Cada rol agrupa permisos (`rol_permiso`). Las rutas se protegen con el middleware `permiso`:

```php
Route::middleware('permiso:usuarios.gestionar')->group(function () { ... });
```

En las vistas, la directiva `@puede('usuarios.gestionar')` esconde lo que la cuenta no puede usar,
y `App\Support\Menu` arma con esos mismos códigos la barra lateral.

### Precios: qué se guarda y qué ve el cliente

`productos.precio_compra` y `productos.precio_venta` se guardan **sin impuesto**. El precio de
estante —lo que paga el cliente— se calcula al vuelo con la tasa vigente en `configuracion`:

```
precio_estante = precio_venta * (1 + tasa_impuesto)      // si el producto está afecto
```

El formulario de producto calcula en ambos sentidos: escribes la base y sale el estante, o
escribes el estante y sale la base. Así los precios quedan redondos en la etiqueta sin sacar la
calculadora. Todo cambio de `precio_venta` se audita con la acción `CAMBIO_PRECIO` (causa C3 del
análisis: precios no centralizados).

`App\Support\Config` lee esos parámetros del negocio (tasa, moneda, nombre del local) una sola
vez por petición.

### La moneda

Sale de `configuracion` y no está escrita en ningún sitio del código:

| Clave | Valor | Para qué |
|-------|-------|----------|
| `moneda_simbolo` | `Bs` | lo que se muestra en pantalla |
| `moneda_codigo` | `BOB` | lo que se congela en cada comprobante emitido |

Para cambiarla basta actualizar esas dos filas. Ojo con una cosa: `comprobantes.moneda` guarda el
código **del momento de emitir**, así que un documento viejo sigue mostrando su símbolo aunque el
negocio cambie de moneda —es una foto, no una referencia—. De eso se encarga
`Config::simbolo($codigo)`, que traduce el código ISO y, si no lo conoce, lo muestra tal cual:
mejor «CLP 1.200» que un símbolo equivocado.

> La **tasa de impuesto** es un parámetro aparte (`tasa_impuesto`) y no se toca al cambiar de
> moneda: hacerlo alteraría el precio de estante de todo el catálogo.

### Lo tributario está en construcción

La tasa de impuesto (hoy **18%**) y la identificación fiscal del negocio (rotulada **RUC**) siguen
siendo las provisionales con las que se armó el esquema, y no corresponden a la normativa
boliviana. Está **a propósito sin cerrar**, y el sistema lo dice en vez de aparentar lo contrario:

- cada documento sale impreso con un recuadro «EN CONSTRUCCIÓN — sin validez tributaria»;
- el listado de comprobantes lleva el mismo aviso arriba;
- el formulario del producto marca la tasa como provisional junto a la casilla de impuesto.

Lo que **sí** está terminado es la mecánica: series y correlativos con bloqueo de fila, desglose
del impuesto por línea, sustitución y anulación de documentos, y la trazabilidad completa. Cerrar
la parte tributaria es cambiar `configuracion.tasa_impuesto`, el rótulo del documento fiscal y —si
se quiere— el `ENUM` de `clientes.tipo_documento`; después se puede quitar el aviso, que vive en
el componente `<x-ui.en-construccion>`.

La facturación electrónica ante la administración tributaria queda fuera de alcance en la versión
1, como dice `docs/01-problematica.md`.

### Fotos de los productos

`productos.imagen` guarda la **ruta relativa** dentro del disco `public`
(`productos/xxx.jpg`), nunca la URL: así el archivo se sigue encontrando aunque cambie el dominio.
Requiere el enlace simbólico de Laravel, que se crea una sola vez:

```bash
php artisan storage:link
```

La foto entra **entera, sin recortar**: el recuadro tiene medida fija —para que la cuadrícula no
se descuadre— y la imagen se acomoda dentro con `object-scale-down`. Da igual si es vertical,
apaisada o cuadrada; y una imagen pequeña no se agranda hasta verse pixelada, cosa que
`object-contain` sí haría. El fondo del recuadro se mantiene claro en ambos temas, porque casi
todas las fotos de producto vienen recortadas sobre blanco.

Se sube desde el formulario del producto (JPG, PNG o WEBP, hasta 2 MB) con vista previa antes de
guardar, y se puede quitar. Al reemplazar o quitar una foto, **el archivo anterior se borra del
disco**: si no, la carpeta se llena de imágenes que ya no referencia nadie.

La foto aparece en el listado del catálogo, en la ficha del producto y —donde más se nota— en las
tarjetas del mostrador. Lo que no tiene foto muestra un marcador del mismo tamaño, para que la
cuadrícula no se descuadre.

> `Producto::imagen_url` usa `asset()` y **no** `Storage::url()`. El segundo arma la dirección con
> `APP_URL`, y como el sistema se abre desde varias máquinas de la red del negocio (RNF3), con
> `APP_URL=http://localhost` las fotos se romperían en todas menos en el servidor. `asset()` toma
> el host de la petición en curso.

### Stock: nunca se escribe a mano

`productos.stock_actual` **no es un campo editable**. Cambia únicamente a través de
`App\Services\Inventario`, que en una transacción bloquea la fila del producto, actualiza el
saldo y escribe un movimiento en `movimientos_inventario` con el stock antes y después, el
responsable y el motivo. De ahí salen tres operaciones:

| Operación | Origen en el kardex | Permiso |
|-----------|---------------------|---------|
| Stock inicial al crear el producto | `INICIAL` | `productos.gestionar` |
| Ingreso de mercadería (con guía o factura del proveedor) | `COMPRA` | `inventario.ingresar` |
| Ajuste por conteo físico (motivo obligatorio) | `AJUSTE` | `inventario.ajustar` |

El ajuste se registra indicando **cuántas unidades hay realmente**; el sistema calcula la
diferencia. Si el conteo coincide con el sistema, no se escribe ningún movimiento.

La unidad de medida manda sobre la cantidad: si `permite_decimal` es falso, el sistema rechaza
un ingreso de 2.5 unidades. Y ningún movimiento puede dejar el stock en negativo.

Nótese que gestionar el catálogo y mover stock son permisos distintos: el almacenero puede hacer
ambas cosas, pero un rol podría crear productos sin poder tocar el inventario.

### La venta: qué hace la aplicación y qué hace la base

Este módulo delega a propósito en el esquema. `App\Services\Ventas` abre una transacción y
orquesta; el resto ya vive en `docs/sql`:

| Paso | Quién lo hace |
|------|---------------|
| Validar stock, descontarlo y escribir el kardex | trigger `trg_venta_detalle_after_insert` |
| Copiar el régimen y la tasa de impuesto a cada línea | trigger `trg_venta_detalle_before_insert` |
| Calcular subtotal e impuesto desde el detalle | `sp_recalcular_venta` |
| Tomar el correlativo con bloqueo de fila | `sp_siguiente_comprobante` |
| Emitir el documento y congelar los datos del cliente | `sp_emitir_comprobante` |
| Comprobar que el tipo de documento corresponde al cliente | trigger `trg_comprobantes_before_insert` |
| Revertir stock y anular el documento | `sp_anular_venta` |
| Sustituir el documento por otro (recibo → factura) | `sp_sustituir_comprobante` |
| Copiar la tasa de impuesto a la línea devuelta | trigger `trg_devolucion_detalle_before_insert` |
| Acumular lo devuelto y reingresar stock | trigger `trg_devolucion_detalle_after_insert` |
| Calcular el efectivo esperado al cerrar caja | `sp_cerrar_caja` |

Reescribir eso en PHP habría dado dos fuentes de verdad que se contradicen con el tiempo.

Un detalle del orden de operaciones: el descuento de cabecera **no** se puede guardar al crear la
venta, porque la base exige `descuento <= subtotal` y el subtotal todavía es cero. Por eso la
secuencia es insertar la venta sin descuento → insertar el detalle → recalcular → aplicar el
descuento → recalcular otra vez (el impuesto baja en la misma proporción).

### Comprobantes

El tipo de documento lo decide **la serie**, y la serie sale de `configuracion`:
persona jurídica → `serie_factura`; el resto → `serie_recibo`. Por eso `comprobantes` no guarda
`tipo_comprobante_id`: sería una segunda fuente de verdad.

Cada documento congela una foto del cliente y de los importes al emitirlo. Nada se borra: un
documento se anula (conservando su correlativo) o se sustituye. Se imprime en ticket de 80 mm o
en A4 desde la misma vista, sin la barra lateral, y los anulados y sustituidos salen con su sello.

**Sustitución (HU-42).** El caso típico: se entregó un recibo y el cliente vuelve pidiendo
factura. La venta no se toca —el dinero ya se cobró, el stock ya salió—: solo cambia el
documento. `sp_sustituir_comprobante` marca el anterior como `SUSTITUIDO`, lo que libera el índice
de «documento vigente», y emite el reemplazo con su propio correlativo, enlazado por
`sustituye_a` y con el motivo en `motivo_emision`. La cadena queda a la vista en la ficha de la
venta.

Las condiciones las pone la base y la aplicación las repite para poder ocultar el botón antes de
intentarlo: el documento debe estar vigente, la venta `COMPLETADA` (ni anulada ni con
devoluciones) y dentro del plazo de `configuracion.dias_max_sustitucion`. Ese plazo se cuenta en
**días de calendario**, con el mismo criterio que el `DATEDIFF` del procedimiento, no en períodos
de 24 horas.

El tipo del reemplazo lo decide el cliente que se le asigne, igual que en la venta: persona
jurídica → factura, el resto → recibo. Funciona en ambos sentidos, porque una factura emitida por
error también hay que poder corregirla. Sustituir es corregir un documento ya entregado, así que
pide el mismo permiso que anular una venta (`ventas.anular`).

### Caja

Una venta necesita un turno abierto: es donde se imputa el dinero. La base impide con un índice
único que una caja tenga dos turnos abiertos a la vez. Al cerrar:

```
esperado = monto_inicial + cobrado en efectivo + ingresos − egresos − devoluciones
```

Solo cuentan los métodos de pago con `afecta_caja = 1`: la tarjeta no deja dinero en el cajón. La
diferencia es una columna generada, así que no puede desincronizarse; no se corrige, se explica.

### Devoluciones

Se registran desde la venta original y admiten devolver solo parte. Otra vez el trigger hace el
trabajo: `trg_devolucion_detalle_after_insert` acumula lo devuelto en la línea de venta,
recalcula el total, mueve la venta a `DEVUELTA_PARCIAL` o `DEVUELTA` y reingresa el stock.

Dos cosas propias de este módulo:

- **`reingresa_stock` por línea.** Lo que vuelve al estante suma inventario; lo que llegó roto o
  vencido se paga igual pero no se puede volver a vender, así que el stock no sube.
- **La devolución se ata a la caja de quien la registra**, no a la de la venta original: el dinero
  sale del cajón de hoy, y así lo descuenta el arqueo.

**El importe devuelto lleva impuesto**, porque es lo que el cliente pagó. `devolucion_detalle`
guarda el mismo desglose que `venta_detalle` —`afecto_impuesto`, `tasa_impuesto` y las columnas
generadas `impuesto_linea` y `total_linea`—, la tasa la copia de la línea de venta original un
trigger BEFORE INSERT (la de aquel día, no la de hoy), y el total de la devolución suma
`total_linea`. Así `sp_cerrar_caja` puede restarlo tal cual del efectivo esperado y la caja cuadra
sola: una venta de S/ 10.90 devuelta por completo deja el cajón como estaba.

> Esto se corrigió el 2026-08-21. Antes el total sumaba solo la base y el arqueo quedaba con un
> sobrante igual al impuesto devuelto. `docs/sql/01_schema_mysql.sql` ya está corregido para las
> instalaciones nuevas; una base que venga de antes se pone al día con
> `docs/sql/parches/2026_08_21_devolucion_con_impuesto.sql`, que además rellena las devoluciones
> ya registradas.

La nota de crédito por devolución de una factura queda fuera de alcance en la versión 1, como dice
`docs/01-problematica.md`.

### Descuentos

`configuracion.descuento_max_cajero` fija el umbral (10%). Por encima hace falta el permiso
`ventas.descuento`, y el mostrador lo avisa antes de dejar cobrar. Todo descuento queda en la
venta con el nombre de quien la registró (objetivo O4).

### La portada

`/inicio` está abierta a todos, pero **cada bloque se arma solo si el rol puede verlo**, y el
controlador ni siquiera consulta lo que no se va a mostrar:

| Bloque | Quién lo ve |
|--------|-------------|
| Mi turno de caja | quien puede vender o abrir caja |
| Lo que llevo vendido hoy | quien puede vender — es su trabajo, no información de gestión |
| Vendido hoy frente a ayer, gráfico de dos semanas, últimas ventas | `reportes.ver` |
| Alertas de reposición | `productos.gestionar` o `reportes.ver` |

Un cajero abre la aplicación y cae en el mostrador, no aquí: es donde pasa el turno y un clic de
más en cada venta se nota. La portada le queda en el menú para consultar cómo va su caja.

La comparación con ayer se omite cuando ayer no hubo ventas, en vez de mostrar un porcentaje
inventado: no se puede dividir por cero.

### Reportes

Dos pantallas, ambas con el mismo filtro de período (por defecto, los últimos 30 días):

- **Ventas:** vendido, neto de devoluciones, ticket promedio e impuesto; evolución diaria;
  desglose por método de pago y por cajero; detalle día a día.
- **Productos e inventario:** valor del stock, alertas de reposición y ranking de más vendidos.

Los agregados salen de las vistas que ya define `docs/sql` —`v_ventas_por_dia`,
`v_ventas_por_metodo_pago`, `v_alertas_stock`—, que son la definición oficial de cada cifra.

Con una salvedad, anotada en el código: **el ranking de más vendidos se repite en PHP.**
`v_productos_mas_vendidos` agrega todo el histórico y no admite rango de fechas, así que el
reporte reproduce sus mismas fórmulas con un filtro. Para que no se separen con el tiempo, una
prueba compara ambos —incluyendo un producto con devolución, que es donde se separarían sin que
nadie lo note— columna por columna: si alguien cambia la vista, la prueba falla.

Las tres cifras del ranking van **netas de devoluciones**. El importe de cada línea se prorratea
por la fracción que el cliente se quedó, el mismo criterio con el que `sp_recalcular_venta`
prorratea el impuesto; así el descuento de línea se reparte solo y no hay que repetir su fórmula.

La serie diaria **rellena con cero los días sin ventas**: si no, el gráfico uniría dos días
lejanos con una recta y aparentaría ventas que no existieron.

Los gráficos son ApexCharts y se cargan bajo demanda: `resources/js/graficos.js` solo importa la
librería si la página trae algún `[data-apexchart]`. La plantilla pone ahí los datos en JSON
—nunca funciones, que no sobreviven a `json_encode`— y el módulo arma las opciones, incluidos los
formateadores de moneda y el tema. Un `MutationObserver` redibuja los gráficos cuando se cambia
entre claro y oscuro.

### Auditoría

`App\Services\Auditor` escribe en la tabla `auditoria` los ingresos, los intentos fallidos y toda
alta, baja o modificación de empleados, cuentas, cargos y roles. Nada se borra en silencio: cuando
un registro tiene historia asociada (un cargo con empleados, una cuenta con operaciones), el
sistema lo **desactiva** en lugar de eliminarlo.

### Se adapta a la pantalla

Probado de 375&nbsp;px (teléfono) a 1440&nbsp;px, sin desbordes horizontales de página en ninguna
pantalla. Lo que cambia según el tamaño:

- **Barra lateral:** completa en escritorio, plegable a iconos, y fuera de pantalla con menú
  hamburguesa por debajo de 1280&nbsp;px. Viene de la plantilla.
- **Tablas:** van dentro de un contenedor con desplazamiento propio, así que nunca desbordan la
  página. Además, en las más anchas (ventas y productos) las columnas secundarias se ocultan por
  tramos —`sm`, `md`, `lg`— y quedan las que identifican la fila y su importe; el resto está a un
  toque, en el detalle.
- **Mostrador:** en escritorio el carrito acompaña el desplazamiento a la derecha. En el teléfono
  queda debajo de la cuadrícula, así que aparece una **barra flotante** con el número de artículos
  y el total, que lleva al carrito de un toque. Sin ella, el cajero tendría que recorrer todo el
  catálogo para ver cuánto lleva.
- **Modales:** todos con `max-h-[90vh]` y desplazamiento interno. Sin eso, en una pantalla baja
  —un teléfono en horizontal— la parte de arriba de un formulario largo queda inalcanzable, porque
  el centrado con flex recorta hacia arriba.
- **Fotos de producto:** ver más abajo; entran enteras sea cual sea su proporción.

### Notas sobre la interfaz

- El tema claro/oscuro y el plegado de la barra lateral son stores de Alpine definidos en
  `resources/views/layouts/partials/head.blade.php`.
- Los componentes reutilizables están en `resources/views/components`: `ui.*` (botón, alerta,
  etiqueta de estado, avatar de iniciales), `form.*` (campo, input, select, textarea, casilla) y
  `common.*` (tarjeta, migas, paginación, avisos flash).
- **Cuidado con `@js()` dentro de atributos de un componente Blade**: no se compila y Alpine
  recibe el texto literal. En esos casos hay que usar un `<button>` normal.
- **Las clases de Tailwind van completas y literales.** Tailwind rastrea el texto de la
  plantilla, así que un `text-{{ $color }}-500` no genera ninguna clase. Cuando el color depende
  de un dato, se guarda la clase entera en el arreglo (ver el resumen de `productos/index`).
- **Ojo con los alias de un `selectRaw` sobre un modelo.** Si el alias coincide con el nombre de
  un accesor (`bajo_minimo` en `Producto`), Eloquent devuelve lo que calcula el accesor sobre una
  fila vacía en vez de la suma. Para agregados conviene el query builder (`DB::table(...)`).

---

## Pruebas

Las pruebas corren contra una copia real de la base, porque el esquema usa columnas generadas,
`ENUM` y triggers que SQLite no reproduce. Se crea una sola vez, desde la raíz del repositorio:

```bash
sed 's/ventas_db/ventas_db_test/g' docs/sql/01_schema_mysql.sql | docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123
```

```bash
sed 's/ventas_db/ventas_db_test/g' docs/sql/02_datos_iniciales.sql | docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123
```

Después:

```bash
php artisan test
```

Cada prueba corre dentro de una transacción que se revierte al terminar, así que los catálogos
quedan intactos.

---

## Mapa del código

```
app/
  Http/Controllers/
    Auth/LoginController.php     ingreso, salida y bloqueo por intentos
    EmpleadoController.php       alta, edición, cese y reactivación
    CargoController.php          catálogo de cargos
    UsuarioController.php        cuentas: alta, rol, acceso, contraseña
    RolController.php            roles y su matriz de permisos
    PerfilController.php         la cuenta propia
    ProductoController.php       catálogo, precios, ingresos y ajustes de stock
    CategoriaController.php      categorías del catálogo
    UnidadMedidaController.php   unidades de venta
    ProveedorController.php      quién abastece el negocio
    PosController.php            el mostrador: búsqueda, carrito y cobro
    VentaController.php          listado, ficha y anulación
    CajaController.php           apertura, movimientos y cierre del turno
    ComprobanteController.php    listado, impresión (ticket 80 mm / A4) y sustitución
    ClienteController.php        persona natural y persona jurídica
    ReporteController.php        reportes de ventas, productos e inventario
    DashboardController.php      la portada, por bloques según el rol
    DevolucionController.php     devoluciones totales y parciales
  Http/Middleware/
    VerificarPermiso.php         corta por permiso de rol
    VerificarCuentaVigente.php   corta si la cuenta dejó de tener acceso
  Models/                        Cargo, Empleado, Rol, Permiso, Usuario, Auditoria,
                                 Categoria, UnidadMedida, Proveedor, Producto,
                                 MovimientoInventario, Cliente, Caja, SesionCaja,
                                 MovimientoCaja, Venta, VentaDetalle, VentaPago,
                                 Comprobante, SerieComprobante, TipoComprobante,
                                 MetodoPago, Devolucion, DevolucionDetalle
  Services/
    Auditor.php                  bitácora
    Inventario.php               único punto por el que cambia el stock
    Ventas.php                   registrar y anular, sobre los procedimientos
    Cajas.php                    abrir, mover efectivo y cerrar el turno
    Devoluciones.php             devolver mercadería de una venta cobrada
    Comprobantes.php             sustituir el documento de una venta
  Support/
    Menu.php                     barra lateral y pantalla de inicio según permisos
    Config.php                   parámetros del negocio (tasa, moneda, formatos)

resources/views/
  layouts/                       app (con barra lateral) y auth (pantalla completa)
  components/                    ui.*, form.*, common.*, header.*
  auth/ empleados/ cargos/ usuarios/ roles/ perfil/
  productos/ categorias/ unidades/ proveedores/
  dashboard.blade.php            la portada
  pos/ ventas/ caja/ comprobantes/ clientes/ devoluciones/ reportes/
```

## Lo que sigue

Del esquema quedan sin explotar `v_kardex` (el kardex se arma hoy desde la ficha del producto),
`v_comprobantes_sustituidos` y `v_empleados`. La facturación electrónica ante la administración
tributaria está fuera de alcance en la versión 1, como dice `docs/01-problematica.md`: el modelo
queda preparado, pero no se integra el servicio del organismo.
