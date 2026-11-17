#!/usr/bin/env bash
# PRÉPARATION — sauvegarde externe chiffrée de la production (root). Relançable.
# Usage : echo "<mot de passe du sous-compte>" | prep-sauvegarde.sh <identifiant du sous-compte, ex. u682965-sub1>
# Le mot de passe (lu sur l'entrée standard, jamais affiché ni conservé) ne sert qu'une fois, à déposer la clé SSH du serveur.
# N'agit pas sur l'application : ni git dans /var/www, ni migration, ni écriture en base, ni redémarrage, ni DNS.
set -euo pipefail
SB_USER="${1:-}"; [[ "$SB_USER" =~ ^u682965-sub[0-9]+$ ]] || { echo "identifiant de sous-compte invalide"; exit 2; }
SB_HOST="$SB_USER.your-storagebox.de"; DATE=$(date +%Y%m%d-%H%M%S); PRE=/root/pre-prep-$DATE; APP=/var/www/hammamluxury
IFS= read -r -t 5 SB_PASS || SB_PASS=""
exec > >(tee -a "/root/prep-sauvegarde-$DATE.log") 2>&1
echo "== 1 copie locale de sécurité → $PRE"; install -d -m 700 "$PRE"
mariadb-dump --single-transaction --routines --triggers hl_platform | gzip > "$PRE/db.sql.gz"
cp -a "$APP/.env" "$PRE/env"; [ -d /etc/hl-backup ] && cp -a /etc/hl-backup "$PRE/hl-backup"
tar czf "$PRE/etc.tgz" /etc/nginx /etc/fail2ban /etc/cron.d /etc/cron.daily /etc/ssh/sshd_config.d 2>/dev/null || true
[ -s "$PRE/db.sql.gz" ] && [ -s "$PRE/env" ]
echo "== 2 clé SSH du serveur pour la Storage Box"; install -d -m 700 /root/.ssh
[ -f /root/.ssh/hl_storagebox ] || ssh-keygen -q -t ed25519 -N '' -f /root/.ssh/hl_storagebox -C hl-prod
touch /root/.ssh/config; chmod 600 /root/.ssh/config
if grep -q "^Host hl-storagebox$" /root/.ssh/config; then
  grep -A6 "^Host hl-storagebox$" /root/.ssh/config | grep -q "User $SB_USER$" || { echo "alias hl-storagebox déjà présent avec un autre compte — arrêt sans modification"; exit 3; }
else
  printf 'Host hl-storagebox\n  HostName %s\n  Port 23\n  User %s\n  IdentityFile /root/.ssh/hl_storagebox\n  IdentitiesOnly yes\n  StrictHostKeyChecking accept-new\n' "$SB_HOST" "$SB_USER" >> /root/.ssh/config
fi
ok() { sftp -q -o BatchMode=yes hl-storagebox <<< "pwd" >/dev/null 2>&1; }
if ! ok; then
  [ -n "$SB_PASS" ] || { echo "clé non acceptée et aucun mot de passe fourni — arrêt"; exit 4; }
  T=$(mktemp -d); chmod 700 "$T"; printf '#!/bin/sh\ncat %s/p\n' "$T" > "$T/ask"; chmod 700 "$T/ask"; (umask 077; printf '%s\n' "$SB_PASS" > "$T/p")
  cp /root/.ssh/hl_storagebox.pub "$T/authorized_keys"
  SSH_ASKPASS="$T/ask" SSH_ASKPASS_REQUIRE=force setsid -w sftp -q -P 23 -o StrictHostKeyChecking=accept-new -o PubkeyAuthentication=no -o PreferredAuthentications=password -o NumberOfPasswordPrompts=1 "$SB_USER@$SB_HOST" <<SFTP || true
-mkdir .ssh
chmod 700 .ssh
put $T/authorized_keys .ssh/authorized_keys
chmod 600 .ssh/authorized_keys
SFTP
  rm -rf "$T"
fi
unset SB_PASS; ok && echo "accès SFTP par clé : OK" || { echo "accès SFTP par clé : ÉCHEC — arrêt"; exit 5; }
echo "== 3 scripts de sauvegarde (copie du dépôt hors /var/www, version figée)"; D=/root/hl-deploy-prep
PIN=d1527a64b110daef9c606fed5d568ee739926d2e
[ -d "$D/.git" ] || git clone -q https://github.com/riadmylaya-commits/hammamluxury-platform.git "$D"
git -C "$D" fetch -q origin; git -C "$D" checkout -q --detach "$PIN"; [ "$(git -C "$D" rev-parse HEAD)" = "$PIN" ]; [ -z "$(git -C "$D" status --porcelain)" ]
git -C "$D" --no-pager log --oneline -1
HL_RESTIC_REPOSITORY="sftp:hl-storagebox:hl-prod" HL_HOST_TAG=hl-prod bash "$D/deploy/backup/install.sh" | grep -v "restic.password" || true
set -a; . /etc/hl-backup/env; set +a
restic snapshots >/dev/null 2>&1 || restic init | grep -v -i password
echo "== 4 première sauvegarde + contrôle"; /usr/local/sbin/hl-backup.sh; tail -2 /var/log/hl-backup.log
/usr/local/sbin/hl-backup-verify.sh; restic check; restic snapshots --compact
echo "PREP SAUVEGARDE OK — journal /root/prep-sauvegarde-$DATE.log"
