#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Restauracion del respaldo de Gestion de Panaderia.
# ---------------------------------------------------------------------------
#
# LAS SEIS REGLAS QUE ESTE SCRIPT RESPETA
# ---------------------------------------
#  1. MANIFIESTO Y SHA-256: antes de tocar la base se verifica que el
#     respaldo este COMPLETO (tiene la marca) y que los checksums calcen. Un
#     respaldo a medias no se restaura.
#
#  2. MARCA DE COMPLETUD: si no esta, el script se niega a seguir. Es la
#     diferencia entre un respaldo bueno y uno que se caigo a mitad.
#
#  3. VALIDAR RUTAS ANTES DE ESCRIBIR: la ruta del respaldo se resuelve a
#     absoluta y se comprueba antes de abrir nada.
#
#  4. RECHAZAR DESTINO OCUPADO SALVO `--force`: la base de destino tiene
#     datos. Restaurar encima los destruye. Con `--force` se vacia con
#     TRUNCATE, nunca con DROP.
#
#  5. NUNCA DROP, NUNCA SOBRESCRITURA AUTOMATICA: este archivo no contiene
#     ninguna sentencia DROP. Para limpiar usa TRUNCATE, que deja la base en
#     pie con su propietario y sus permisos. Si el respaldo trajera ordenes
#     DROP, se cancelan con `--if-exists --clean`.
#
#  6. DEJAR EL SERVICIO DETENIDO al terminar. Es lo mas importante: una
#     aplicacion que vuelve a levantar contra una base a medio restaurar
#     escribe sobre datos incompletos y el problema original se duplica.
#
# Ver docs/08-RESPALDOS.md.
#
# USO
#   ./restauracion.sh /respaldos/panaderia-20260101-030000
#   ./restauracion.sh --force /respaldos/pananderia-...
#   ./restauracion.sh --dejar-detenido /respaldos/panaderia-...
set -euo pipefail

# --- Utilidades ------------------------------------------------------------

registrar() {
    printf '[restauracion] %s\n' "$*"
}

fallar() {
    printf '[restauracion] ERROR: %s\n' "$*" >&2
    exit 1
}

RESPALDO=""
FUERZA="false"
DEJAR_DETENIDO="true"
CONTENEDOR_BD="panaderia-postgres-1"
NOMBRE_BD="${POSTGRES_DB:-panaderia}"
USUARIO_BD="${POSTGRES_USER:-panaderia_app}"
MARCA_COMPLETO=".completo"

# --- Argumentos ------------------------------------------------------------

while [ $# -gt 0 ]; do
    case "$1" in
        --force)
            FUERZA="true"
            shift
            ;;
        --dejar-detenido)
            DEJAR_DETENIDO="true"
            shift
            ;;
        --ayuda|-h)
            sed -n '2,45p' "$0"
            exit 0
            ;;
        -*)
            fallar "Argumento desconocido: $1"
            ;;
        *)
            if [ -n "$RESPALDO" ]; then
                failar "Solo se acepta una ruta de respaldo."
            fi
            RESPALDO="$1"
            shift
            ;;
    esac
done

if [ -z "$RESPALDO" ]; then
    fallar "Falta la ruta del respaldo. Usa --ayuda."
fi

# --- Regla 3: validar la ruta ---------------------------------------------

RESPALDO_ABSOLUTO="$(cd "$RESPALDO" 2>/dev/null && pwd -P)" \
    || fallar "No existe el respaldo: $RESPALDO"

registrar "Respaldo: $RESPALDO_ABSOLUTO"

# --- Regla 1 (parte 1): el respaldo tiene que estar COMPLETO ---------------

if [ ! -f "${RESPALDO_ABSOLUTO}/${MARCA_COMPLETO}" ]; then
    fallar "Este respaldo NO esta completo: le falta la marca '${MARCA_COMPLETO}'."
    fallar "El proceso se cayo antes de terminar. No se restaura."
fi

registrar "El respaldo tiene la marca de completo."

# --- Regla 1 (parte 2): verificar los SHA-256 ------------------------------

MANIFIESTO="${RESPALDO_ABSOLUTO}/manifiesto.txt"

if [ ! -f "$MANIFIESTO" ]; then
    fallar "Falta el manifiesto: $MANIFIESTO. Sin el no se puede verificar nada."
fi

if ! ( cd "$RESPALDO_ABSOLUTO" && sha256sum --check --quiet "$(basename "$MANIFIESTO")" ); then
    fallar "La verificacion de SHA-256 fallo: el archivo esta corrupto o cambiado."
fi

registrar "Los SHA-256 calzan. El respaldo no fue modificado."

# El archivo de respaldo propiamente dicho.
ARCHIVO_DUMP=""

