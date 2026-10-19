#!/usr/bin/env bash
# ============================================================
#  commits_del_dia.sh
#  Genera un archivo con los mensajes de tus commits de hoy.
#
#  Uso:
#    ./commits_del_dia.sh [ruta_del_repo] [archivo_salida]
#
#  Sin argumentos: usa la carpeta actual como repo y guarda
#  commits_AAAA-MM-DD.txt junto a este script.
# ============================================================

set -euo pipefail

REPO="${1:-$PWD}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOY="$(date +%F)"
SALIDA="${2:-$SCRIPT_DIR/commits_$HOY.txt}"

if ! git -C "$REPO" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "\"$REPO\" no es un repositorio git" >&2
    exit 1
fi

EMAIL="$(git -C "$REPO" config user.email || true)"
if [[ -z "$EMAIL" ]]; then
    echo "No hay user.email configurado en git" >&2
    exit 1
fi

# Las rutas relativas de salida se resuelven antes de movernos al repo
mkdir -p "$(dirname "$SALIDA")"
SALIDA="$(cd "$(dirname "$SALIDA")" && pwd)/$(basename "$SALIDA")"

cd "$REPO"

TOTAL="$(git rev-list --all --since=midnight --author="$EMAIL" --count)"

{
    echo "Commits del $HOY - $EMAIL"
    echo "Repositorio: $REPO"
    echo "Total: $TOTAL"
    echo "------------------------------------------------------------"
    echo
    if [[ "$TOTAL" != "0" ]]; then
        git log --all --since=midnight --author="$EMAIL" --reverse \
            --date=format:%H:%M \
            --pretty=tformat:"[%h] %ad - %s%n%b"
    fi
} > "$SALIDA"

echo "Listo: $TOTAL commit(s). Archivo generado en:"
echo "$SALIDA"

