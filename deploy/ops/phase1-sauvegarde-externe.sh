#!/usr/bin/env bash
# PHASE 1 (production, root) — sauvegarde locale + dépôt restic chiffré sur le sous-compte Storage Box hl-prod + première sauvegarde + vérification.
# Aucune action sur l'application : ni migrate, ni git, ni redémarrage, ni écriture en base, ni DNS.
# Usage : HL_SB_USER=u682965-subN bash phase1-sauvegarde-externe.sh
set -euo pipefail
[ -f /root/ACCORD-PHASE-1 ] || { echo "Pas d'accord écrit (fichier /root/ACCORD-PHASE-1 absent) — arrêt."; exit 1; }
: "${HL_SB_USER:?HL_SB_USER (identifiant du sous-compte hl-prod, ex. u682965-sub3) requis}"
SB_HOST="u682965.your-storagebox.de"; DATE=$(date +%Y%m%d-%H%M%S); PRE=/root/pre-phase-1-$DATE; APP=/var/www/hammamluxury
exec > >(tee -a /root/phase-1-$DATE.log) 2>&1
echo "== P0 scripts récents (hors /var/www)"; rm -rf /root/hl-deploy; git clone -q --depth 1 -b main https://github.com/riadmylaya-commits/hammamluxury-platform.git /root/hl-deploy
echo "== P1 sauvegarde locale → $PRE"; install -d -m 700 "$PRE"
mysqldump --single-transaction --routines --triggers hl_platform | gzip > "$PRE/db.sql.gz"
cp -a "$APP/.env" "$PRE/env"; tar czf "$PRE/etc.tgz" /etc/nginx /etc/fail2ban /etc/cron.d 2>/dev/null; tar czf "$PRE/storage-app.tgz" -C "$APP" storage/app
ufw status verbose > "$PRE/ufw.txt" || true; crontab -l > "$PRE/crontab-root.txt" 2>/dev/null || true
( cd "$PRE" && sha256sum * > SHA256SUMS ); cat "$PRE/SHA256SUMS"; [ -s "$PRE/db.sql.gz" ] && [ -s "$PRE/env" ]
echo "== P2 clé SSH dédiée (distincte de la staging) + alias"; [ -f /root/.ssh/hl_storagebox ] || ssh-keygen -q -t ed25519 -N '' -f /root/.ssh/hl_storagebox -C hl-prod
grep -q "Host hl-storagebox" /root/.ssh/config 2>/dev/null || cat >> /root/.ssh/config <<CFG
Host hl-storagebox
  HostName $SB_HOST
  Port 23
  User $HL_SB_USER
  IdentityFile /root/.ssh/hl_storagebox
  IdentitiesOnly yes
CFG
chmod 600 /root/.ssh/config
echo "Clé publique à déposer sur le sous-compte (Console Hetzner → sous-compte → SSH keys) si ce n'est pas déjà fait :"; cat /root/.ssh/hl_storagebox.pub
if ! sftp -q -o BatchMode=yes hl-storagebox <<< "pwd" >/dev/null 2>&1; then
  echo "Clé non encore acceptée : dépôt par mot de passe (saisi une fois, jamais stocké)."; ssh-copy-id -s -i /root/.ssh/hl_storagebox.pub -p 23 "$HL_SB_USER@$SB_HOST"
fi
sftp -q -o BatchMode=yes hl-storagebox <<< "pwd" >/dev/null && echo "Accès SFTP OK"
echo "== P2 installation restic (mot de passe de chiffrement généré sur ce serveur, différent de la staging)"
HL_RESTIC_REPOSITORY="sftp:hl-storagebox:hl-prod" HL_HOST_TAG=hl-prod bash /root/hl-deploy/deploy/backup/install.sh
set -a; . /etc/hl-backup/env; set +a
restic snapshots >/dev/null 2>&1 || restic init
echo "== P3 première sauvegarde réelle + vérification"; /usr/local/sbin/hl-backup.sh; /usr/local/sbin/hl-backup-verify.sh; restic check; restic snapshots
echo "== Terminé. Mot de passe restic : /etc/hl-backup/restic.password — à copier dans le gestionnaire de mots de passe de Fatima (remis hors chat). Journal : /root/phase-1-$DATE.log"
