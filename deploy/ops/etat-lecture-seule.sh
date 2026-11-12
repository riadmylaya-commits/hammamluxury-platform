#!/usr/bin/env bash
# Relevé LECTURE SEULE de l'état du serveur (aucune écriture). Exécutable via sudo par l'utilisateur d'intervention.
set -uo pipefail
echo "hôte=$(hostname) date=$(date -Is)"; uptime
df -h / | tail -1; free -m | sed -n 2p
[ -f /var/run/reboot-required ] && echo "REDÉMARRAGE NOYAU EN ATTENTE" || echo "pas de redémarrage en attente"
for s in nginx php8.3-fpm mariadb redis-server supervisor fail2ban; do printf '%s=%s ' "$s" "$(systemctl is-active "$s" 2>/dev/null)"; done; echo
git -c safe.directory=/var/www/hammamluxury -C /var/www/hammamluxury log --oneline -1 2>/dev/null || echo "git: non lisible"
mysql -N hl_platform -e "SELECT CONCAT('spas=',(SELECT COUNT(*) FROM spas),' bookings=',(SELECT COUNT(*) FROM bookings),' users=',(SELECT COUNT(*) FROM users),' migrations=',(SELECT COUNT(*) FROM migrations))" 2>/dev/null || echo "mysql: non lisible"
ls -d /root/backups* /root/pre-* /etc/hl-backup 2>/dev/null; which restic >/dev/null && restic version | head -1
curl -s -o /dev/null -w 'prod.hammamluxury.com %{http_code}\n' https://prod.hammamluxury.com/
