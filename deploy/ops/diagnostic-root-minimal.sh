#!/usr/bin/env bash
# DIAGNOSTIC ROOT MINIMAL — seules les quatre lectures qui exigent root : version du code déployé, nombre de lignes de 4 tables,
# présence (pas le contenu) des dossiers de sauvegarde, version de restic. Lecture seule, aucun secret affiché :
# pas de lecture de .env, aucun identifiant, aucun mot de passe, aucune valeur de configuration.
set -uo pipefail
echo "diagnostic root date=$(date -Is)"
git --no-pager -c safe.directory=/var/www/hammamluxury -C /var/www/hammamluxury log --oneline -1 2>/dev/null || echo "git: non lisible"
mysql -N hl_platform -e "SELECT CONCAT('spas=',(SELECT COUNT(*) FROM spas),' bookings=',(SELECT COUNT(*) FROM bookings),' users=',(SELECT COUNT(*) FROM users),' migrations=',(SELECT COUNT(*) FROM migrations))" 2>/dev/null || echo "mysql: non lisible"
for d in /root/backups /root/pre-phase-a-20261030 /etc/hl-backup; do [ -e "$d" ] && echo "présent : $d" || echo "absent : $d"; done
command -v restic >/dev/null && restic version | head -1 || echo "restic: non installé"
exit 0
