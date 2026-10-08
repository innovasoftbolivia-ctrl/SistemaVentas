#!/usr/bin/env bash
#
# Actualiza el Sistema de Ventas en el servidor de un cliente, sin olvidar nada.
#
#   ./scripts/actualizar.sh                # pide confirmación y actualiza
#   ./scripts/actualizar.sh --simular      # solo revisa y muestra qué haría
#   ./scripts/actualizar.sh --si           # sin preguntar (automatizar)
#   ./scripts/actualizar.sh --sin-git      # el código ya se copió a mano (servidor sin git)
#   ./scripts/actualizar.sh --sin-assets   # los estilos ya vienen compilados (servidor sin node)
#
# Por qué existe: una actualización a mano tiene seis pasos y olvidar uno rompe
# el negocio sin avisar. Un `git pull` sin recompilar los estilos deja TODAS las
# pantallas en error 500; los parches se aplicaban sin respaldo previo; y nadie
# comprobaba al final que el sistema siguiera vendiendo.
#
# El orden es el que protege los datos:
#
#   1. Revisa que se pueda (docker, el .env, MySQL corriendo, sin cambios locales).
#   2. RESPALDO. Si falla, no se toca nada más.
#   3. Trae el código nuevo (git pull --ff-only: nunca mezcla ni pisa).
#   4. Compila los estilos y comprueba que existen. Si falla, los contenedores
#      siguen corriendo con la versión anterior, intactos.
#   5. Reconstruye y reinicia los contenedores.
#   6. Aplica los parches de base de datos que falten.
#   7. Comprueba que el sistema responde y que sus estilos cargan.
#
# Si algo falla después del paso 3, dice qué versión estaba antes y cuál es el
# respaldo, con las órdenes exactas para volver atrás.
#
# Hazlo FUERA del horario de venta: al reiniciar, los cajeros con una venta a
# medias pierden el carrito y vuelven a iniciar sesión.
#
# Variables que acepta:
#   COMPOSE_FILE   archivo de compose (docker-compose.prod.yml)
#   URL_BASE       dirección del sistema para la comprobación final (http://localhost:8100)
#   ESPERA_SEGUNDOS  cuánto esperar a que la aplicación esté sana (180)

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
URL_BASE="${URL_BASE:-http://localhost:8100}"
ESPERA_SEGUNDOS="${ESPERA_SEGUNDOS:-180}"

SI=0
SIMULAR=0
SIN_GIT=0
SIN_ASSETS=0
for arg in "$@"; do
    case "$arg" in
        --si) SI=1 ;;
        --simular) SIMULAR=1 ;;
        --sin-git) SIN_GIT=1 ;;
        --sin-assets) SIN_ASSETS=1 ;;
        -h|--help) sed -n 2,11p "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Opción desconocida: $arg" >&2; exit 2 ;;
    esac
done

ETAPA="revisión previa"
VERSION_ANTERIOR=""
RESPALDO=""

paso() { echo; echo "== $1"; }
# `return 1` y no `exit 1`: así salta la trampa ERR y se imprime cómo volver atrás.
aborta() { echo "ERROR: $1" >&2; return 1; }

recuperacion() {
    local codigo=$?
    echo >&2
    echo "La actualización se detuvo en: ${ETAPA}." >&2

    if [ -n "$VERSION_ANTERIOR" ]; then
        echo >&2
        echo "Para volver a la versión anterior del código:" >&2
        echo "  git checkout ${VERSION_ANTERIOR}" >&2
        echo "  (cd sistema-ventas && npm ci && npm run build)" >&2
        echo "  docker compose -f ${COMPOSE_FILE} up -d --build" >&2
    fi

    if [ -n "$RESPALDO" ]; then
        echo >&2
        echo "El respaldo de antes de actualizar está en: ${RESPALDO}" >&2
        echo "Solo si los datos quedaron mal (no basta con el código): ./scripts/restore-db.sh ${RESPALDO}" >&2
    fi

    exit "$codigo"
}
trap recuperacion ERR

