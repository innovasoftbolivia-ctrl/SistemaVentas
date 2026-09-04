#!/usr/bin/env bash
#
# Copia de seguridad de la base del Sistema de Ventas.
#
#   ./scripts/backup-db.sh
#
# Guarda un volcado comprimido en backups/, con fecha y hora en el nombre, y
# borra los que tengan más de RETENTION_DAYS (14 por omisión). Pensado para
# correr desde cron en el servidor donde vive `docker compose`:
#
#   # todas las noches a la 1:00
#   0 1 * * *  cd /ruta/al/proyecto && ./scripts/backup-db.sh >> backups/backup.log 2>&1
#
# `--routines --triggers --events`: el esquema apoya reglas de negocio en
# procedimientos almacenados (sp_cerrar_caja, sp_recalcular_venta...) y en
# triggers (kardex, comprobantes). Un volcado sin estas tres banderas se ve
# completo pero, al restaurarlo, esa lógica no vuelve — silenciosamente.
#
# `--single-transaction`: MySQL sigue aceptando ventas mientras se hace la
# copia; no hace falta parar el sistema para respaldarlo.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

CONTENEDOR="ventas_mysql"
BASE="ventas_db"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
DESTINO="backups"

# La misma contraseña que usa docker-compose.yml para MYSQL_ROOT_PASSWORD.
if [ -f .env ]; then
    DB_PASSWORD="$(grep -E '^DB_PASSWORD=' .env | tail -1 | cut -d= -f2-)"
fi
DB_PASSWORD="${DB_PASSWORD:-ventas123}"

if ! docker inspect "$CONTENEDOR" >/dev/null 2>&1; then
    echo "No encuentro el contenedor $CONTENEDOR. ¿Está levantado \`docker compose\`?" >&2
    exit 1
fi

mkdir -p "$DESTINO"

MARCA="$(date +%Y%m%d_%H%M%S)"
ARCHIVO="$DESTINO/${BASE}_${MARCA}.sql.gz"
TEMPORAL="${ARCHIVO}.tmp"

docker exec "$CONTENEDOR" \
    mysqldump --single-transaction --routines --triggers --events \
        --default-character-set=utf8mb4 -uroot -p"$DB_PASSWORD" "$BASE" \
    | gzip > "$TEMPORAL"

mv "$TEMPORAL" "$ARCHIVO"
echo "Copia guardada en $ARCHIVO ($(du -h "$ARCHIVO" | cut -f1))"

# Limpieza: solo entra aquí lo que este script generó, por el nombre.
BORRADOS=0
while IFS= read -r -d '' viejo; do
    rm -f "$viejo"
    BORRADOS=$((BORRADOS + 1))
done < <(find "$DESTINO" -maxdepth 1 -name "${BASE}_*.sql.gz" -mtime "+${RETENTION_DAYS}" -print0)

if [ "$BORRADOS" -gt 0 ]; then
    echo "Se borraron $BORRADOS copias de más de $RETENTION_DAYS días."
fi
