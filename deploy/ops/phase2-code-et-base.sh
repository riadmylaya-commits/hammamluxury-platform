#!/usr/bin/env bash
# PHASE 2 (production, root) — redémarrage (noyau en attente), code main, migrations sur base VIDE, .env complété, file et planificateur. Coupure < 2 min. Pré-requis : phase 1 réussie, sauvegarde A1 bis.
set -euo pipefail
[ -f /root/ACCORD-PHASE-2 ] || { echo "Pas d'accord écrit — arrêt."; exit 1; }
: "${HL_CONTACT_EMAIL:?adresse de réception des demandes (ex. hammamluxury26@gmail.com)}"; : "${HL_ALERT_EMAIL:?adresse des alertes}"
APP=/var/www/hammamluxury; DATE=$(date +%Y%m%d-%H%M%S); exec > >(tee -a /root/phase-2-$DATE.log) 2>&1
echo "== B1 contrôle : base vide attendue"; mysql -N hl_platform -e "SELECT COUNT(*) FROM spas; SELECT COUNT(*) FROM bookings" | tr '\n' ' '; echo
echo "== B2 sauvegarde A1 bis"; /usr/local/sbin/hl-backup.sh; install -d -m700 /root/pre-phase-2-$DATE; mysqldump --single-transaction hl_platform | gzip > /root/pre-phase-2-$DATE/db.sql.gz; cp -a $APP/.env /root/pre-phase-2-$DATE/env
if [ -f /var/run/reboot-required ] && [ -z "${HL_SKIP_REBOOT:-}" ]; then echo "== B3 redémarrage noyau (relancer ce script avec HL_SKIP_REBOOT=1 après)"; systemctl reboot; exit 0; fi
echo "== B4 code main"; sudo -u deploy -H git -C $APP fetch -q origin main && sudo -u deploy -H git -C $APP checkout -q -f origin/main && sudo -u deploy -H git -C $APP log --oneline -1
echo "== B5 .env (ajouts seulement, jamais HL_NOINDEX ni HL_BILLING_*)"
for kv in "HL_CONTACT_EMAIL=$HL_CONTACT_EMAIL" "HL_ALERT_EMAIL=$HL_ALERT_EMAIL" "HL_FEATURE_PROMOTIONS=false"; do k=${kv%%=*}; grep -q "^$k=" $APP/.env || echo "$kv" >> $APP/.env; done
grep -q "^HL_NOINDEX=true" $APP/.env && { echo "HL_NOINDEX=true présent en production — arrêt"; exit 1; }
echo "== B6 release (composer, npm, migrate --force, caches, filament, php-fpm, file)"; bash $APP/deploy/release.sh
echo "== B7 planificateur"; grep -q "schedule:run" /etc/cron.d/hl-schedule 2>/dev/null || echo "* * * * * deploy cd $APP && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/hl-schedule
echo "== B8 contrôles"; curl -s -o /dev/null -w "prod.hammamluxury.com → %{http_code}\n" https://prod.hammamluxury.com/fr; sudo -u deploy -H env HOME=/tmp/deploy-home php $APP/artisan migrate:status | grep -c Pending || true; supervisorctl status hl-queue
echo "== B9 e-mail de test"; sudo -u deploy -H env HOME=/tmp/deploy-home php $APP/artisan hl:alert "Phase 2 production : e-mail de test" || echo "hl:alert indisponible — vérifier MAIL_*"
