#!/usr/bin/env bash
#
# Ensaya que el último respaldo SE PUEDE restaurar, sin tocar la base real.
#
#   ./scripts/probar-restauracion.sh                       # el respaldo más reciente
#   ./scripts/probar-restauracion.sh backups/ventas_db_20261001_010000.sql.gz
#
# Por qué existe: un respaldo que nunca se restauró es una promesa, no una red
# de seguridad. Se descubre que estaba roto el día que el disco muere. Los
# respaldos nocturnos los genera el propio sistema, y la restauración solo se
# había probado con otro generador (mysqldump a mano).
#
# Qué hace:
#   1. Elige el respaldo más reciente (el de backups/ o el nocturno del
#      volumen de la aplicación) o el que se le indique.
#   2. Lo carga en una base TEMPORAL («ventas_db_ensayo»), nunca en la real.
#   3. Comprueba que volvió completo: mismas tablas, vistas, procedimientos y
#      triggers que la base viva; que tiene datos; y que el stock de cada
#      producto coincide con el último movimiento de su kardex.
#   4. Borra la base temporal, pase lo que pase.
#   5. Anota el resultado en backups/ultimo-ensayo-restauracion.txt, que
#      scripts/revisar-salud.sh vigila: si pasa más de un mes sin ensayo, avisa.
#
# Para correrlo todos los meses (el día 1 a las 3:00):
#
#   0 3 1 * *  cd /ruta/al/proyecto && ./scripts/probar-restauracion.sh >> backups/ensayo.log 2>&1
#
# Variables que acepta:
#   BASE              la base viva con la que se compara (ventas_db)
#   BACKUP_MAX_HORAS  antigüedad máxima tolerada del respaldo (30)
#   VENTAS_MYSQL / VENTAS_APP   nombres de contenedor (se autodetectan)
#
# Sale con 0 si el respaldo se restauró bien y 1 si algo falló.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
export MSYS_NO_PATHCONV=1

BASE="${BASE:-ventas_db}"
ENSAYO="${BASE}_ensayo"
BACKUP_MAX_HORAS="${BACKUP_MAX_HORAS:-30}"
NOTA="backups/ultimo-ensayo-restauracion.txt"
ARCHIVO="${1:-}"
TEMPORAL=""
ERRORES=""
EPOCA=""
PROBLEMAS=()

detectar() {
    local explicito="$1"; shift
    if [ -n "$explicito" ]; then echo "$explicito"; return 0; fi
    for nombre in "$@"; do
        if docker inspect "$nombre" >/dev/null 2>&1; then echo "$nombre"; return 0; fi
    done
    return 1
}

fallar() { PROBLEMAS+=("$1"); echo "  FALLA  $1"; }
pasar()  { echo "  ok     $1"; }

if ! [[ "$BASE" =~ ^ventas_db[A-Za-z0-9_]*$ ]]; then
    echo "«$BASE» no es una base de este proyecto." >&2
    exit 2
fi

if ! CONTENEDOR="$(detectar "${VENTAS_MYSQL:-}" ventas_mysql_prod ventas_mysql)"; then
    echo "No encuentro el contenedor de MySQL (ventas_mysql_prod ni ventas_mysql)." >&2
    exit 1
fi

if [ -f .env ]; then
    DB_PASSWORD="$(grep -E '^DB_PASSWORD=' .env | tail -1 | cut -d= -f2- | tr -d '\r' || true)"
fi
DB_PASSWORD="${DB_PASSWORD:-ventas123}"

mysql() {
    docker exec -i -e MYSQL_PWD="$DB_PASSWORD" "$CONTENEDOR" mysql --default-character-set=utf8mb4 -uroot "$@"
}

# La base temporal se borra siempre, también si algo falla a mitad.
limpiar() {
    mysql -e "DROP DATABASE IF EXISTS \`$ENSAYO\`" >/dev/null 2>&1 || true
    if [ -n "$TEMPORAL" ]; then rm -f "$TEMPORAL"; fi
    if [ -n "$ERRORES" ]; then rm -f "$ERRORES"; fi
}
trap limpiar EXIT

# --- 1. Elegir el respaldo -------------------------------------------------------
echo "== 1/4 Eligiendo el respaldo"

