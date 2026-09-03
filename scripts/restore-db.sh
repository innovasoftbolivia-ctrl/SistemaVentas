#!/usr/bin/env bash
#
# Restaura una copia de seguridad generada por backup-db.sh.
#
#   ./scripts/restore-db.sh backups/ventas_db_20260901_010000.sql.gz
#
# SOBRESCRIBE la base actual por completo. Pide confirmación escrita antes de
# tocar nada; con CONFIRMAR=si se salta la pregunta (útil para automatizar
# una restauración en un servidor nuevo, no para el uso normal).

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

CONTENEDOR="ventas_mysql"
BASE="ventas_db"
ARCHIVO="${1:-}"

if [ -z "$ARCHIVO" ] || [ ! -f "$ARCHIVO" ]; then
    echo "Uso: $0 <archivo-de-copia.sql.gz>" >&2
    echo "Copias disponibles en backups/:" >&2
    ls -1 backups/*.sql.gz 2>/dev/null >&2 || echo "  (ninguna)" >&2
    exit 1
fi

if [ -f .env ]; then
    DB_PASSWORD="$(grep -E '^DB_PASSWORD=' .env | tail -1 | cut -d= -f2-)"
fi
DB_PASSWORD="${DB_PASSWORD:-ventas123}"

if ! docker inspect "$CONTENEDOR" >/dev/null 2>&1; then
    echo "No encuentro el contenedor $CONTENEDOR. ¿Está levantado \`docker compose\`?" >&2
    exit 1
fi

if [ "${CONFIRMAR:-}" != "si" ]; then
    echo "Esto BORRA y reemplaza todo el contenido de «$BASE» con «$ARCHIVO»."
    read -r -p "Escribe SI (en mayúsculas) para continuar: " respuesta
    if [ "$respuesta" != "SI" ]; then
        echo "Cancelado. No se tocó la base."
        exit 1
    fi
fi

gunzip -c "$ARCHIVO" | docker exec -i "$CONTENEDOR" \
    mysql --default-character-set=utf8mb4 -uroot -p"$DB_PASSWORD" "$BASE"

echo "Restaurado «$BASE» desde $ARCHIVO."
