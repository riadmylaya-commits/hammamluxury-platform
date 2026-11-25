#!/usr/bin/env bash
# RETOUR ARRIÈRE DU CODE SEUL (production, root) — remet le code enregistré par deploiement-d155c5a.sh. LA BASE N'EST NI RESTAURÉE NI VIDÉE :
# les réservations, comptes et notifications reçus depuis le déploiement sont conservés. Aucune migration n'est annulée : les deux tables
# d'assistance et les trois colonnes « dernière minute » ajoutées restent en place, inutilisées par l'ancien code.
# Usage : retour-arriere-code.sh /root/pre-deploiement-<date> CONFIRMER
# .env n'est pas touché. Une copie de la base courante est faite par précaution ; elle n'est jamais rechargée par ce script.
# S'arrête AVANT tout changement de code si la maintenance n'est pas effective ou si la file d'envoi n'est pas arrêtée.
set -euo pipefail
PRE="${1:-}"; [[ "$PRE" =~ ^/root/pre-deploiement-[0-9]{8}-[0-9]{6}$ ]] && [ -s "$PRE/commit" ] || { echo "dossier de sauvegarde invalide"; exit 2; }
[ "${2:-}" = CONFIRMER ] || { echo "ajouter CONFIRMER"; exit 2; }
APP=/var/www/hammamluxury; DB=hl_platform; DATE=$(date +%Y%m%d-%H%M%S); OLD=$(cat "$PRE/commit"); [[ "$OLD" =~ ^[0-9a-f]{40}$ ]]
SITE=https://prod.hammamluxury.com
F2B=/etc/fail2ban/filter.d/hl-nginx-abuse.conf; SAUV=/root/avant-retour-$DATE
# Verrou commun au déploiement et au retour arrière : une seule intervention à la fois.
exec 9>/run/lock/hl-intervention.lock; flock -n 9 || { echo "une autre intervention est déjà en cours — arrêt, rien n'est modifié"; exit 1; }
exec > >(tee -a "/root/retour-arriere-$DATE.log") 2>&1
q() { mariadb -N -B "$DB" -e "$1"; }
g() { sudo -u deploy -H git -C "$APP" "$@"; }
art() { sudo -u deploy -H env HOME=/tmp/deploy-home php "$APP/artisan" "$@"; }
url() { curl -s -o /dev/null -w '%{http_code}' -m 20 "$SITE$1" || true; }
gel() {
    local i
    [ "$(url /fr)" = 503 ] || { echo "le site n'est pas en maintenance — arrêt"; return 1; }
    [ -f "$APP/storage/framework/down" ] || { echo "marqueur de maintenance absent : les tâches planifiées ne sont pas suspendues — arrêt"; return 1; }
    supervisorctl status hl-queue | grep -qE 'STOPPED|EXITED' || { echo "la file d'envoi n'est pas arrêtée — arrêt"; return 1; }
    for i in $(seq 1 30); do pgrep -u deploy -f artisan >/dev/null || return 0; sleep 2; done
    echo "une commande de l'application est encore en cours (tâche planifiée) — arrêt"; return 1
}
TECH="'cache','cache_locks','sessions','jobs','job_batches','failed_jobs'"
colonnes() { mariadb -N -B -e "SET SESSION group_concat_max_len=1000000; SELECT table_name, GROUP_CONCAT(CONCAT('\`',column_name,'\`') ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema='$DB' AND table_name NOT IN ($TECH) AND table_name IN (SELECT table_name FROM information_schema.tables WHERE table_schema='$DB' AND table_type='BASE TABLE') GROUP BY table_name ORDER BY table_name"; }
# Empreinte SHA-256 du contenu de chaque table (toutes les lignes, toutes les colonnes).
empreintes() { local t c h; while IFS=$'\t' read -r t c; do h=$(mariadb -N -B --default-character-set=utf8mb4 "$DB" -e "SELECT $c FROM \`$t\` ORDER BY $c" | sha256sum | cut -d' ' -f1); echo "$t=$h"; done < "$1"; }
etat() { q "SELECT CONCAT('comptes=',(SELECT COUNT(*) FROM users),' fiches=',(SELECT COUNT(*) FROM spas),' reservations=',(SELECT COUNT(*) FROM bookings),' notifications=',(SELECT COUNT(*) FROM booking_notifications),' derniere_reservation=',(SELECT COALESCE(MAX(id),0) FROM bookings),' fiches_non_brouillon=',(SELECT COUNT(*) FROM spas WHERE status<>'draft'),' fiches_non_referencees=',(SELECT COUNT(*) FROM spas WHERE listing_type<>'referenced'),' factures=',(SELECT COUNT(*) FROM invoices),' paiements_enregistres=',(SELECT COUNT(*) FROM invoice_payments),' facturation_activee=',(SELECT COUNT(*) FROM billing_settings WHERE \`key\`='enabled' AND value IN ('1','true')))"; }
install -d -o deploy -g deploy -m 700 /tmp/deploy-home
MAINT=0
trap 'c=$?; if [ $c -ne 0 ]; then if [ $MAINT = 1 ]; then echo "ÉCHEC — le site reste en maintenance ; la base n a pas été restaurée ni vidée"; else echo "ARRÊT AVANT TOUTE MODIFICATION"; fi; fi' EXIT
g cat-file -e "$OLD^{commit}" || { echo "ancienne version absente du dépôt — arrêt"; exit 1; }
[ -z "$(g status --porcelain --untracked-files=no)" ] || { echo "fichiers suivis modifiés dans $APP — arrêt"; exit 1; }
echo "version en place : $(g rev-parse HEAD) ; version à remettre : $OLD"

