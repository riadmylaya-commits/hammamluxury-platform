#!/usr/bin/env bash
# DÉPLOIEMENT d155c5a (production, root) — fait passer l'application de 1afc6f6 à la version figée ci-dessous, données conservées.
# Usage : deploiement-d155c5a.sh CONFIRMER
# Ne publie aucune fiche, n'active ni facturation ni paiement, ne modifie pas .env, ne supprime aucune donnée, ne redémarre pas le serveur.
# S'arrête AVANT toute modification si : version installée inattendue, fiche non brouillon, facturation activée, contenu téléchargé
# différent de l'empreinte validée, sauvegarde externe en échec, copie de la base non rechargeable à l'identique.
# Retour arrière (code seul, base conservée) : retour-arriere-code.sh <dossier /root/pre-deploiement-…> CONFIRMER
set -euo pipefail
[ "${1:-}" = CONFIRMER ] || { echo "ajouter CONFIRMER"; exit 2; }
PIN=d155c5a5ba49e83afb9564bef694d079adcb6aa4
TREE=25534c7b1ad8e876ac8eb84890bd59859d6999cd
FROM=1afc6f6bfe99e0940ee5fe4352b2d4cef7c86fcc
FICHES="31,34,35,36,37,38"
REPO=https://github.com/riadmylaya-commits/hammamluxury-platform.git
APP=/var/www/hammamluxury; DB=hl_platform; DATE=$(date +%Y%m%d-%H%M%S); PRE=/root/pre-deploiement-$DATE; CHK=hl_controle_$(date +%Y%m%d%H%M%S)
F2B=/etc/fail2ban/filter.d/hl-nginx-abuse.conf
exec > >(tee -a "/root/deploiement-$DATE.log") 2>&1
q() { mariadb -N -B "$DB" -e "$1"; }
g() { sudo -u deploy -H git -C "$APP" "$@"; }
art() { sudo -u deploy -H env HOME=/tmp/deploy-home php "$APP/artisan" "$@"; }
# Nombre exact de lignes de chaque table d'une base : sert aux comparaisons avant / après.
compte() { local t; for t in $(mariadb -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$1' AND table_type='BASE TABLE' ORDER BY table_name"); do echo "$t=$(mariadb -N -B "$1" -e "SELECT COUNT(*) FROM \`$t\`")"; done; }
# Tables techniques exclues de la comparaison avant / après (elles varient d'elles-mêmes) ; toutes les autres doivent rester identiques.
TECH='^(cache|cache_locks|sessions|jobs|job_batches|failed_jobs|migrations|support_requests|support_messages)='
fiches() { q "SELECT CONCAT(id,':',status,':',listing_type,':',instant_booking) FROM spas ORDER BY id"; }
facturation() { q "SELECT COUNT(*) FROM billing_settings WHERE \`key\`='enabled' AND value IN ('1','true')"; }
install -d -o deploy -g deploy -m 700 /tmp/deploy-home
MODIFIE=0
trap 'c=$?; mariadb -e "DROP DATABASE IF EXISTS \`$CHK\`" || true; if [ $c -ne 0 ]; then if [ $MODIFIE = 1 ]; then echo "ÉCHEC APRÈS MODIFICATION — le site reste en maintenance. Lancer : retour-arriere-code.sh $PRE CONFIRMER"; else echo "ARRÊT AVANT TOUTE MODIFICATION — production inchangée"; fi; fi' EXIT

echo "== 1 contrôles préalables (lecture seule)"
OLD=$(g rev-parse HEAD); echo "version installée : $OLD"
[ "$OLD" = "$FROM" ] || { echo "version installée inattendue (attendue $FROM) — arrêt"; exit 1; }
[ -z "$(g status --porcelain --untracked-files=no)" ] || { echo "fichiers suivis modifiés dans $APP — arrêt"; exit 1; }
[ "$(g rev-parse --abbrev-ref HEAD)" = main ] || { echo "le dépôt n'est pas sur la branche main — arrêt"; exit 1; }
[ "$(q "SELECT GROUP_CONCAT(id ORDER BY id) FROM spas")" = "$FICHES" ] || { echo "les fiches ne sont pas exactement $FICHES — arrêt"; exit 1; }
[ "$(q "SELECT COUNT(*) FROM spas WHERE status<>'draft' OR listing_type<>'referenced' OR instant_booking<>0")" = 0 ] || { echo "une fiche n'est pas brouillon / référencée / sans réservation immédiate — arrêt"; exit 1; }
[ "$(facturation)" = 0 ] || { echo "facturation activée en base — arrêt"; exit 1; }
if grep -E '^HL_BILLING_ENABLED=(true|1)' "$APP/.env" >/dev/null; then echo "facturation activée dans .env — arrêt"; exit 1; fi
[ "$(art migrate:status | grep -c Pending || true)" = 0 ] || { echo "migrations en attente avant déploiement — arrêt"; exit 1; }
g fetch -q "$REPO" main; g cat-file -e "$PIN^{commit}"
[ "$(g rev-parse "$PIN^{tree}")" = "$TREE" ] || { echo "le contenu téléchargé n'a pas l'empreinte validée $TREE — arrêt"; exit 1; }
g merge-base --is-ancestor "$OLD" "$PIN" || { echo "la version figée ne prolonge pas la version installée — arrêt"; exit 1; }
echo "version $PIN téléchargée, empreinte $TREE conforme (rien n'est encore modifié)"

echo "== 2 sauvegarde externe (site encore ouvert)"
/usr/local/sbin/hl-backup.sh

echo "== 3 maintenance, copie locale de la base, rechargement de contrôle"
install -d -m 700 "$PRE"; echo "$OLD" > "$PRE/commit"; cp -a "$APP/.env" "$PRE/env"; [ -f "$F2B" ] && cp -a "$F2B" "$PRE/filter-hl-nginx-abuse.conf"
ENVSUM=$(sha256sum < "$APP/.env")
MODIFIE=1; art down --retry=60
supervisorctl stop hl-queue
mariadb-dump --single-transaction --routines --triggers "$DB" | gzip > "$PRE/db.sql.gz"; gunzip -t "$PRE/db.sql.gz"
compte "$DB" > "$PRE/comptes-avant.txt"; fiches > "$PRE/fiches-avant.txt"
mariadb -e "CREATE DATABASE \`$CHK\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"; gunzip -c "$PRE/db.sql.gz" | mariadb "$CHK"
compte "$CHK" > "$PRE/comptes-copie.txt"; mariadb -e "DROP DATABASE \`$CHK\`"
cmp -s "$PRE/comptes-avant.txt" "$PRE/comptes-copie.txt" || { echo "la copie rechargée diffère de la base en service — arrêt (code non modifié)"; diff "$PRE/comptes-avant.txt" "$PRE/comptes-copie.txt" || true; exit 1; }
echo "copie de la base rechargée à l'identique : $(wc -l < "$PRE/comptes-avant.txt") tables comparées"
grep -E '^(users|spas|bookings|booking_notifications|migrations)=' "$PRE/comptes-avant.txt" | tr '\n' ' '; echo

echo "== 4 code $PIN"; art optimize:clear -q || true
g merge -q --ff-only "$PIN"; [ "$(g rev-parse HEAD)" = "$PIN" ]; g --no-pager log --oneline -1

echo "== 5 mise à jour (dépendances, compilation, 2 migrations d'ajout, caches) — chaque étape doit réussir"
sudo -u deploy -H env HOME=/tmp/deploy-home bash -euo pipefail <<EOS
cd $APP
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
npm ci --no-audit --no-fund
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan filament:assets
EOS
[ "$APP/public/build/manifest.json" -nt "$PRE/commit" ] || { echo "compilation des ressources non renouvelée — arrêt"; exit 1; }
if [ -f "$F2B" ] && ! cmp -s "$APP/deploy/fail2ban/filter-hl-nginx-abuse.conf" "$F2B"; then install -m 0644 "$APP/deploy/fail2ban/filter-hl-nginx-abuse.conf" "$F2B"; fail2ban-client reload hl-nginx-abuse >/dev/null; echo "filtre fail2ban mis à jour (rapports /csp-report ignorés)"; fi
systemctl reload php8.3-fpm; supervisorctl start hl-queue

echo "== 6 contrôles avant réouverture (site toujours en maintenance)"
P=$(art migrate:status | grep -c Pending || true); echo "migrations en attente : $P"; [ "$P" = 0 ]
[ "$(q 'SELECT COUNT(*) FROM migrations')" = "$(( $(grep -oP '^migrations=\K.*' "$PRE/comptes-avant.txt") + 2 ))" ] || { echo "nombre de migrations inattendu — arrêt"; exit 1; }
compte "$DB" > "$PRE/comptes-apres.txt"; fiches > "$PRE/fiches-apres.txt"
diff <(grep -vE "$TECH" "$PRE/comptes-avant.txt") <(grep -vE "$TECH" "$PRE/comptes-apres.txt") || { echo "le nombre de lignes d'une table de données a changé — arrêt"; exit 1; }
cmp -s "$PRE/fiches-avant.txt" "$PRE/fiches-apres.txt" || { echo "l'état des fiches a changé — arrêt"; exit 1; }
[ "$(q "SELECT COUNT(*) FROM spas WHERE status<>'draft'")" = 0 ] || { echo "une fiche n'est plus en brouillon — arrêt"; exit 1; }
[ "$(q "SELECT COUNT(*) FROM spas WHERE last_minute_enabled<>0")" = 0 ] || { echo "option dernière minute activée sur une fiche — arrêt"; exit 1; }
[ "$(facturation)" = 0 ] || { echo "facturation activée — arrêt"; exit 1; }
[ "$(sha256sum < "$APP/.env")" = "$ENVSUM" ] || { echo ".env modifié pendant le déploiement — arrêt"; exit 1; }
art schedule:list | grep -q 'hl:expire-waiting' || { echo "tâche d'expiration absente du planificateur — arrêt"; exit 1; }
grep -qs "schedule:run" /etc/cron.d/hl-schedule || crontab -u deploy -l 2>/dev/null | grep -q "schedule:run" || { echo "planificateur système absent — arrêt"; exit 1; }
supervisorctl status hl-queue | grep -q RUNNING || { echo "file d'envoi arrêtée — arrêt"; exit 1; }
echo "comptes, fiches (six brouillons), facturation, .env, planificateur, file d'envoi : conformes"

echo "== 7 réouverture et contrôles publics"
art up
url() { curl -s -o /dev/null -w '%{http_code}' -m 20 "https://prod.hammamluxury.com$1" || true; }
OK=1; for u in /fr /fr/recherche /admin/login /partenaire/login /up; do c=$(url "$u"); echo "$u → $c"; [ "$c" = 200 ] || OK=0; done
c=$(url /fr/spa/mythic-oriental-spa); echo "/fr/spa/mythic-oriental-spa → $c (404 attendu : fiche en brouillon)"; [ "$c" = 404 ] || OK=0
curl -sI -m 20 https://prod.hammamluxury.com/fr | grep -qi '^x-robots-tag:.*noindex' && echo "site non indexable" || { echo "en-tête noindex absent"; OK=0; }
if [ $OK = 1 ]; then echo "DEPLOIEMENT d155c5a OK — retour arrière possible avec : retour-arriere-code.sh $PRE CONFIRMER"; else art down --retry=60; echo "contrôle public en échec — site remis en maintenance"; exit 1; fi
