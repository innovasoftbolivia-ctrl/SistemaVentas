#!/usr/bin/env bash
#
# BORRA una base de DESARROLLO o de PRUEBAS y la vuelve a armar con el esquema y
# los datos de demostración.
#
#   scripts/recrear-base-desarrollo.sh ventas_db_test
#   scripts/recrear-base-desarrollo.sh ventas_db --catalogo     # y el catálogo real del minimarket
#   scripts/recrear-base-desarrollo.sh ventas_db_test --si      # sin preguntar (CI, scripts)
#
# Existe porque `docs/sql/01_schema_mysql.sql` ya no borra nada: antes empezaba
# con `DROP DATABASE ventas_db`, y en el servidor de un cliente «recargar el
# esquema» era un copiar-pegar de perder el negocio. El DROP vive ahora solo
# aquí y en `docs/sql/desarrollo/`, con estas defensas:
#
#   * solo habla con el contenedor de DESARROLLO (`ventas_mysql`); se niega si el
#     nombre apunta a producción o si hay un contenedor de producción corriendo;
#   * el nombre de la base tiene que empezar con «ventas_db»;
#   * hay que escribir el nombre de la base para confirmar (salvo con --si).
#
# Opciones:
#   --si          no pide confirmación
#   --catalogo    aplica además los 4 parches del catálogo de referencia
#                 (abarrotes, bebidas, cigarrillos, sin impuesto)

set -euo pipefail
export MSYS_NO_PATHCONV=1
cd "$(dirname "${BASH_SOURCE[0]}")/.."

BASE=""
SI=0
CATALOGO=0
for arg in "$@"; do
    case "$arg" in
        --si) SI=1 ;;
        --catalogo) CATALOGO=1 ;;
        -h|--help) sed -n 2,24p "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) echo "Opción desconocida: $arg" >&2; exit 2 ;;
        *) BASE="$arg" ;;
    esac
done

if [ -z "$BASE" ]; then
    echo "Falta decir qué base borrar y volver a crear. Ejemplo:" >&2
    echo "  scripts/recrear-base-desarrollo.sh ventas_db_test" >&2
    exit 2
fi

if ! [[ "$BASE" =~ ^ventas_db[A-Za-z0-9_]*$ ]]; then
    echo "«$BASE» no es una base de este proyecto: tiene que empezar con ventas_db y llevar solo letras, números o guion bajo." >&2
    exit 2
fi

CONTENEDOR="${VENTAS_MYSQL:-ventas_mysql}"

if [[ "$CONTENEDOR" == *prod* ]]; then
    echo "REHUSADO: «$CONTENEDOR» es de PRODUCCIÓN. Este script no borra bases de un servidor real." >&2
    exit 1
fi

if [ "$(docker inspect -f '{{.State.Running}}' ventas_mysql_prod 2>/dev/null || true)" = "true" ]; then
    echo "REHUSADO: hay un contenedor de PRODUCCIÓN (ventas_mysql_prod) corriendo en esta máquina." >&2
    echo "Este script es solo para desarrollo. Si de verdad hace falta, hazlo a mano y con un respaldo." >&2
    exit 1
fi

if [ "$(docker inspect -f '{{.State.Running}}' "$CONTENEDOR" 2>/dev/null || true)" != "true" ]; then
    echo "No encuentro el contenedor «$CONTENEDOR» corriendo. ¿Está levantado \`docker compose up -d\`?" >&2
    exit 1
fi

if [ -f .env ]; then
    DB_PASSWORD="$(grep -E '^DB_PASSWORD=' .env | tail -1 | cut -d= -f2- || true)"
fi
DB_PASSWORD="${DB_PASSWORD:-ventas123}"

mysql() {
    docker exec -i -e MYSQL_PWD="$DB_PASSWORD" "$CONTENEDOR" mysql --default-character-set=utf8mb4 -uroot "$@"
}

# Los archivos dicen `ventas_db`; para otra base se cambia el nombre al vuelo.
con_nombre() {
    sed "s/ventas_db/$BASE/g" "$1"
}

EXISTE="$(mysql -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$BASE'")"
if [ "$EXISTE" = "1" ]; then
    VENTAS="$(mysql -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$BASE' AND TABLE_NAME = 'ventas'")"
    if [ "$VENTAS" = "1" ]; then
        N="$(mysql -N "$BASE" -e "SELECT COUNT(*) FROM ventas")"
        echo "La base «$BASE» existe y tiene $N venta(s)."
    else
        echo "La base «$BASE» existe."
    fi
else
    echo "La base «$BASE» no existe todavía: se creará."
fi

if [ "$SI" -eq 0 ]; then
    echo
    echo "Se va a BORRAR «$BASE» y a cargarla de nuevo con los datos de demostración."
    read -r -p "Para confirmar, escribe el nombre de la base: " RESPUESTA
    if [ "$RESPUESTA" != "$BASE" ]; then
        echo "No coincide. No se tocó nada."
        exit 1
    fi
fi

echo "1/3  Borrando y creando «$BASE»…"
con_nombre docs/sql/desarrollo/borrar_y_crear_base_DESTRUCTIVO.sql | mysql

echo "2/3  Esquema…"
con_nombre docs/sql/01_schema_mysql.sql | mysql

echo "3/3  Datos de demostración…"
con_nombre docs/sql/02_datos_iniciales.sql | mysql

if [ "$CATALOGO" -eq 1 ]; then
    echo "     Catálogo de referencia…"
    for parche in docs/sql/parches/2026_08_23_*.sql; do
        archivo="$(basename "$parche")"
        mysql "$BASE" < "$parche"
        mysql "$BASE" -e "INSERT IGNORE INTO parches_aplicados (archivo) VALUES ('$archivo')"
    done
fi

# Una base de desarrollo recién cargada trae un hash de ejemplo en las cuentas:
# las contraseñas de desarrollo (admin123…) se ponen con su seeder. Solo cuando
# es la base que usa la aplicación.
if [ "$BASE" = "ventas_db" ] && [ "$(docker inspect -f '{{.State.Running}}' ventas_app 2>/dev/null || true)" = "true" ]; then
    docker exec ventas_app php artisan db:seed --class=CredencialesSeeder --no-interaction >/dev/null \
        && echo "     Contraseñas de desarrollo puestas (README, «Cuentas de desarrollo»)."
fi

PRODUCTOS="$(mysql -N "$BASE" -e "SELECT COUNT(*) FROM productos")"
echo
echo "Listo: «$BASE» tiene $PRODUCTOS productos."
