#!/usr/bin/env bash
# LECTURE SEULE — relevé de l'état du serveur de production. Aucune commande d'écriture : pas de fichier créé ou modifié,
# pas d'INSERT/UPDATE/DELETE, pas de redémarrage, pas d'installation. Seules des lectures (uptime, df, free, systemctl is-active,
# git log, SELECT COUNT, ls, curl HEAD vers le site) sont exécutées.
set -uo pipefail
echo "hôte=$(hostname) date=$(date -Is)"
uptime
df -h / | tail -1
free -m | sed -n 2p
if [ -f /var/run/reboot-required ]; then echo "REDÉMARRAGE NOYAU EN ATTENTE"; else echo "pas de redémarrage en attente"; fi
for s in nginx php8.3-fpm mariadb redis-server supervisor fail2ban; do printf '%s=%s ' "$s" "$(systemctl is-active "$s" 2>/dev/null)"; done; echo
git --no-pager -c safe.directory=/var/www/hammamluxury -C /var/www/hammamluxury log --oneline -1 2>/dev/null || echo "git: non lisible"
mysql -N hl_platform -e "SELECT CONCAT('spas=',(SELECT COUNT(*) FROM spas),' bookings=',(SELECT COUNT(*) FROM bookings),' users=',(SELECT COUNT(*) FROM users),' migrations=',(SELECT COUNT(*) FROM migrations))" 2>/dev/null || echo "mysql: non lisible"
ls -d /root/backups* /root/pre-* /etc/hl-backup 2>/dev/null
command -v restic >/dev/null && restic version | head -1
curl -sI -o /dev/null -w 'prod.hammamluxury.com %{http_code}\n' https://prod.hammamluxury.com/
exit 0
