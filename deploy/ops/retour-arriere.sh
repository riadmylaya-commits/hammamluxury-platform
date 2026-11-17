#!/usr/bin/env bash
# RETOUR ARRIÈRE (production, root) — revient au code, aux réglages et à la base enregistrés par deploiement-code.sh.
# Usage : retour-arriere.sh /root/pre-deploiement-<date> CONFIRMER
# Ne supprime rien : la base courante est d'abord sauvegardée, les photos importées sont mises de côté.
set -euo pipefail
PRE="${1:-}"; [[ "$PRE" =~ ^/root/pre-deploiement-[0-9]{8}-[0-9]{6}$ ]] && [ -s "$PRE/db.sql.gz" ] && [ -s "$PRE/env" ] && [ -s "$PRE/commit" ] || { echo "dossier de sauvegarde invalide"; exit 2; }
[ "${2:-}" = CONFIRMER ] || { echo "ajouter CONFIRMER"; exit 2; }
APP=/var/www/hammamluxury; DB=hl_platform; DATE=$(date +%Y%m%d-%H%M%S); OLD=$(cat "$PRE/commit"); [[ "$OLD" =~ ^[0-9a-f]{40}$ ]]
exec > >(tee -a "/root/retour-arriere-$DATE.log") 2>&1
gunzip -t "$PRE/db.sql.gz"
echo "== 1 sauvegarde de l'état courant"; install -d -m 700 "/root/avant-retour-$DATE"
mariadb-dump --single-transaction --routines --triggers "$DB" | gzip > "/root/avant-retour-$DATE/db.sql.gz"; cp -a "$APP/.env" "/root/avant-retour-$DATE/env"
/usr/local/sbin/hl-backup.sh || echo "sauvegarde externe indisponible — copie locale faite"
echo "== 2 code $OLD et réglages"; sudo -u deploy -H git -C "$APP" checkout -q --detach "$OLD"; cp -a "$PRE/env" "$APP/.env"
echo "== 3 base d'avant"; mariadb -e "DROP DATABASE \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"; gunzip -c "$PRE/db.sql.gz" | mariadb "$DB"
S=$APP/storage/app/private/spas; [ -d "$S" ] && mv "$S" "$APP/storage/app/private/spas.retire-$DATE" && echo "photos mises de côté"
echo "== 4 reconstruction"
sudo -u deploy -H bash -euo pipefail <<EOS
cd $APP
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
npm ci --no-audit --no-fund && npm run build
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
EOS
systemctl reload php8.3-fpm; supervisorctl restart hl-queue || true
C=$(curl -s -o /dev/null -w '%{http_code}' -m 20 https://prod.hammamluxury.com/fr || true); echo "prod.hammamluxury.com/fr → $C"
echo "RETOUR ARRIERE TERMINE (version $OLD)"
