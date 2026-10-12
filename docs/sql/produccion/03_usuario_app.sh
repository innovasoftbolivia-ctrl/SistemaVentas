#!/bin/bash
# =============================================================================
#  Cuenta de MySQL con permisos mínimos para la aplicación (producción)
#
#  Lo corre el contenedor de MySQL una sola vez, al crear la base, después del
#  esquema y los datos base. La aplicación no necesita crear ni borrar tablas:
#  lee y escribe datos, llama a los procedimientos y lee su definición para
#  los respaldos. Conectada como root, un fallo de seguridad en la aplicación
#  tendría el servidor de base de datos entero.
#
#  Los parches (`scripts/aplicar-parches.sh`), el respaldo y la restauración
#  por consola siguen usando root desde el propio contenedor.
#
#  Para una instalación que ya existía: scripts/crear-usuario-app.sh
#
#  Sin `set -e` ni `exit`, a propósito: si el archivo llega sin permiso de
#  ejecución, el contenedor de MySQL lo carga dentro de su propio script de
#  arranque, y un `exit` cortaría la inicialización de la base entera.
# =============================================================================

if [ -z "${DB_APP_PASSWORD:-}" ]; then
    echo "AVISO: DB_APP_PASSWORD vacía; no se crea ventas_app y la aplicación seguirá con root." >&2
elif [[ "$DB_APP_PASSWORD" == *"'"* || "$DB_APP_PASSWORD" == *\\* ]]; then
    echo "ERROR: DB_APP_PASSWORD no puede llevar comillas simples ni barras invertidas; no se crea ventas_app." >&2
else
    mysql --protocol=socket -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE USER IF NOT EXISTS 'ventas_app'@'%' IDENTIFIED BY '${DB_APP_PASSWORD}';
-- SIN DELETE a nivel de toda la base: la aplicación solo borra en las tablas de
-- catálogo y seguridad que de verdad borra (cajas, cargos, categorías, clientes,
-- productos, proveedores, roles y sus permisos, unidades y usuarios). Las ventas, el
-- kardex, la bitácora, los comprobantes y los movimientos de caja no se borran
-- jamás: un error de código o un ataque ya no puede vaciarlos. (El privilegio
-- TRIGGER sigue siendo necesario para que el respaldo lea los triggers.)
GRANT SELECT, INSERT, UPDATE, EXECUTE, SHOW VIEW, TRIGGER, LOCK TABLES
    ON ventas_db.* TO 'ventas_app'@'%';
GRANT DELETE ON ventas_db.cajas TO 'ventas_app'@'%';
GRANT DELETE ON ventas_db.cargos TO 'ventas_app'@'%';
GRANT DELETE ON ventas_db.categorias TO 'ventas_app'@'%';
GRANT DELETE ON ventas_db.clientes TO 'ventas_app'@'%';
GRANT DELETE ON ventas_db.productos TO 'ventas_app'@'%';
GRANT DELETE ON ventas_db.proveedores TO 'ventas_app'@'%';
GRANT DELETE ON ventas_db.rol_permiso TO 'ventas_app'@'%';
GRANT DELETE ON ventas_db.roles TO 'ventas_app'@'%';
GRANT DELETE ON ventas_db.unidades_medida TO 'ventas_app'@'%';
GRANT DELETE ON ventas_db.usuarios TO 'ventas_app'@'%';
-- Leer el cuerpo de los procedimientos, para que el respaldo desde la
-- aplicación los incluya.
GRANT SHOW_ROUTINE ON *.* TO 'ventas_app'@'%';
SQL
    echo "Cuenta ventas_app creada con permisos mínimos sobre ventas_db."
fi