echo "== 1 maintenance, arrêt de la file d'envoi, copie de précaution de la base courante (lecture seule)"
art down --retry=60 || { echo "le mode maintenance n'a pas pu être activé — arrêt, code inchangé, site laissé tel quel"; exit 1; }; MAINT=1
[ "$(url /fr)" = 503 ] || { echo "le mode maintenance n'est pas effectif — arrêt, code inchangé"; exit 1; }
supervisorctl stop hl-queue || true
gel || { echo "code inchangé"; exit 1; }
install -d -m 700 "$SAUV"; mariadb-dump --single-transaction --routines --triggers "$DB" | gzip > "$SAUV/db.sql.gz"; gunzip -t "$SAUV/db.sql.gz"
colonnes > "$SAUV/colonnes.txt"; empreintes "$SAUV/colonnes.txt" > "$SAUV/contenu-avant.txt"
AVANT=$(etat); echo "avant : $AVANT"

echo "== 2 ancien code $OLD (base et .env inchangés)"; art optimize:clear -q || true
[ "$(url /fr)" = 503 ] || { echo "le site n'est plus en maintenance — arrêt, code inchangé"; art down --retry=60 || true; exit 1; }
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
systemctl reload php8.3-fpm

echo "== 4 contrôles : aucune donnée perdue ni modifiée (site en maintenance, file d'envoi arrêtée, tâches planifiées suspendues)"
gel
APRES=$(etat); echo "après : $APRES"
[ "$AVANT" = "$APRES" ] || { echo "ÉCART de données pendant le retour arrière — copie de la base : $SAUV"; exit 1; }
empreintes "$SAUV/colonnes.txt" > "$SAUV/contenu-apres.txt"
diff "$SAUV/contenu-avant.txt" "$SAUV/contenu-apres.txt" || { echo "le CONTENU d'une table a changé pendant le retour arrière — copie de la base : $SAUV"; exit 1; }
echo "contenu de $(wc -l < "$SAUV/contenu-avant.txt") tables identique avant et après"
grep -q 'fiches_non_brouillon=0 fiches_non_referencees=0 factures=0 paiements_enregistres=0 facturation_activee=0$' <<<"$APRES" || { echo "fiche publiée, commission, facture, paiement ou facturation non nuls — arrêt"; exit 1; }
supervisorctl start hl-queue
supervisorctl status hl-queue | grep -q RUNNING || { echo "file d'envoi arrêtée — arrêt"; exit 1; }

echo "== 5 réouverture et contrôles publics"
art up; rm -f /root/deploiement-en-attente
OK=1; for u in /fr /admin/login /partenaire/login /up; do c=$(url "$u"); echo "$u → $c"; [ "$c" = 200 ] || OK=0; done
if [ $OK = 1 ]; then echo "RETOUR ARRIERE DU CODE TERMINE (version $OLD) — base conservée, copie de précaution : $SAUV"
else art down --retry=60; echo "le site ne répond pas 200 — site remis en maintenance"; exit 1; fi
