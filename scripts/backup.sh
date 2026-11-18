#!/usr/bin/env bash
# Sauvegarde de la base Faet (PostgreSQL) et des fichiers de storage/app.
#
#   ./scripts/backup.sh                    -> backups/<horodatage>/faet-<horodatage>.tar
#   BACKUP_DIR=/srv/sauvegardes ./scripts/backup.sh
#   BACKUP_FICHIERS=0 ./scripts/backup.sh  -> la base seule
#
# Contrat commun aux dépôts du VPS (le dépôt Infrastructure envoie les
# sauvegardes sur MinIO) : un seul fichier écrit, son chemin en DERNIÈRE ligne
# de la sortie standard, les messages sur la sortie d'erreur.
#
# La base de Faet n'est pas un conteneur de ce dépôt : sa connexion est celle
# que Laravel utilise réellement, lue DANS le conteneur faet-api (config mise
# en cache comprise). pg_dump tourne dans un conteneur postgres jetable sur le
# même réseau, l'image de l'API n'ayant pas le client Postgres.
set -euo pipefail

APP_CONTAINER="${FAET_APP_CONTAINER:-faet-api}"
RESEAU="${FAET_RESEAU:-web}"
IMAGE_PG="${FAET_IMAGE_PG:-postgres:16-alpine}"
STAMP="$(date +%Y%m%d-%H%M%S)"
RACINE="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${BACKUP_DIR:-$RACINE/backups/$STAMP}"
TRAVAIL="$(mktemp -d)"
trap 'rm -rf "$TRAVAIL"' EXIT
mkdir -p "$DEST"
FICHIER="$DEST/faet-$STAMP.tar"

echo "Connexion à la base lue dans $APP_CONTAINER…" >&2
CONNEXION="$(docker exec "$APP_CONTAINER" php -r '
  require "/var/www/vendor/autoload.php";
  $app = require "/var/www/bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $nom = config("database.default");
  $c = config("database.connections.$nom");
  if (($c["driver"] ?? "") !== "pgsql") { fwrite(STDERR, "pilote non pris en charge : ".($c["driver"] ?? "?")."\n"); exit(3); }
  foreach (["host","port","database","username","password"] as $k) echo $c[$k] ?? "", "\n";
')"
{ read -r H; read -r P; read -r D; read -r U; read -r MDP; } <<< "$CONNEXION"
[ -n "$H" ] && [ -n "$D" ] || { echo "Échec : connexion introuvable." >&2; exit 1; }

echo "Base : pg_dump de « $D » sur $H…" >&2
docker run --rm --network "$RESEAU" -e PGPASSWORD="$MDP" "$IMAGE_PG" \
  pg_dump -h "$H" -p "${P:-5432}" -U "$U" -d "$D" --clean --if-exists --no-owner \
  | gzip > "$TRAVAIL/base.sql.gz"
if [ "$(gzip -dc "$TRAVAIL/base.sql.gz" | head -c 1 | wc -c)" -eq 0 ]; then
  echo "Échec : dump de la base vide." >&2; exit 1
fi

if [ "${BACKUP_FICHIERS:-1}" != "0" ]; then
  echo "Fichiers : storage/app depuis $APP_CONTAINER…" >&2
  docker exec "$APP_CONTAINER" tar cf - -C /var/www/storage/app . > "$TRAVAIL/storage-app.tar"
fi

tar cf "$FICHIER.part" -C "$TRAVAIL" .
mv "$FICHIER.part" "$FICHIER"
echo "✓ $(du -h "$FICHIER" | cut -f1)" >&2
echo "$FICHIER"
