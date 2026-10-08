# Acta de instalación — Sistema de Ventas

Se imprime, se llena en el local del cliente y se firma. Es la constancia de qué se decidió y qué
quedó funcionando el día de la instalación. Cada línea con **[ ]** se marca solo cuando se comprobó
con los ojos, no cuando se dio por hecho.

| | |
| --- | --- |
| Negocio | |
| Dirección | |
| Fecha | |
| Instalador | |
| Responsable por el negocio | |
| Versión instalada (`git rev-parse --short HEAD`) | |

Los comandos de esta acta se explican en el [README](../sistema-ventas/README.md#nueva-instalación-un-cliente).

## 1. Decisiones que se toman ANTES de instalar

**Red de las cajas.** Con `http://IP-del-servidor:8100` las contraseñas y la sesión viajan **sin
cifrar** por la red del local. Marcar una:

- [ ] Cajas por **cable** o por un **WiFi propio** con contraseña, que no usan los clientes.
- [ ] **Certificado** en el servidor (Caddy/nginx), instalado en cada caja, con `APP_PUERTO=127.0.0.1:8100`.
- [ ] El negocio **acepta el riesgo** de usar una red sin cifrar. Motivo: ______________________

**Copia externa de los respaldos** (`RESPALDOS_COPIA_SERVIDOR` en el `.env`; sin ella el sistema no arranca):

- [ ] Disco externo o carpeta sincronizada en: ______________________ (escribible por el uid 82)
- [ ] Todavía no hay: los respaldos quedan **solo en el servidor** y el cliente lo sabe. Fecha límite para tener el disco: ____________

**Facturación.** [ ] Factura con IVA   [ ] Sin IVA   [ ] Precios ya incluyen el impuesto

**Cobro por QR.** [ ] Solo manual (simulado)   [ ] Banco Económico: trámite iniciado el ____________ — al recibir las credenciales, `php artisan qr:diagnostico --conectar`

## 2. Lo que el cliente entrega

- [ ] Catálogo en una hoja de cálculo (nombre, categoría, unidad, precios, código de barras, stock contado)
- [ ] Datos del negocio: nombre, NIT, dirección, teléfono
- [ ] Lista del personal y quién cobra, quién maneja almacén
- [ ] Un celular para recibir los avisos de falla por Telegram
- [ ] Un UPS para el servidor y la caja

## 3. Lo que se comprueba en el servidor

- [ ] `docker compose -f docker-compose.prod.yml up -d --build` sin errores; el sistema abre en `http://…:8100`
- [ ] Contraseña de `admin` cambiada; `PRIMER-ACCESO.txt` **borrado**
- [ ] **Segundo administrador** creado (cuenta de respaldo guardada bajo llave). Si se olvida la clave: `php artisan usuario:clave admin`
- [ ] Catálogo cargado: `catalogo:importar` revisado y luego con `--aplicar` — productos cargados: ________
- [ ] Una venta de prueba cobrada, impresa y **anulada**; el stock volvió
- [ ] Cierre de caja de prueba con arqueo
- [ ] Respaldo nocturno: `docker compose … exec app php artisan respaldo:crear` y aparece en **Sistema → Respaldos**
- [ ] **Ensayo de restauración** hecho hoy: `./scripts/probar-restauracion.sh` → «SE PUEDE restaurar»
- [ ] `crontab -e` con las dos líneas (cambia `/ruta/al/proyecto`):

  ```
  */5 * * * *  cd /ruta/al/proyecto && ALERTA_COMANDO='…' ./scripts/revisar-salud.sh >> backups/salud.log 2>&1
  0 3 1 * *    cd /ruta/al/proyecto && ./scripts/probar-restauracion.sh >> backups/ensayo.log 2>&1
  ```

- [ ] `./scripts/revisar-salud.sh` termina con «Todo en orden» (y un aviso de prueba llegó al celular)
- [ ] `docker compose … exec app php artisan qr:diagnostico` sin errores (o QR simulado, anotado arriba)

## 4. Capacitación

- [ ] El cajero abrió turno, vendió, cobró en efectivo y por QR, y cerró caja solo
- [ ] El encargado ingresó mercadería y entendió el kardex
- [ ] Se entregó al responsable: el usuario/clave de cada persona, **cómo actualizar** (`./scripts/actualizar.sh`) y **a quién llamar**

## 5. Firmas

| Instalador | Responsable del negocio |
| --- | --- |
| Nombre: | Nombre: |
| Firma: | Firma: |

*Pendientes acordados al cierre de este día (qué, quién, para cuándo):*

&nbsp;

&nbsp;
