#!/usr/bin/env bash
# DIAGNOSTIC SANS PRIVILÈGES — s'exécute avec le compte d'intervention (hl-devin), sans sudo. Lecture seule : aucun fichier créé
# ou modifié, aucune écriture en base, aucun redémarrage. N'affiche aucun secret (pas de lecture de .env ni de configuration).
set -uo pipefail
echo "hôte=$(hostname) date=$(date -Is) utilisateur=$(id -un)"
uptime
df -h / | tail -1
free -m | sed -n 2p
if [ -e /var/run/reboot-required ]; then echo "REDÉMARRAGE NOYAU EN ATTENTE"; else echo "pas de redémarrage en attente"; fi
for s in nginx php8.3-fpm mariadb redis-server supervisor fail2ban; do printf '%s=%s ' "$s" "$(systemctl is-active "$s" 2>/dev/null)"; done; echo
curl -sI -o /dev/null -w 'prod.hammamluxury.com HTTP %{http_code}\n' https://prod.hammamluxury.com/
exit 0
