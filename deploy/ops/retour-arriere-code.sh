#!/usr/bin/env bash
# RETOUR ARRIÈRE DU CODE SEUL (production, root) — remet le code enregistré par deploiement-d155c5a.sh. LA BASE N'EST NI RESTAURÉE NI VIDÉE :
# les réservations, comptes et notifications reçus depuis le déploiement sont conservés. Aucune migration n'est annulée : les deux tables
# d'assistance et les trois colonnes « dernière minute » ajoutées restent en place, inutilisées par l'ancien code.
# Usage : retour-arriere-code.sh /root/pre-deploiement-<date> CONFIRMER
# .env n'est pas touché (le déploiement ne le modifie pas). Une copie de la base courante est faite par précaution, sans rien écraser.
set -euo pipefail
PRE="${1:-}"; [[ "$PRE" =~ ^/root/pre-deploiement-[0-9]{8}-[0-9]{6}$ ]] && [ -s "$PRE/commit" ] || { echo "dossier de sauvegarde invalide"; exit 2; }
[ "${2:-}" = CONFIRMER ] || { echo "ajouter CONFIRMER"; exit 2; }
APP=/var/www/hammamluxury; DB=hl_platform; DATE=$(date +%Y%m%d-%H%M%S); OLD=$(cat "$PRE/commit"); [[ "$OLD" =~ ^[0-9a-f]{40}$ ]]
F2B=/etc/fail2ban/filter.d/hl-nginx-abuse.conf; SAUV=/root/avant-retour-$DATE
exec > >(tee -a "/root/retour-arriere-$DATE.log") 2>&1
q() { mariadb -N -B "$DB" -e "$1"; }
g() { sudo -u deploy -H git -C "$APP" "$@"; }
art() { sudo -u deploy -H env HOME=/tmp/deploy-home php "$APP/artisan" "$@"; }
etat() { q "SELECT CONCAT('comptes=',(SELECT COUNT(*) FROM users),' fiches=',(SELECT COUNT(*) FROM spas),' reservations=',(SELECT COUNT(*) FROM bookings),' notifications=',(SELECT COUNT(*) FROM booking_notifications),' derniere_reservation=',(SELECT COALESCE(MAX(id),0) FROM bookings),' fiches_non_brouillon=',(SELECT COUNT(*) FROM spas WHERE status<>'draft'))"; }
install -d -o deploy -g deploy -m 700 /tmp/deploy-home
g cat-file -e "$OLD^{commit}" || { echo "ancienne version absente du dépôt — arrêt"; exit 1; }
[ -z "$(g status --porcelain --untracked-files=no)" ] || { echo "fichiers suivis modifiés dans $APP — arrêt"; exit 1; }
echo "version en place : $(g rev-parse HEAD) ; version à remettre : $OLD"
echo "== 1 maintenance et copie de précaution de la base courante (lecture seule)"
art down --retry=60 || true; supervisorctl stop hl-queue || true
install -d -m 700 "$SAUV"; mariadb-dump --single-transaction --routines --triggers "$DB" | gzip > "$SAUV/db.sql.gz"; gunzip -t "$SAUV/db.sql.gz"
AVANT=$(etat); echo "avant : $AVANT"
echo "== 2 ancien code $OLD (base et .env inchangés)"; art optimize:clear -q || true
g checkout -q -B main "$OLD"; [ "$(g rev-parse HEAD)" = "$OLD" ]
echo "== 3 reconstruction"
sudo -u deploy -H env HOME=/tmp/deploy-home bash -euo pipefail <<EOS
cd $APP
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
npm ci --no-audit --no-fund
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan filament:assets
EOS
if [ -s "$PRE/filter-hl-nginx-abuse.conf" ] && ! cmp -s "$PRE/filter-hl-nginx-abuse.conf" "$F2B"; then install -m 0644 "$PRE/filter-hl-nginx-abuse.conf" "$F2B"; fail2ban-client reload hl-nginx-abuse >/dev/null; echo "filtre fail2ban d'avant remis"; fi
systemctl reload php8.3-fpm; supervisorctl start hl-queue
echo "== 4 contrôles : aucune donnée perdue"
APRES=$(etat); echo "après : $APRES"
[ "$AVANT" = "$APRES" ] || { echo "ÉCART de données pendant le retour arrière — le site reste en maintenance ; copie de la base : $SAUV"; exit 1; }
art up
OK=1; for u in /fr /admin/login /partenaire/login /up; do c=$(curl -s -o /dev/null -w '%{http_code}' -m 20 "https://prod.hammamluxury.com$u" || true); echo "$u → $c"; [ "$c" = 200 ] || OK=0; done
[ $OK = 1 ] && echo "RETOUR ARRIERE DU CODE TERMINE (version $OLD) — base conservée, copie de précaution : $SAUV" || { echo "le site ne répond pas 200 — vérifier avant toute autre action"; exit 1; }