# --- 1. Revisión previa --------------------------------------------------------
paso "1/7 Revisando que se pueda actualizar"

command -v docker >/dev/null 2>&1 || aborta "no encuentro docker en este servidor."
docker info >/dev/null 2>&1 || aborta "docker no responde. ¿Está iniciado el servicio?"
[ -f "$COMPOSE_FILE" ] || aborta "no existe $COMPOSE_FILE. Corre esto desde la carpeta del proyecto."
[ -f .env ] || aborta "falta el archivo .env en la raíz (se crea copiando .env.example)."
[ -f sistema-ventas/.env.docker ] || aborta "falta sistema-ventas/.env.docker."

if [ "$(docker inspect -f '{{.State.Running}}' ventas_mysql_prod 2>/dev/null || true)" != "true" ]; then
    aborta "MySQL (ventas_mysql_prod) no está corriendo: no hay base que respaldar ni que actualizar. Si el sistema aún no está instalado, usa el paso a paso del README."
fi

if [ "$SIN_GIT" -eq 0 ]; then
    [ -e .git ] || aborta "este servidor no tiene git. Copia el código nuevo encima y corre esto con --sin-git."

    if ! git diff --quiet || ! git diff --cached --quiet; then
        echo "Hay cambios hechos a mano en el código de este servidor:" >&2
        git status --short | grep -v '^??' >&2 || true
        aborta "el git pull los pisaría o se confundiría. Guárdalos o descártalos antes."
    fi
fi

if [ "$SIN_ASSETS" -eq 0 ]; then
    command -v npm >/dev/null 2>&1 || aborta "no hay npm para compilar los estilos. Si ya vienen compilados: --sin-assets."
fi

echo "Todo en orden para actualizar."

if [ "$SIMULAR" -eq 1 ]; then
    paso "Simulación: esto es lo que haría"
    echo "  2. Respaldo: ./scripts/backup-db.sh"
    [ "$SIN_GIT" -eq 0 ] && echo "  3. Código: git pull --ff-only" || echo "  3. Código: (omitido, --sin-git)"
    [ "$SIN_ASSETS" -eq 0 ] && echo "  4. Estilos: npm ci && npm run build, y comprobar que existen" || echo "  4. Estilos: solo comprobar que existen"
    echo "  5. Contenedores: docker compose -f $COMPOSE_FILE up -d --build"
    echo "  6. Parches de base de datos pendientes: ./scripts/aplicar-parches.sh --aplicar"
    echo "  7. Comprobación: $URL_BASE/up, /login y su hoja de estilos"
    echo
    echo "No se cambió nada."
    exit 0
fi

if [ "$SI" -eq 0 ]; then
    echo
    echo "Se va a ACTUALIZAR el sistema. Los cajeros con una venta a medias perderán el carrito."
    read -r -p "¿Es fuera del horario de venta? Escribe «actualizar» para seguir: " RESPUESTA
    if [ "$RESPUESTA" != "actualizar" ]; then
        echo "No se tocó nada."
        exit 1
    fi
fi

# --- 2. Respaldo ----------------------------------------------------------------
ETAPA="el respaldo (todavía no se cambió nada)"
paso "2/7 Respaldo de la base y las fotos"

SALIDA_RESPALDO="$(./scripts/backup-db.sh)" || aborta "el respaldo falló: no se actualiza sin respaldo. No se cambió nada."
echo "$SALIDA_RESPALDO"
RESPALDO="$(sed -n 's/^Base guardada en \(.*\) (.*$/\1/p' <<<"$SALIDA_RESPALDO" | head -1)"
[ -n "$RESPALDO" ] || aborta "no pude confirmar dónde quedó el respaldo. No se cambió nada."

# --- 3. Código ------------------------------------------------------------------
ETAPA="traer el código nuevo"
paso "3/7 Código nuevo"

