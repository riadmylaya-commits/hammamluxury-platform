#!/usr/bin/env bash
# DÉPLOIEMENT DU CODE (production, root) — passe l'application à la version figée ci-dessous, sur une base SANS fiche ni réservation.
# Sauvegardes avant toute modification ; aucun redémarrage du serveur ; site non indexable ; facturation non activée.
# Retour arrière : retour-arriere.sh <dossier /root/pre-deploiement-…>
set -euo pipefail
PIN=1afc6f6bfe99e0940ee5fe4352b2d4cef7c86fcc
APP=/var/www/hammamluxury; DB=hl_platform; DATE=$(date +%Y%m%d-%H%M%S); PRE=/root/pre-deploiement-$DATE
exec > >(tee -a "/root/deploiement-$DATE.log") 2>&1
q() { mariadb -N -B "$DB" -e "$1"; }
g() { sudo -u deploy -H git -C "$APP" "$@"; }
art() { sudo -u deploy -H env HOME=/tmp/deploy-home php "$APP/artisan" "$@"; }
echo "== 1 contrôles : base sans fiche ni réservation, dépôt propre"
[ "$(q 'SELECT COUNT(*) FROM spas')" = 0 ] && [ "$(q 'SELECT COUNT(*) FROM bookings')" = 0 ] || { echo "la base contient déjà des fiches ou des réservations — arrêt"; exit 1; }
[ -z "$(g status --porcelain --untracked-files=no)" ] || { echo "fichiers suivis modifiés dans $APP — arrêt"; exit 1; }
OLD=$(g rev-parse HEAD); echo "version actuelle : $OLD"
echo "== 2 sauvegardes"; install -d -m 700 "$PRE"; echo "$OLD" > "$PRE/commit"
mariadb-dump --single-transaction --routines --triggers "$DB" | gzip > "$PRE/db.sql.gz"; cp -a "$APP/.env" "$PRE/env"; [ -s "$PRE/db.sql.gz" ] && [ -s "$PRE/env" ]
/usr/local/sbin/hl-backup.sh
echo "== 3 code figé $PIN"; g fetch -q origin; g checkout -q --detach "$PIN"; [ "$(g rev-parse HEAD)" = "$PIN" ]; g --no-pager log --oneline -1
echo "== 4 réglages (.env) — valeurs non secrètes, sauf le mot de passe de démonstration remplacé sans être affiché"
setenv() { local k="$1" v="$2" line; line="$k=$v"; if grep -q "^$k=" "$APP/.env"; then K="$k" L="$line" perl -i -pe 'BEGIN{$k=$ENV{K};$l=$ENV{L}} $_="$l\n" if /^\Q$k\E=/' "$APP/.env"; else printf '%s\n' "$line" >> "$APP/.env"; fi; }
setenv HL_CONTACT_EMAIL hammamluxury26@gmail.com; setenv HL_ALERT_EMAIL hammamluxury26@gmail.com
setenv HL_FEATURE_PROMOTIONS false; setenv HL_NOINDEX true
setenv HL_LEGAL_COMPANY_NAME '"JAUNE ET VERT"'; setenv HL_LEGAL_FORM '"SARL à associé unique"'; setenv HL_LEGAL_CAPITAL '"10 000"'
setenv HL_LEGAL_RC_CITY '"Marrakech"'; setenv HL_LEGAL_RC_NUMBER '"96347"'; setenv HL_LEGAL_ICE '"002256350000079"'; setenv HL_LEGAL_TAX_ID '"3750725"'
setenv HL_LEGAL_PATENTE '"45600537"'; setenv HL_LEGAL_ADDRESS '"117 Derb Jdid, Bab Doukkala, Marrakech"'; setenv HL_LEGAL_EMAIL '"hammamluxury26@gmail.com"'
setenv HL_LEGAL_COURT_CITY '"Marrakech"'; setenv HL_LEGAL_RETENTION_YEARS 5
setenv HL_DEMO_PASSWORD "$(openssl rand -hex 16)"
if grep -E '^HL_BILLING_ENABLED=(true|1)' "$APP/.env" >/dev/null; then echo "facturation activée dans .env — arrêt"; exit 1; fi
chown deploy:deploy "$APP/.env"; chmod 640 "$APP/.env"
echo "== 5 mise à jour (dépendances, compilation, migrations, caches)"; bash "$APP/deploy/release.sh"
echo "== 6 planificateur"
if ! crontab -u deploy -l 2>/dev/null | grep -q "schedule:run" && ! grep -qs "schedule:run" /etc/cron.d/hl-schedule; then
  printf 'SHELL=/bin/bash\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n* * * * * deploy cd %s && php artisan schedule:run >> /dev/null 2>&1\n' "$APP" > /etc/cron.d/hl-schedule; chmod 644 /etc/cron.d/hl-schedule; echo "planificateur ajouté"
else echo "planificateur déjà présent"; fi
echo "== 7 contrôles"
P=$(art migrate:status | grep -c Pending || true); echo "migrations en attente : $P"; [ "$P" = 0 ]
echo "comptes=$(q 'SELECT COUNT(*) FROM users') fiches=$(q 'SELECT COUNT(*) FROM spas') migrations=$(q 'SELECT COUNT(*) FROM migrations')"
C=$(curl -s -o /dev/null -w '%{http_code}' -m 20 https://prod.hammamluxury.com/fr || true); echo "prod.hammamluxury.com/fr → $C"
curl -sI -m 20 https://prod.hammamluxury.com/fr | grep -i '^x-robots-tag' || echo "ATTENTION : en-tête noindex absent"
supervisorctl status hl-queue || true
[ "$C" = 200 ] && echo "DEPLOIEMENT CODE OK — retour arrière possible avec $PRE" || { echo "le site ne répond pas 200 — lancer retour-arriere.sh $PRE"; exit 1; }
