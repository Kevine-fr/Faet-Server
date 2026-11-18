#!/usr/bin/env bash
# Restaure une sauvegarde faite par scripts/backup.sh (base, puis storage/app
# s'il est dans l'archive).
#
#   ./scripts/backup.sh                       # l'état actuel, d'abord
#   ./scripts/restore.sh backups/<horodatage>/faet-<horodatage>.tar
set -euo pipefail

FICHIER="${1:?Usage : restore.sh <faet-AAAAMMJJ-HHMMSS.tar>}"
APP_CONTAINER="${FAET_APP_CONTAINER:-faet-api}"
RESEAU="${FAET_RESEAU:-web}"
IMAGE_PG="${FAET_IMAGE_PG:-postgres:16-alpine}"
[ -s "$FICHIER" ] || { echo "Archive introuvable ou vide : $FICHIER" >&2; exit 1; }
TRAVAIL="$(mktemp -d)"
trap 'rm -rf "$TRAVAIL"' EXIT
tar xf "$FICHIER" -C "$TRAVAIL"

CONNEXION="$(docker exec "$APP_CONTAINER" php -r '
  require "/var/www/vendor/autoload.php";
  $app = require "/var/www/bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $c = config("database.connections.".config("database.default"));
  foreach (["host","port","database","username","password"] as $k) echo $c[$k] ?? "", "\n";
')"
{ read -r H; read -r P; read -r D; read -r U; read -r MDP; } <<< "$CONNEXION"

echo "Base : restauration de « $D » sur $H…" >&2
gzip -dc "$TRAVAIL/base.sql.gz" \
  | docker run --rm -i --network "$RESEAU" -e PGPASSWORD="$MDP" "$IMAGE_PG" \
      psql -q -v ON_ERROR_STOP=1 -h "$H" -p "${P:-5432}" -U "$U" -d "$D" >/dev/null

if [ -f "$TRAVAIL/storage-app.tar" ]; then
  echo "Fichiers : restauration de storage/app dans $APP_CONTAINER…" >&2
  docker exec -i "$APP_CONTAINER" tar xf - -C /var/www/storage/app < "$TRAVAIL/storage-app.tar"
fi
echo "✓ Restauré." >&2