if [ "$SIN_GIT" -eq 0 ]; then
    VERSION_ANTERIOR="$(git rev-parse HEAD)"
    git pull --ff-only
    echo "Versión: $(git rev-parse --short HEAD) (antes: ${VERSION_ANTERIOR:0:7})"
else
    echo "Omitido (--sin-git): se asume que el código nuevo ya está copiado."
fi

# --- 4. Estilos -----------------------------------------------------------------
ETAPA="compilar los estilos (los contenedores siguen con la versión anterior)"
paso "4/7 Estilos y scripts del navegador"

if [ "$SIN_ASSETS" -eq 0 ]; then
    (cd sistema-ventas && npm ci && npm run build)
fi

# Sin este archivo las pantallas dan error 500: es el fallo que más se repite.
[ -f sistema-ventas/public/build/manifest.json ] || aborta "faltan los estilos compilados (public/build/manifest.json). Sin ellos TODAS las pantallas dan error 500."
echo "Estilos listos."

# --- 5. Contenedores ------------------------------------------------------------
ETAPA="reconstruir los contenedores"
paso "5/7 Reiniciando el sistema"

docker compose -f "$COMPOSE_FILE" up -d --build

echo "Esperando a que la aplicación esté sana (hasta ${ESPERA_SEGUNDOS}s)…"
LISTO=0
for _ in $(seq 1 $((ESPERA_SEGUNDOS / 3))); do
    if [ "$(docker inspect -f '{{.State.Health.Status}}' ventas_app_prod 2>/dev/null || true)" = "healthy" ]; then
        LISTO=1
        break
    fi
    sleep 3
done
[ "$LISTO" -eq 1 ] || aborta "la aplicación no llegó a estar sana en ${ESPERA_SEGUNDOS}s. Mira: docker compose -f $COMPOSE_FILE logs --tail=50 app"

# --- 6. Parches -----------------------------------------------------------------
ETAPA="aplicar los parches de base de datos"
paso "6/7 Parches de la base de datos"

./scripts/aplicar-parches.sh --aplicar

# --- 7. Comprobación final ------------------------------------------------------
ETAPA="la comprobación final"
paso "7/7 Comprobando que el sistema vende"

codigo() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$1" || true; }

[ "$(codigo "$URL_BASE/up")" = "200" ] || aborta "$URL_BASE/up no responde 200: la aplicación o la base no están bien."

PAGINA="$(curl -s --max-time 20 "$URL_BASE/login" || true)"
grep -q 'name="usuario"' <<<"$PAGINA" || aborta "la pantalla de ingreso no se ve bien: $URL_BASE/login."

ESTILO="$(grep -oE '(https?://[^"/]+)?/build/assets/[^"]+\.css' <<<"$PAGINA" | head -1 || true)"
[ -n "$ESTILO" ] || aborta "la pantalla de ingreso no enlaza ninguna hoja de estilos compilada."
case "$ESTILO" in http*) URL_ESTILO="$ESTILO" ;; *) URL_ESTILO="$URL_BASE$ESTILO" ;; esac
[ "$(codigo "$URL_ESTILO")" = "200" ] || aborta "la hoja de estilos no carga ($URL_ESTILO): las pantallas se verían rotas."

echo "El sistema responde, la pantalla de ingreso se ve y sus estilos cargan."

if [ -x scripts/revisar-salud.sh ]; then
    ./scripts/revisar-salud.sh || echo "AVISO: revisar-salud.sh encontró problemas (ver arriba). La actualización terminó, pero revísalos."
fi

mkdir -p backups
{
    echo "Última actualización: $(date '+%Y-%m-%d %H:%M')"
    echo "Versión anterior: ${VERSION_ANTERIOR:-(no se usó git)}"
    echo "Respaldo previo:  ${RESPALDO}"
} > backups/ultima-actualizacion.txt

trap - ERR
echo
echo "Actualización terminada. Respaldo previo: $RESPALDO"
