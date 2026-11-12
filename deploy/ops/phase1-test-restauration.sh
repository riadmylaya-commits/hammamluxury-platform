#!/usr/bin/env bash
# PHASE 1 (staging, root) — test de restauration du dépôt hl-prod dans une base ISOLÉE, puis suppression. La staging réelle n'est pas touchée (CHECKSUM TABLE avant/après).
# Pré-requis : clé SSH de lecture vers le sous-compte hl-prod déposée sur la staging (clé distincte), mot de passe restic prod saisi une fois (RESTIC_PASSWORD), restic installé.
set -euo pipefail
[ -f /root/ACCORD-PHASE-1 ] || { echo "Pas d'accord écrit — arrêt."; exit 1; }
: "${RESTIC_PASSWORD:?mot de passe restic de la production requis (saisi à l'écran, non stocké)}"
export RESTIC_REPOSITORY="sftp:hl-storagebox-prod-ro:hl-prod"
DB=hl_restore_prod_test; T=/root/restore-test-$(date +%Y%m%d-%H%M%S)
mysql -N hl_platform -e "CHECKSUM TABLE spas, treatments, spa_photos, spa_hours, extras, users" > /root/staging-before.txt
bash /var/www/hammamluxury/deploy/backup/hl-restore.sh "$T" "$DB" hl-prod | tee "$T.log"
echo "== comparaison avec la production (à rapprocher des comptes relevés sur prod : tables, users, spas, bookings)"
mysql -N "$DB" -e "SELECT 'tables', COUNT(*) FROM information_schema.tables WHERE table_schema='$DB'; SELECT 'users', COUNT(*) FROM users; SELECT 'spas', COUNT(*) FROM spas; SELECT 'migrations', COUNT(*) FROM migrations;"
grep -c '^APP_KEY=' "$T/files/var/www/hammamluxury/.env" >/dev/null && echo ".env restauré lisible"
mysql -e "DROP DATABASE \`$DB\`"; rm -rf "$T"
mysql -N hl_platform -e "CHECKSUM TABLE spas, treatments, spa_photos, spa_hours, extras, users" > /root/staging-after.txt
diff /root/staging-before.txt /root/staging-after.txt && echo "Staging intacte (sommes de contrôle identiques). Base de test supprimée."