if [ -z "$ARCHIVO" ]; then
    # Candidatos: «época|origen|ruta». El nocturno del programador vive en el
    # volumen de la aplicación, no en backups/.
    CANDIDATOS=""
    for f in backups/ventas_db_*.sql.gz; do
        [ -f "$f" ] || continue
        CANDIDATOS+="$(stat -c %Y "$f")|local|$f"$'\n'
    done

    if APP="$(detectar "${VENTAS_APP:-}" ventas_app_prod ventas_app)"; then
        while IFS= read -r linea; do
            [ -n "$linea" ] && CANDIDATOS+="${linea%% *}|volumen|${linea#* }"$'\n'
        done < <(docker exec "$APP" sh -c 'for f in storage/app/respaldos/ventas_db_*.sql.gz; do [ -f "$f" ] && echo "$(stat -c %Y "$f") $f"; done' 2>/dev/null || true)
    fi

    ELEGIDO="$(printf '%s' "$CANDIDATOS" | sort -t'|' -k1,1nr | head -1)"
    if [ -z "$ELEGIDO" ]; then
        echo "No hay ningún respaldo que ensayar (ni en backups/ ni en el volumen de la aplicación)." >&2
        exit 1
    fi

    EPOCA="$(cut -d'|' -f1 <<<"$ELEGIDO")"
    ORIGEN="$(cut -d'|' -f2 <<<"$ELEGIDO")"
    RUTA="$(cut -d'|' -f3 <<<"$ELEGIDO")"

    if [ "$ORIGEN" = "volumen" ]; then
        TEMPORAL="$(mktemp)"
        docker exec "$APP" cat "$RUTA" > "$TEMPORAL"
        ARCHIVO="$TEMPORAL"
        NOMBRE="$(basename "$RUTA") (volumen de la aplicación)"
    else
        ARCHIVO="$RUTA"
        NOMBRE="$(basename "$RUTA")"
    fi
else
    [ -f "$ARCHIVO" ] || { echo "No existe el archivo $ARCHIVO." >&2; exit 1; }
    NOMBRE="$(basename "$ARCHIVO")"
fi

echo "  Respaldo: $NOMBRE"

# --- 2. Cargarlo en la base temporal ---------------------------------------------
echo "== 2/4 Restaurando en «$ENSAYO» (la base real no se toca)"

if ! gzip -t "$ARCHIVO" 2>/dev/null; then
    fallar "el archivo está corrupto: gzip no lo puede leer"
elif ! FINAL="$(gunzip -c "$ARCHIVO" | tail -8)" || ! grep -qE 'Dump completed|^-- Fin del respaldo: completo\.|^SET UNIQUE_CHECKS = 1;' <<<"$FINAL"; then
    # Tres finales válidos: el de mysqldump, la marca del respaldo nocturno del
    # sistema y —para los respaldos anteriores a esa marca— su cierre de
    # restricciones. Un archivo cortado a mitad no termina en ninguno.
    fallar "el volcado está incompleto (no termina como un respaldo completo)"
else
    mysql -e "DROP DATABASE IF EXISTS \`$ENSAYO\`; CREATE DATABASE \`$ENSAYO\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

    ERRORES="$(mktemp)"
    if gunzip -c "$ARCHIVO" | mysql "$ENSAYO" 2>"$ERRORES"; then
        pasar "se cargó sin errores"
    else
        fallar "MySQL rechazó el volcado: $(head -c 300 "$ERRORES" | tr '\n' ' ')"
    fi
fi

# --- 3. Comprobar que volvió completo --------------------------------------------
echo "== 3/4 Comprobando lo restaurado"

