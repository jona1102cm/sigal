#!/usr/bin/env bash
set -Eeuo pipefail

# Verifica checksums, adjuntos y restauración real del dump en una base temporal.
# No altera la base operativa ni publica el contenido restaurado.
umask 077

if [[ $# -ne 1 ]]; then
    echo "Uso: $0 /ruta/al/respaldo" >&2
    exit 1
fi

PROJECT_DIR="${SIGAL_PROJECT_DIR:-/opt/sigal}"
ENV_FILE="${SIGAL_ENV_FILE:-$PROJECT_DIR/.env}"
COMPOSE_FILE="${SIGAL_COMPOSE_FILE:-$PROJECT_DIR/docker-compose.beta.yml}"
backup_dir="$(realpath "$1")"
compose=(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE")
drill_database="sigal_restore_check_$(date +%Y%m%d_%H%M%S)_$$"
database_created=0

for required in SHA256SUMS database.dump storage.tar.gz; do
    if [[ ! -f "$backup_dir/$required" ]]; then
        echo "Falta $required en el respaldo." >&2
        exit 1
    fi
done

cleanup() {
    if [[ "$database_created" -eq 1 ]]; then
        "${compose[@]}" exec -T database sh -lc \
            "dropdb --if-exists -U \"\$POSTGRES_USER\" '$drill_database'" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

(
    cd "$backup_dir"
    sha256sum -c SHA256SUMS
    tar -tzf storage.tar.gz >/dev/null
)

"${compose[@]}" exec -T database sh -lc \
    "createdb -U \"\$POSTGRES_USER\" '$drill_database'"
database_created=1

cat "$backup_dir/database.dump" | "${compose[@]}" exec -T database sh -lc \
    "pg_restore --no-owner --no-privileges -U \"\$POSTGRES_USER\" -d '$drill_database'"

"${compose[@]}" exec -T database sh -lc \
    "psql -U \"\$POSTGRES_USER\" -d '$drill_database' -v ON_ERROR_STOP=1 -Atc \
    \"select 'migrations='||count(*) from migrations union all select 'users='||count(*) from users union all select 'employees='||count(*) from employees;\""

echo "Restauración de prueba verificada; la base temporal será eliminada."
