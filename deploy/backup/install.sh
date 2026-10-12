#!/usr/bin/env bash
# Installe les sauvegardes sur le serveur (root). Idempotent.
# Variables : HL_RESTIC_REPOSITORY (obligatoire, ex. sftp:hl-storagebox:hl-staging via alias ssh, ou s3:...),
#             HL_BACKUP_HOUR (défaut 03), HL_VERIFY_HOUR (défaut 07), et éventuellement AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY, B2_*.
set -euo pipefail
: "${HL_RESTIC_REPOSITORY:?HL_RESTIC_REPOSITORY requis}"
SRC="$(cd "$(dirname "$0")" && pwd)"
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq restic >/dev/null
install -d -m 700 /etc/hl-backup /var/lib/hl-backup
install -m 700 "$SRC"/hl-backup.sh "$SRC"/hl-backup-verify.sh "$SRC"/hl-alert.sh "$SRC"/hl-restore.sh /usr/local/sbin/
if [ ! -s /etc/hl-backup/restic.password ]; then
  (umask 077; openssl rand -base64 48 | tr -d '\n' > /etc/hl-backup/restic.password)
  echo "Mot de passe restic généré dans /etc/hl-backup/restic.password — À COPIER DANS UN GESTIONNAIRE DE MOTS DE PASSE : sans lui, les sauvegardes sont illisibles."
fi
{
  echo "RESTIC_REPOSITORY=$HL_RESTIC_REPOSITORY"
  echo "RESTIC_PASSWORD_FILE=/etc/hl-backup/restic.password"
  echo "HL_HOST_TAG=${HL_HOST_TAG:-$(hostname -s)}"
  for v in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY B2_ACCOUNT_ID B2_ACCOUNT_KEY RESTIC_COMPRESSION; do [ -n "${!v:-}" ] && echo "$v=${!v}"; done
} > /etc/hl-backup/env
chmod 600 /etc/hl-backup/env
cat > /etc/cron.d/hl-backup <<CRON
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
15 ${HL_BACKUP_HOUR:-03} * * * root /usr/local/sbin/hl-backup.sh
15 ${HL_VERIFY_HOUR:-07} * * * root /usr/local/sbin/hl-backup-verify.sh >> /var/log/hl-backup.log 2>&1
CRON
chmod 644 /etc/cron.d/hl-backup
echo "Installé. Première sauvegarde : /usr/local/sbin/hl-backup.sh ; contrôle : /usr/local/sbin/hl-backup-verify.sh"