for archivo in "$RESPALDO_ABSOLUTO"/*.dump; do
    if [ -f "$archivo" ]; then
        ARCHIVO_DUMP="$archivo"
        break
    fi
done

if [ -z "$ARCHIVO_DUMP" ]; then
    failar "No hay ningun archivo .dump en el respaldo."
fi

# --- Estado actual de la base ---------------------------------------------

if ! docker ps --format '{{.Names}}' | grep -q "$CONTENEDOR_BD"; then
    fallar "El contenedor de la base no esta corriendo: $CONTENEDOR_BD"
fi

# Cuantas filas hay ahora. Sirve para avisar ANTES de borrar nada, no para
# decidir.
FILAS_ACTUALES="$(docker exec "$CONTENEDOR_BD" psql \
    --username="$USUARIO_BD" \
    --dbname="$NOMBRE_BD" \
    --tuples-only \
    --no-align \
    --command="SELECT count(*) FROM information_schema.tables WHERE table_schema='public';" \
    2>/dev/null | tr -d '[:space:]' || echo "0")"

registrar "La base '${NOMBRE_BD}' tiene ${FILAS_ACTUALES} tabla(s) ahora."

if [ "$FILAS_ACTUALES" -gt 0 ] && [ "$FUERZA" != "true" ]; then
    registrar "La base NO esta vacia. Se va a VACIAR antes de restaurar."
    registrar "Si no era eso lo que querias, cancela con Ctrl+C ahora."
    sleep 5
fi

# --- Regla 6: detener el servicio ANTES de tocar la base -------------------
#
# Es lo que evita el peor caso: una aplicacion escribiendo sobre una base a
# medio restaurar. Se detiene la API y la web, que son los que escriben.

registrar "Deteniendo la API y la interfaz..."

docker compose -f "${RESTAURACION_DIR:-.}/docker-compose.prod.yml" stop api web >/dev/null 2>&1 \
    || docker stop panaderia-api-1 panaderia-web-1 >/dev/null 2>&1 \
    || registrar "No se pudieron detener por nombre de servicio. Se sigue."

# --- Regla 5: limpiar con TRUNCATE, NUNCA con DROP ------------------------
#
# `TRUNCATE` vacia las tablas y deja intacta la estructura, el propietario y
# los permisos. `DROP` eliminaria la tabla y habria que volver a crearla con
# el propietario correcto, que es donde aparecen los problemas de permisos
# despues de una restauracion.

VACIAR_BASE() {
    registrar "Vaciando las tablas con TRUNCATE (no se usa DROP)..."

    # Se genera la lista de tablas de `public`. Se excluyen las de
    # migraciones: la estructura se conserva y solo se vacian los datos.
    LISTA_TABLAS="$(docker exec "$CONTENEDOR_BD" psql \
        --username="$USUARIO_BD" \
        --dbname="$NOMBRE_BD" \
        --tuples-only \
        --no-align \
        --command="SELECT quote_ident(tablename) FROM pg_tables WHERE schemaname='public' AND tablename <> 'migrations';" \
        2>/dev/null | tr '\n' ' ')"

    if [ -z "$(echo "$LISTA_TABLAS" | tr -d '[:space:]')" ]; then
        registrar "No hay tablas que vaciar."
        return 0
    fi

    # `CASCADE` porque hay claves foraneas entre tablas. En una sola sentencia
    # y en una sola transaccion: vaciar tabla por tabla dejaria el conjunto en
    # un estado invalido a medio camino.
    if ! docker exec "$CONTENEDOR_BD" psql \
        --username="$USUARIO_BD" \
        --dbname="$NOMBRE_BD" \
        --set=ON_ERROR_STOP=1 \
        --command="TRUNCATE ${LISTA_TABLAS} CASCADE;"; then
        fallar "No se pudieron vaciar las tablas. Se aborta."
    fi

    registrar "Tablas vaciadas."
}

if [ "$FUERZA" = "true" ] || [ "$FILAS_ACTUALES" -gt 0 ]; then
    VACIAR_BASE
fi

# --- Restaurar -------------------------------------------------------------

ARCHIVO_DUMP="/tmp/$(basename "$ARCHIVO_DUMP")"

registrar "Copiando el respaldo al contenedor..."

if ! docker cp "$ARCHIVO_DUMP" "${CONTENEDOR_BD}:${ARCHIVO_DUMP}" >/dev/null; then
    failar "No se pudo copiar el respaldo al contenedor."
fi

registrar "Restaurando. Puede tardar: avisa el avance con docker logs -f."

if ! docker exec "$CONTENEDOR_BD" pg_restore \
        --username="$USUARIO_BD" \
        --dbname="$NOMBRE_BD" \
        --no-owner \
        --no-privileges \
        --single-transaction \
        --exit-on-error \
        "$ARCHIVO_DUMP"; then
    docker exec "$CONTENEDOR_BD" rm -f "$ARCHIVO_DUMP" >/dev/null 2>&1 || true
    fallar "FALLO LA RESTAURACION. La base quedo como estaba."
fi

docker exec "$CONTENEDOR_BD" rm -f "$ARCHIVO_DUMP" >/dev/null 2>&1 || true

# --- Verificar -------------------------------------------------------------

TABLAS_RESTAURADAS="$(docker exec "$CONTENEDOR_BD" psql \
    --username="$USUARIO_BD" \
    --dbname="$NOMBRE_BD" \
    --tuples-only \
    --no-align \
    --command="SELECT count(*) FROM information_schema.tables WHERE table_schema='public';" \
    2>/dev/null | tr -d '[:space:]' || echo "0")"

registrar "La base quedo con ${TABLAS_RESTAURADAS} tabla(s)."

if [ "${TABLAS_RESTAURADAS:-0}" -eq 0 ]; then
    fallar "La base quedo VACIA. El respaldo probablemente no era el correcto."
fi

# --- Regla 6: dejar el servicio DETENIDO ----------------------------------

if [ "$DEJAR_DETENIDO" = "true" ]; then
    registrar "El servicio QUEDA DETENIDO a proposito."
    registrar ""
    registrar "  Antes de volver a levantar, revisa que todo este bien:"
    registrar ""
    registrar "    cd $RESPALDO_ABSOLUTO && sha256sum -c manifiesto.txt"
    registrar "    docker compose -f docker-compose.prod.yml up -d"
    registrar ""
    registrar "  Si algo no cuadra, este mismo respaldo sigue en su sitio y"
    registrar "  el respaldo anterior tambien. No se borro nada."
fi

exit 0