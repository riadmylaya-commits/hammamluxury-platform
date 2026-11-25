#!/usr/bin/env bash
# DÉPLOIEMENT d155c5a (production, root) — fait passer l'application de 1afc6f6 à la version figée ci-dessous, données conservées.
# DEUX ÉTAPES, deux commandes :
#   deploiement-d155c5a.sh CONFIRMER  → installe la version, contrôle tout, envoie UN e-mail de contrôle puis S'ARRÊTE, site en maintenance.
#   deploiement-d155c5a.sh ROUVRIR    → seulement après confirmation écrite de la propriétaire qu'elle a reçu l'e-mail dans Gmail :
#                                        recontrôle les données, relance la file d'envoi, rouvre le site, contrôles publics.
# Entre les deux : site en maintenance, file d'envoi arrêtée. Pour renoncer : retour-arriere-code.sh <dossier> CONFIRMER.
# Ne publie aucune fiche, n'active ni facturation ni paiement, ne modifie pas .env, ne supprime aucune donnée, ne redémarre pas le serveur.
# NE RESTAURE JAMAIS LA BASE EN SERVICE : le seul rechargement se fait dans une base temporaire de contrôle au nom distinct, supprimée ensuite.
# S'arrête AVANT toute modification si : autre intervention en cours, version installée inattendue, fiche non brouillon, commission ou
# facturation non nulle, déploiement déjà tenté, contenu téléchargé différent de l'empreinte validée, sauvegarde externe en échec.
# Retour arrière (code seul, base conservée) : retour-arriere-code.sh <dossier /root/pre-deploiement-…> CONFIRMER
set -euo pipefail
case "${1:-}" in CONFIRMER|ROUVRIR) ETAPE=$1 ;; *) echo "usage : CONFIRMER (étape 1) ou ROUVRIR (étape 2, après confirmation de réception de l'e-mail)"; exit 2 ;; esac
ATTENTE=/root/deploiement-en-attente
PIN=d155c5a5ba49e83afb9564bef694d079adcb6aa4
TREE=25534c7b1ad8e876ac8eb84890bd59859d6999cd
FROM=1afc6f6bfe99e0940ee5fe4352b2d4cef7c86fcc
FICHES="31,34,35,36,37,38"
REPO=https://github.com/riadmylaya-commits/hammamluxury-platform.git
SITE=https://prod.hammamluxury.com
MAILTEST=hammamluxury26@gmail.com
APP=/var/www/hammamluxury; DB=hl_platform; DATE=$(date +%Y%m%d-%H%M%S); PRE=/root/pre-deploiement-$DATE; CHK=hl_controle_$(date +%Y%m%d%H%M%S)
F2B=/etc/fail2ban/filter.d/hl-nginx-abuse.conf
# Verrou commun au déploiement et au retour arrière : une seule intervention à la fois.
exec 9>/run/lock/hl-intervention.lock; flock -n 9 || { echo "une autre intervention est déjà en cours — arrêt, rien n'est modifié"; exit 1; }
exec > >(tee -a "/root/deploiement-$DATE.log") 2>&1
q() { mariadb -N -B "$DB" -e "$1"; }
g() { sudo -u deploy -H git -C "$APP" "$@"; }
art() { sudo -u deploy -H env HOME=/tmp/deploy-home php "$APP/artisan" "$@"; }
url() { curl -s -o /dev/null -w '%{http_code}' -m 20 "$SITE$1" || true; }
# Tables techniques exclues des comparaisons de contenu (elles varient d'elles-mêmes) ; toutes les autres doivent rester identiques.
TECH="'cache','cache_locks','sessions','jobs','job_batches','failed_jobs','migrations','support_requests','support_messages'"
# Nombre exact de lignes de chaque table d'une base.
compte() { local t n; for t in $(mariadb -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$1' AND table_type='BASE TABLE' ORDER BY table_name"); do n=$(mariadb -N -B "$1" -e "SELECT COUNT(*) FROM \`$t\`"); echo "$t=$n"; done; }
# Liste « table <tabulation> colonnes » des tables de données d'une base.
colonnes() { mariadb -N -B -e "SET SESSION group_concat_max_len=1000000; SELECT table_name, GROUP_CONCAT(CONCAT('\`',column_name,'\`') ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema='$1' AND table_name NOT IN ($TECH) AND table_name IN (SELECT table_name FROM information_schema.tables WHERE table_schema='$1' AND table_type='BASE TABLE') GROUP BY table_name ORDER BY table_name"; }
# Empreinte SHA-256 du CONTENU de chaque table (toutes les lignes, colonnes listées dans le fichier $2) : une seule valeur modifiée change l'empreinte.
empreintes() { local t c h; while IFS=$'\t' read -r t c; do h=$(mariadb -N -B --default-character-set=utf8mb4 "$1" -e "SELECT $c FROM \`$t\` ORDER BY $c" | sha256sum | cut -d' ' -f1); echo "$t=$h"; done < "$2"; }
fiches() { q "SELECT CONCAT(id,':',status,':',listing_type,':',instant_booking) FROM spas ORDER BY id"; }
# Commissions, facturation, paiements : relevé en base. Tout doit être à 0.
argent() { q "SELECT CONCAT('fiches_non_referencees=',(SELECT COUNT(*) FROM spas WHERE listing_type<>'referenced'),' reservations_avec_commission=',(SELECT COUNT(*) FROM bookings WHERE commission_pct<>0 OR commission_amount<>0),' ecritures_comptables_non_nulles=',(SELECT COUNT(*) FROM ledger_entries WHERE amount<>0),' factures=',(SELECT COUNT(*) FROM invoices),' paiements_enregistres=',(SELECT COUNT(*) FROM invoice_payments),' facturation_activee=',(SELECT COUNT(*) FROM billing_settings WHERE \`key\`='enabled' AND value IN ('1','true')),' periode_de_facturation=',(SELECT COUNT(*) FROM billing_settings WHERE \`key\`='start_period' AND value IS NOT NULL AND value<>''))"; }
ARGENT_OK="fiches_non_referencees=0 reservations_avec_commission=0 ecritures_comptables_non_nulles=0 factures=0 paiements_enregistres=0 facturation_activee=0 periode_de_facturation=0"
# Même contrôle vu par l'application elle-même (règle de commission des six fiches et réglage effectif de la facturation).
APPLI_OK="APPLI facturation=inactive periode=aucune fiches_commissionnees=0 fiches=6"
appli() { art tinker --execute="\$s=app(App\\Domain\\Billing\\BillingSettings::class); echo 'APPLI facturation='.(\$s->enabled()?'ACTIVE':'inactive').' periode='.(\$s->startPeriod()?'definie':'aucune').' fiches_commissionnees='.App\\Models\\Spa::all()->reject->isReferenced()->count().' fiches='.App\\Models\\Spa::count();" | grep -o 'APPLI .*' || true; }
controle_argent() { # $1 = moment
    local a b; a=$(argent); b=$(appli); echo "commissions / facturation / paiements ($1) : $a ; $b"
    [ "$a" = "$ARGENT_OK" ] || { echo "commission, facture, paiement ou facturation non nuls en base — arrêt"; exit 1; }
    [ "$b" = "$APPLI_OK" ] || { echo "l'application ne confirme pas facturation inactive et commission nulle des six fiches — arrêt"; exit 1; }
    if grep -E '^HL_BILLING_ENABLED=("?)(true|1)' "$APP/.env" >/dev/null; then echo "facturation activée dans .env — arrêt"; exit 1; fi
    if grep -E '^HL_BILLING_(START_PERIOD|ACCEPTANCE_REF|OWNER_AUTHORIZATION_REF)=' "$APP/.env" | grep -vqE '=("")?$'; then echo "verrou de facturation renseigné dans .env — arrêt"; exit 1; fi
}
# Aucun traitement ne doit pouvoir écrire dans la base pendant les contrôles d'intégrité : site en maintenance (503, ce qui suspend aussi les
# tâches planifiées de l'application), file d'envoi arrêtée, aucune commande de l'application encore en cours.
gel() {
    local i
    [ "$(url /fr)" = 503 ] || { echo "le site n'est pas en maintenance — arrêt"; return 1; }
    [ -f "$APP/storage/framework/down" ] || { echo "marqueur de maintenance absent : les tâches planifiées ne sont pas suspendues — arrêt"; return 1; }
    supervisorctl status hl-queue | grep -qE 'STOPPED|EXITED' || { echo "la file d'envoi n'est pas arrêtée — arrêt"; return 1; }
    for i in $(seq 1 30); do pgrep -u deploy -f artisan >/dev/null || return 0; sleep 2; done
    echo "une commande de l'application est encore en cours (tâche planifiée) — arrêt"; return 1
}
# Base temporaire de contrôle : nom daté, jamais celui de la base en service ; elle n'est supprimée que si ce script l'a créée.
CHK_CREE=0
nom_chk_sur() { [[ "$CHK" =~ ^hl_controle_[0-9]{14}$ ]] && [ "$CHK" != "$DB" ]; }
supprime_chk() { if [ "$CHK_CREE" = 1 ] && nom_chk_sur; then mariadb -e "DROP DATABASE IF EXISTS \`$CHK\`"; CHK_CREE=0; fi; }
install -d -o deploy -g deploy -m 700 /tmp/deploy-home
MODIFIE=0
trap 'c=$?; supprime_chk || echo "ATTENTION : base temporaire $CHK à supprimer à la main"; if [ $c -ne 0 ]; then if [ $MODIFIE = 1 ]; then echo "ÉCHEC APRÈS MODIFICATION — le site reste en maintenance. Lancer : retour-arriere-code.sh $PRE CONFIRMER"; else echo "ARRÊT AVANT TOUTE MODIFICATION — production inchangée"; fi; fi' EXIT

rouvrir() {
    echo "== 7 réouverture et contrôles publics"
    supervisorctl start hl-queue
    supervisorctl status hl-queue | grep -q RUNNING || { echo "file d'envoi arrêtée — arrêt"; exit 1; }
    art up; rm -f "$ATTENTE"
    OK=1; for u in /fr /fr/recherche /admin/login /partenaire/login /up; do c=$(url "$u"); echo "$u → $c"; [ "$c" = 200 ] || OK=0; done
    c=$(url /fr/spa/mythic-oriental-spa); echo "/fr/spa/mythic-oriental-spa → $c (404 attendu : fiche en brouillon)"; [ "$c" = 404 ] || OK=0
    curl -sI -m 20 "$SITE/fr" | grep -qi '^x-robots-tag:.*noindex' && echo "site non indexable" || { echo "en-tête noindex absent"; OK=0; }
    if [ $OK = 1 ]; then echo "DEPLOIEMENT d155c5a OK — retour arrière possible avec : retour-arriere-code.sh $PRE CONFIRMER"; else art down --retry=60; echo "contrôle public en échec — site remis en maintenance"; exit 1; fi
}

if [ "$ETAPE" = ROUVRIR ]; then
    # Étape 2 : uniquement après l'étape 1 réussie (marqueur) ; rien n'est réinstallé, les données sont recontrôlées avant la réouverture.
    [ -s "$ATTENTE" ] || { echo "aucun déploiement en attente de réouverture — arrêt, rien n'est modifié"; exit 1; }
    PRE=$(sed -n 's/^PRE=//p' "$ATTENTE"); ENVSUM=$(sed -n 's/^ENVSUM=//p' "$ATTENTE")
    [[ "$PRE" =~ ^/root/pre-deploiement-[0-9]{8}-[0-9]{6}$ ]] && [ -s "$PRE/contenu-apres.txt" ] && [ -s "$PRE/colonnes-avant.txt" ] && [ -s "$PRE/fiches-avant.txt" ] || { echo "marqueur d'attente invalide — arrêt"; exit 1; }
    MODIFIE=1
    echo "== 6 bis contrôles avant réouverture (étape 2) — dossier $PRE"
    [ "$(g rev-parse HEAD)" = "$PIN" ] && [ "$(g rev-parse 'HEAD^{tree}')" = "$TREE" ] && [ -z "$(g status --porcelain)" ] || { echo "le code installé n'est pas exactement $PIN — arrêt"; exit 1; }
    gel
    cmp -s "$PRE/contenu-apres.txt" <(empreintes "$DB" "$PRE/colonnes-avant.txt") || { echo "le contenu des données a changé pendant l'attente — arrêt"; exit 1; }
    cmp -s "$PRE/fiches-avant.txt" <(fiches) || { echo "l'état des fiches a changé — arrêt"; exit 1; }
    [ "$(q "SELECT COUNT(*) FROM spas WHERE status<>'draft'")" = 0 ] || { echo "une fiche n'est plus en brouillon — arrêt"; exit 1; }
    controle_argent "avant réouverture"
    [ "$(sha256sum < "$APP/.env")" = "$ENVSUM" ] || { echo ".env modifié pendant l'attente — arrêt"; exit 1; }
    gel
    echo "données, six fiches en brouillon, commissions, facturation, .env : identiques à la fin de l'étape 1"
    rouvrir; exit 0
fi
[ ! -e "$ATTENTE" ] || { echo "un déploiement attend déjà sa réouverture (ROUVRIR) ou son retour arrière — arrêt, rien n'est modifié"; exit 1; }

echo "== 1 contrôles préalables (lecture seule)"
nom_chk_sur || { echo "nom de la base de contrôle invalide — arrêt"; exit 1; }
[ "$(mariadb -N -B -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$CHK' OR schema_name LIKE 'hl\_controle\_%'")" = 0 ] || { echo "une base de contrôle existe déjà (intervention précédente inachevée) — arrêt"; exit 1; }
OLD=$(g rev-parse HEAD); echo "version installée : $OLD"
[ "$OLD" = "$FROM" ] || { echo "version installée inattendue (attendue $FROM) — arrêt"; exit 1; }
[ -z "$(g status --porcelain --untracked-files=no)" ] || { echo "fichiers suivis modifiés dans $APP — arrêt"; exit 1; }
[ "$(g rev-parse --abbrev-ref HEAD)" = main ] || { echo "le dépôt n'est pas sur la branche main — arrêt"; exit 1; }
[ "$(q "SELECT GROUP_CONCAT(id ORDER BY id) FROM spas")" = "$FICHES" ] || { echo "les fiches ne sont pas exactement $FICHES — arrêt"; exit 1; }
[ "$(q "SELECT COUNT(*) FROM spas WHERE status<>'draft' OR listing_type<>'referenced' OR instant_booking<>0")" = 0 ] || { echo "une fiche n'est pas brouillon / référencée / sans réservation immédiate — arrêt"; exit 1; }
controle_argent "avant"
MS=$(art migrate:status); [ "$(grep -c Pending <<<"$MS" || true)" = 0 ] || { echo "migrations en attente avant déploiement — arrêt"; exit 1; }
[ "$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_name IN ('support_requests','support_messages')")" = 0 ] || { echo "les tables de la nouvelle version existent déjà (déploiement déjà tenté) — arrêt"; exit 1; }
[ "$(git ls-remote "$REPO" refs/heads/main | cut -f1)" = "$PIN" ] || { echo "la branche main de GitHub n'est plus à la version $PIN — arrêt"; exit 1; }
g fetch -q "$REPO" main; g cat-file -e "$PIN^{commit}"
[ "$(g rev-parse "$PIN^{tree}")" = "$TREE" ] || { echo "le contenu téléchargé n'a pas l'empreinte validée $TREE — arrêt"; exit 1; }
g merge-base --is-ancestor "$OLD" "$PIN" || { echo "la version figée ne prolonge pas la version installée — arrêt"; exit 1; }
[ "$(url /fr)" = 200 ] || { echo "le site ne répond pas 200 avant l'intervention — arrêt"; exit 1; }
echo "GitHub main = $PIN, empreinte $TREE conforme (rien n'est encore modifié)"

echo "== 2 sauvegarde externe (site encore ouvert)"
/usr/local/sbin/hl-backup.sh

echo "== 3 maintenance, copie locale de la base, rechargement de contrôle dans une base temporaire"
install -d -m 700 "$PRE"; echo "$OLD" > "$PRE/commit"; cp -a "$APP/.env" "$PRE/env"; [ -f "$F2B" ] && cp -a "$F2B" "$PRE/filter-hl-nginx-abuse.conf"
ENVSUM=$(sha256sum < "$APP/.env")
MODIFIE=1; art down --retry=60
[ "$(url /fr)" = 503 ] || { echo "le mode maintenance n'est pas effectif — arrêt"; exit 1; }
supervisorctl stop hl-queue || true
gel
mariadb-dump --single-transaction --routines --triggers "$DB" | gzip > "$PRE/db.sql.gz"; gunzip -t "$PRE/db.sql.gz"
compte "$DB" > "$PRE/comptes-avant.txt"; fiches > "$PRE/fiches-avant.txt"; colonnes "$DB" > "$PRE/colonnes-avant.txt"
empreintes "$DB" "$PRE/colonnes-avant.txt" > "$PRE/contenu-avant.txt"
q "SELECT migration FROM migrations ORDER BY id" > "$PRE/migrations-avant.txt"
mariadb -e "CREATE DATABASE \`$CHK\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"; CHK_CREE=1
gunzip -c "$PRE/db.sql.gz" | mariadb "$CHK"
compte "$CHK" > "$PRE/comptes-copie.txt"; empreintes "$CHK" "$PRE/colonnes-avant.txt" > "$PRE/contenu-copie.txt"
supprime_chk
[ "$(mariadb -N -B -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$CHK'")" = 0 ] || { echo "la base temporaire n'a pas été supprimée — arrêt"; exit 1; }
cmp -s "$PRE/comptes-avant.txt" "$PRE/comptes-copie.txt" || { echo "la copie rechargée n'a pas le même nombre de lignes que la base en service — arrêt (code non modifié)"; diff "$PRE/comptes-avant.txt" "$PRE/comptes-copie.txt" || true; exit 1; }
cmp -s "$PRE/contenu-avant.txt" "$PRE/contenu-copie.txt" || { echo "la copie rechargée n'a pas le même contenu que la base en service — arrêt (code non modifié)"; diff "$PRE/contenu-avant.txt" "$PRE/contenu-copie.txt" || true; exit 1; }
gel
cmp -s "$PRE/contenu-avant.txt" <(empreintes "$DB" "$PRE/colonnes-avant.txt") || { echo "le contenu de la base en service a changé pendant le contrôle — arrêt (code non modifié)"; exit 1; }
cmp -s "$PRE/comptes-avant.txt" <(compte "$DB") || { echo "la base en service a changé pendant le contrôle — arrêt (code non modifié)"; exit 1; }
echo "copie de la base rechargeable à l'identique : $(wc -l < "$PRE/comptes-avant.txt") tables comptées, contenu de $(wc -l < "$PRE/contenu-avant.txt") tables de données comparé ; base temporaire supprimée"
grep -E '^(users|spas|bookings|booking_notifications|migrations)=' "$PRE/comptes-avant.txt" | tr '\n' ' '; echo

echo "== 4 code $PIN"; art optimize:clear -q || true
[ "$(url /fr)" = 503 ] || { echo "le site n'est plus en maintenance — arrêt"; art down --retry=60 || true; exit 1; }
g merge -q --ff-only "$PIN"; [ "$(g rev-parse HEAD)" = "$PIN" ]; g --no-pager log --oneline -1
MS=$(art migrate:status); ATT=$(grep Pending <<<"$MS" | awk '{print $1}' | sort | tr '\n' ' ')
[ "$ATT" = "2026_11_18_000001_create_support_requests 2026_11_19_000001_last_minute_requests " ] || { echo "migrations à appliquer inattendues : $ATT— arrêt (base non modifiée)"; exit 1; }

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
systemctl reload php8.3-fpm

echo "== 6 contrôles avant réouverture (site en maintenance, file d'envoi arrêtée, tâches planifiées suspendues)"
gel
MS=$(art migrate:status); P=$(grep -c Pending <<<"$MS" || true); echo "migrations en attente : $P"; [ "$P" = 0 ]
cmp -s "$PRE/migrations-avant.txt" <(q "SELECT migration FROM migrations ORDER BY id" | head -n "$(wc -l < "$PRE/migrations-avant.txt")") || { echo "l'historique des migrations a été modifié — arrêt"; exit 1; }
[ "$(q 'SELECT COUNT(*) FROM migrations')" = "$(( $(wc -l < "$PRE/migrations-avant.txt") + 2 ))" ] || { echo "nombre de migrations inattendu — arrêt"; exit 1; }
compte "$DB" > "$PRE/comptes-apres.txt"; fiches > "$PRE/fiches-apres.txt"; colonnes "$DB" > "$PRE/colonnes-apres.txt"
empreintes "$DB" "$PRE/colonnes-avant.txt" > "$PRE/contenu-apres.txt"
TECHRE="^($(tr -d "'" <<<"$TECH" | tr ',' '|'))="
diff <(grep -vE "$TECHRE" "$PRE/comptes-avant.txt") <(grep -vE "$TECHRE" "$PRE/comptes-apres.txt") || { echo "le nombre de lignes d'une table de données a changé — arrêt"; exit 1; }
diff "$PRE/contenu-avant.txt" "$PRE/contenu-apres.txt" || { echo "le CONTENU d'une table de données a changé — arrêt"; exit 1; }
NOUV=$(diff <(tr '\t,' '\n\n' < "$PRE/colonnes-avant.txt") <(tr '\t,' '\n\n' < "$PRE/colonnes-apres.txt") | grep '^[<>]' | tr -d ' `' | tr '\n' ' ' || true)
[ "$NOUV" = ">last_minute >last_minute_enabled >last_minute_allowed " ] || { echo "structure des tables de données inattendue (attendu : trois colonnes ajoutées) : $NOUV— arrêt"; exit 1; }
cmp -s "$PRE/fiches-avant.txt" "$PRE/fiches-apres.txt" || { echo "l'état des fiches a changé — arrêt"; exit 1; }
[ "$(q "SELECT COUNT(*) FROM spas WHERE status<>'draft'")" = 0 ] || { echo "une fiche n'est plus en brouillon — arrêt"; exit 1; }
[ "$(q "SELECT COUNT(*) FROM spas WHERE last_minute_enabled<>0")" = 0 ] || { echo "option dernière minute activée sur une fiche — arrêt"; exit 1; }
[ "$(q "SELECT COUNT(*) FROM bookings WHERE last_minute<>0")" = 0 ] || { echo "réservation marquée dernière minute — arrêt"; exit 1; }
controle_argent "après"
[ "$(sha256sum < "$APP/.env")" = "$ENVSUM" ] || { echo ".env modifié pendant le déploiement — arrêt"; exit 1; }
art schedule:list | grep -q 'hl:expire-waiting' || { echo "tâche d'expiration absente du planificateur — arrêt"; exit 1; }
grep -qs "schedule:run" /etc/cron.d/hl-schedule || crontab -u deploy -l 2>/dev/null | grep -q "schedule:run" || { echo "planificateur système absent — arrêt"; exit 1; }
gel
cmp -s "$PRE/contenu-apres.txt" <(empreintes "$DB" "$PRE/colonnes-avant.txt") || { echo "le contenu des données a changé pendant les contrôles — arrêt"; exit 1; }
# E-mail de contrôle : un seul message, vers l'adresse de test de la propriétaire, remis directement au serveur d'envoi (aucun client destinataire).
art tinker --execute="Illuminate\\Support\\Facades\\Mail::raw('Message de contrôle envoyé par le déploiement de HammamLuxury avant la réouverture du site. Aucune action attendue.', fn (\$m) => \$m->to('$MAILTEST')->subject('HammamLuxury - contrôle d\\'envoi avant réouverture')); echo 'ENVOI_ACCEPTE';" | grep -q ENVOI_ACCEPTE || { echo "le serveur d'envoi n'a pas accepté l'e-mail de contrôle — arrêt"; exit 1; }
echo "e-mail de contrôle accepté par le serveur d'envoi (destinataire : $MAILTEST)"
echo "contenu des données, six fiches en brouillon, commissions, facturation, .env, planificateur, e-mail accepté : conformes"

gel
printf 'PRE=%s\nENVSUM=%s\n' "$PRE" "$ENVSUM" > "$ATTENTE"; chmod 600 "$ATTENTE"
echo "ÉTAPE 1 TERMINÉE — le site RESTE EN MAINTENANCE, file d'envoi arrêtée."
echo "Attendre la confirmation écrite de réception de l'e-mail dans Gmail ($MAILTEST), puis : deploiement-d155c5a.sh ROUVRIR"
echo "Pour renoncer : retour-arriere-code.sh $PRE CONFIRMER"
