#!/usr/bin/env bash
# Épreuve du contrat de sauvegarde, sans Docker (faux `docker`).
#   bash tests/scripts/sauvegarde.test.sh
set -euo pipefail
RACINE="$(cd "$(dirname "$0")/../.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/storage"
cat > "$T/bin/docker" <<'FAUX'
#!/usr/bin/env bash
case "$*" in
  *"php -r"*) printf 'faet-db\n5432\nfaet\nfaet_user\nsecret\n' ;;
  *pg_dump*) cat "$FAUX/base" ;;
  *psql*) cat > "$FAUX/base" ;;
  *"tar cf"*) tar cf - -C "$FAUX/storage" . ;;
  *"tar xf"*) tar xf - -C "$FAUX/storage" ;;
  *) echo "faux docker : commande inattendue $*" >&2; exit 2 ;;
esac
FAUX
chmod +x "$T/bin/docker"
export PATH="$T/bin:$PATH" FAUX="$T" BACKUP_DIR="$T/sauvegardes"

echo "CREATE TABLE products();" > "$T/base"
echo "facture" > "$T/storage/facture.pdf"
F="$(bash "$RACINE/scripts/backup.sh" 2>/dev/null | tail -n1)"
[ -s "$F" ] || { echo "ÉCHEC : pas d'archive ($F)"; exit 1; }
echo "abîmé" > "$T/base"; rm "$T/storage/"*
bash "$RACINE/scripts/restore.sh" "$F" 2>/dev/null
grep -q "CREATE TABLE products" "$T/base" || { echo "ÉCHEC : base non restaurée"; exit 1; }
grep -q facture "$T/storage/facture.pdf" || { echo "ÉCHEC : storage non restauré"; exit 1; }
: > "$T/base"
if bash "$RACINE/scripts/backup.sh" >/dev/null 2>&1; then echo "ÉCHEC : un dump vide passe pour une sauvegarde"; exit 1; fi
echo "✓ sauvegarde/restauration : contrat tenu"
