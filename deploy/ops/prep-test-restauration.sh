#!/usr/bin/env bash
# PRÉPARATION — test de restauration ISOLÉ (root) : restaure la dernière sauvegarde externe dans un dossier et une base temporaires,
# compare avec la base en service, puis supprime uniquement ces copies temporaires. La base hl_platform et le site ne sont pas modifiés.
set -euo pipefail
set -a; . /etc/hl-backup/env; set +a
DATE=$(date +%Y%m%d%H%M%S); T=/root/restore-test-$DATE; DB=hl_restore_test_$DATE
trap 'mariadb -e "DROP DATABASE IF EXISTS \`$DB\`"; rm -rf "$T"' EXIT
/usr/local/sbin/hl-restore.sh "$T" "$DB" hl-prod
cmp() { mariadb -N -e "SELECT CONCAT((SELECT COUNT(*) FROM $1.users),'/',(SELECT COUNT(*) FROM $1.spas),'/',(SELECT COUNT(*) FROM $1.bookings),'/',(SELECT COUNT(*) FROM $1.migrations))"; }
A=$(cmp hl_platform); B=$(cmp "$DB"); echo "en service users/spas/bookings/migrations=$A ; restauré=$B"
[ -s "$T/files/var/www/hammamluxury/.env" ] && echo ".env restauré : présent"
[ "$A" = "$B" ] && echo "TEST RESTAURATION OK" || { echo "TEST RESTAURATION : ÉCART"; exit 1; }
