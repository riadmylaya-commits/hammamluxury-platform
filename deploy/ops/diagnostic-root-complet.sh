#!/usr/bin/env bash
# DIAGNOSTIC ROOT COMPLET — lecture seule, aucun secret affiché (noms de variables et réglages non sensibles seulement).
set -uo pipefail
APP=/var/www/hammamluxury
echo "== pare-feu"; ufw status verbose 2>&1 | head -25
echo "== fail2ban"; fail2ban-client status 2>&1 | tail -2
echo "== accès root"; passwd -S root | awk '{print "mot de passe root : "$2}'; awk '{print "clé autorisée : "$NF}' /root/.ssh/authorized_keys 2>/dev/null; ls /etc/sudoers.d
echo "== /root"; ls -la /root | awk '{print $1,$5,$9}' | tail -n +4
echo "== sauvegarde"; ls -la /etc/hl-backup 2>&1 | awk '{print $1,$5,$9}'; grep -E '^(RESTIC_REPOSITORY|HL_HOST_TAG)=' /etc/hl-backup/env 2>&1
cat /var/lib/hl-backup/last.json 2>&1; echo; tail -5 /var/log/hl-backup.log 2>&1; grep -c . /root/.ssh/config 2>/dev/null
echo "== cron hl"; for f in /etc/cron.d/hl-* /etc/cron.daily/hl-mysqldump; do echo "-- $f"; grep -vE '^\s*(#|$)' "$f" | grep -viE 'pass|secret|token|key' | head -12; done
echo "== monitor"; tail -5 /var/log/hl-monitor.log 2>&1; ls /usr/local/sbin
echo "== application"; git --no-pager -c safe.directory=$APP -C $APP status -sb 2>&1 | head -5
echo "variables .env (noms) :"; grep -oE '^[A-Z0-9_]+=' $APP/.env | tr -d '=' | tr '\n' ' '; echo
grep -E '^(APP_ENV|APP_DEBUG|APP_URL|QUEUE_CONNECTION|CACHE_STORE|SESSION_DRIVER|MAIL_MAILER|MAIL_HOST|MAIL_FROM_ADDRESS|HL_NOINDEX|HL_BILLING_ENABLED)=' $APP/.env
echo "mot de passe de démonstration : $(grep -q "^HL_DEMO_PASSWORD=." $APP/.env && echo défini || echo absent) (valeur jamais affichée)"
echo "== base"; mariadb -N -e "SELECT CONCAT(user,'@',host) FROM mysql.user; SELECT table_schema, COUNT(*) FROM information_schema.tables WHERE table_schema NOT IN ('mysql','sys','information_schema','performance_schema') GROUP BY 1" 2>&1
echo "== services"; supervisorctl status 2>&1; timedatectl | grep -E 'Time zone|synchronized'; swapon --show | tail -1; nginx -t 2>&1 | tail -1
echo "== mises à jour"; cat /var/run/reboot-required.pkgs 2>/dev/null | sort -u | tr '\n' ' '; echo; tail -3 /var/log/unattended-upgrades/unattended-upgrades.log 2>&1
exit 0