if [ ${#PROBLEMAS[@]} -eq 0 ]; then
    consulta() { mysql -N "$1" -e "$2" 2>/dev/null | tr -d '\r'; }

    # Mismas piezas que la base viva: un volcado sin --routines/--triggers se ve
    # completo pero, al restaurarlo, esa lógica no vuelve.
    contar() {
        consulta "$1" "SELECT
            (SELECT COUNT(*) FROM information_schema.TABLES   WHERE TABLE_SCHEMA='$1' AND TABLE_TYPE='BASE TABLE'),
            (SELECT COUNT(*) FROM information_schema.VIEWS    WHERE TABLE_SCHEMA='$1'),
            (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$1'),
            (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$1')" | tr '\t' ' '
    }

    VIVA="$(contar "$BASE")"
    RESTAURADA="$(contar "$ENSAYO")"

    if [ "$VIVA" = "$RESTAURADA" ]; then
        pasar "mismas piezas que la base viva (tablas, vistas, procedimientos, triggers: $RESTAURADA)"
    else
        fallar "faltan piezas. Viva: $VIVA — restaurada: $RESTAURADA (tablas, vistas, procedimientos, triggers)"
    fi

    # Que traiga datos. Un respaldo de la base vacía pasa todas las demás pruebas.
    for tabla in productos usuarios; do
        EN_VIVA="$(consulta "$BASE" "SELECT COUNT(*) FROM $tabla")"
        EN_COPIA="$(consulta "$ENSAYO" "SELECT COUNT(*) FROM $tabla" || echo 0)"

        if [ "${EN_COPIA:-0}" -eq 0 ] && [ "${EN_VIVA:-0}" -gt 0 ]; then
            fallar "$tabla está VACÍA en el respaldo y tiene $EN_VIVA filas en la base viva"
        else
            pasar "$tabla: $EN_COPIA filas en el respaldo (viva: $EN_VIVA)"
        fi
    done

    VENTAS_VIVA="$(consulta "$BASE" "SELECT COUNT(*) FROM ventas")"
    VENTAS_COPIA="$(consulta "$ENSAYO" "SELECT COUNT(*) FROM ventas")"
    if [ "${VENTAS_COPIA:-0}" -gt "${VENTAS_VIVA:-0}" ]; then
        fallar "el respaldo tiene MÁS ventas ($VENTAS_COPIA) que la base viva ($VENTAS_VIVA): ¿es de otra instalación?"
    else
        pasar "ventas: $VENTAS_COPIA en el respaldo (viva: $VENTAS_VIVA; la diferencia es lo vendido desde la copia)"
    fi

    # El stock de cada producto es el resultado de su último movimiento.
    DESCUADRES="$(consulta "$ENSAYO" "SELECT COUNT(*) FROM productos p
        JOIN movimientos_inventario m ON m.id = (SELECT MAX(id) FROM movimientos_inventario WHERE producto_id = p.id)
        WHERE m.stock_resultante <> p.stock_actual")"
    if [ "${DESCUADRES:-1}" = "0" ]; then
        pasar "el stock de todos los productos coincide con su kardex"
    else
        fallar "$DESCUADRES producto(s) con el stock distinto del último movimiento del kardex"
    fi
fi

# --- 4. Antigüedad del respaldo y resultado ---------------------------------------
echo "== 4/4 Resultado"

# Solo cuando el respaldo lo eligió el guion (el más reciente): si se indicó uno
# a mano, su edad es lo de menos.
if [ -n "$EPOCA" ]; then
    EDAD_HORAS=$(( ( $(date +%s) - EPOCA ) / 3600 ))
    if [ "$EDAD_HORAS" -gt "$BACKUP_MAX_HORAS" ]; then
        fallar "el respaldo más reciente tiene $EDAD_HORAS h (límite ${BACKUP_MAX_HORAS} h): ¿sigue corriendo el respaldo nocturno?"
    fi
fi

mkdir -p backups
FECHA="$(date '+%Y-%m-%d %H:%M')"

if [ ${#PROBLEMAS[@]} -eq 0 ]; then
    printf 'RESULTADO: OK\nFecha: %s\nRespaldo: %s\n' "$FECHA" "$NOMBRE" > "$NOTA"
    echo
    echo "El respaldo SE PUEDE restaurar: $NOMBRE"
    exit 0
fi

{
    printf 'RESULTADO: FALLÓ\nFecha: %s\nRespaldo: %s\n' "$FECHA" "$NOMBRE"
    for p in "${PROBLEMAS[@]}"; do printf -- '- %s\n' "$p"; done
} > "$NOTA"

echo >&2
echo "El respaldo NO se pudo restaurar bien (${#PROBLEMAS[@]} problema(s)). Arréglalo hoy, no el día que haga falta." >&2
exit 1
