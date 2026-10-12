#!/usr/bin/env bash
# Restauration depuis le dépôt restic vers un emplacement ISOLÉ (jamais sur la base/les fichiers en service sans décision explicite).
# Usage : RESTIC_REPOSITORY=... RESTIC_PASSWORD_FILE=... hl-restore.sh <dossier_cible> <base_cible> [hôte] [id_snapshot_db] [id_snapshot_files]
# Pré-requis : restic, client mariadb avec droits sur <base_cible> (créée si absente).
set -euo pipefail
TARGET="$1"; DB_TARGET="$2"; HOST_TAG="${3:-}"; DB_SNAP="${4:-latest}"; FILES_SNAP="${5:-latest}"
HOSTOPT=(); [ -n "$HOST_TAG" ] && HOSTOPT=(--host "$HOST_TAG")
mkdir -p "$TARGET"

echo "→ Fichiers (snapshot $FILES_SNAP)"
restic restore "$FILES_SNAP" "${HOSTOPT[@]}" --tag files --target "$TARGET/files" --quiet

echo "→ Base de données (snapshot $DB_SNAP) → $DB_TARGET"
mariadb -e "CREATE DATABASE IF NOT EXISTS \`$DB_TARGET\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
restic dump "$DB_SNAP" "${HOSTOPT[@]}" --tag db "db/$(ls -1 "$TARGET/files/var/www/hammamluxury/.env" >/dev/null 2>&1 && grep -oP '^DB_DATABASE=\K.*' "$TARGET/files/var/www/hammamluxury/.env" || echo hl_platform).sql" \
  | mariadb "$DB_TARGET"

echo "→ Vérifications"
mariadb -N -e "SELECT CONCAT('tables=', COUNT(*)) FROM information_schema.tables WHERE table_schema='$DB_TARGET'"
for t in users partners spas bookings booking_participants reviews activity_logs; do
  mariadb -N -e "SELECT CONCAT('$t=', COUNT(*)) FROM \`$DB_TARGET\`.\`$t\`" 2>/dev/null || echo "$t=ABSENTE"
done
find "$TARGET/files" -type f | wc -l | sed 's/^/fichiers restaurés=/'
echo "Terminé. Fichiers : $TARGET/files — Base : $DB_TARGET"
