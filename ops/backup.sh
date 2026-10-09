#!/usr/bin/env bash
set -Eeuo pipefail

# Respalda coordinadamente PostgreSQL, archivos adjuntos y configuración del
# despliegue. Se ejecuta como root porque el archivo de entorno contiene secretos.
umask 077

PROJECT_DIR="${SIGAL_PROJECT_DIR:-/opt/sigal}"
ENV_FILE="${SIGAL_ENV_FILE:-$PROJECT_DIR/.env}"
COMPOSE_FILE="${SIGAL_COMPOSE_FILE:-$PROJECT_DIR/docker-compose.beta.yml}"
BACKUP_ROOT="${SIGAL_BACKUP_ROOT:-$PROJECT_DIR/backups}"
RETENTION_DAYS="${SIGAL_BACKUP_RETENTION_DAYS:-30}"
MAINTENANCE="${SIGAL_BACKUP_MAINTENANCE:-1}"
LABEL="${SIGAL_BACKUP_LABEL:-}"

if [[ ! -r "$ENV_FILE" || ! -r "$COMPOSE_FILE" ]]; then
    echo "No se puede leer el entorno o Docker Compose de SIGAL." >&2
    exit 1
fi

if [[ -n "$LABEL" && ! "$LABEL" =~ ^[a-z0-9][a-z0-9-]{0,47}$ ]]; then
    echo "SIGAL_BACKUP_LABEL solo admite minúsculas, números y guiones." >&2
    exit 1
fi

stamp="$(date +%Y%m%d-%H%M%S)"
suffix="${LABEL:+-$LABEL}"
partial_dir="$BACKUP_ROOT/.${stamp}${suffix}.partial"
backup_dir="$BACKUP_ROOT/${stamp}${suffix}"
compose=(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE")
maintenance_enabled=0

cleanup() {
    local exit_code=$?

    if [[ "$maintenance_enabled" -eq 1 ]]; then
        "${compose[@]}" exec -T app php artisan up >/dev/null 2>&1 || true
    fi

    if [[ "$exit_code" -ne 0 ]]; then
        rm -rf -- "$partial_dir"
    fi
}
trap cleanup EXIT

mkdir -p "$BACKUP_ROOT"
rm -rf -- "$partial_dir"
mkdir "$partial_dir"
cd "$PROJECT_DIR"

if [[ "$MAINTENANCE" == "1" ]]; then
    "${compose[@]}" exec -T app php artisan down --retry=60 >/dev/null
    maintenance_enabled=1
fi

"${compose[@]}" exec -T database sh -c \
    'pg_dump -Fc -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
    > "$partial_dir/database.dump"

"${compose[@]}" exec -T app sh -c 'tar -C storage -czf - .' \
    > "$partial_dir/storage.tar.gz"

install -m 600 "$ENV_FILE" "$partial_dir/environment.env"

users="$("${compose[@]}" exec -T database sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select count(*) from users"')"
employees="$("${compose[@]}" exec -T database sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select count(*) from employees"')"
expedients="$("${compose[@]}" exec -T database sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select count(*) from expedients"')"
documents="$("${compose[@]}" exec -T database sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select count(*) from documents"')"
material_requests="$("${compose[@]}" exec -T database sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select count(*) from material_requests"')"
warehouse_receipts="$("${compose[@]}" exec -T database sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select count(*) from warehouse_receipts"')"
release="$(cat "$PROJECT_DIR/RELEASE" 2>/dev/null || true)"

cat > "$partial_dir/MANIFEST.txt" <<EOF
Tipo: respaldo coordinado SIGAL
Creado: $(date --iso-8601=seconds)
Etiqueta: ${LABEL:-sin-etiqueta}
Version: ${release:-no-registrada}
Usuarios: $users
Funcionarios: $employees
Expedientes: $expedients
Documentos: $documents
Solicitudes de materiales: $material_requests
Ingresos de almacen: $warehouse_receipts
Incluye: PostgreSQL, storage y configuracion de entorno protegida
EOF

(
    cd "$partial_dir"
    sha256sum MANIFEST.txt database.dump environment.env storage.tar.gz > SHA256SUMS
    sha256sum -c SHA256SUMS >/dev/null
    tar -tzf storage.tar.gz >/dev/null
)

cat "$partial_dir/database.dump" | "${compose[@]}" exec -T database pg_restore -l >/dev/null
mv "$partial_dir" "$backup_dir"
chmod -R go-rwx "$backup_dir"

if [[ "$maintenance_enabled" -eq 1 ]]; then
    "${compose[@]}" exec -T app php artisan up >/dev/null
    maintenance_enabled=0
fi

# Las líneas base permanentes viven fuera de BACKUP_ROOT y no entran en esta retención.
find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d \
    -name '20??????-??????*' -mtime "+$RETENTION_DAYS" -exec rm -rf -- {} +

echo "$backup_dir"
