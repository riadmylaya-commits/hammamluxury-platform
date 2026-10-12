#!/usr/bin/env bash
# Sauvegarde chiffrée hors serveur (restic) : base MariaDB + fichiers applicatifs. À lancer en root (cron quotidien).
# Configuration : /etc/hl-backup/env (RESTIC_REPOSITORY, RESTIC_PASSWORD_FILE, variables optionnelles ci-dessous).
set -euo pipefail
set -a; source /etc/hl-backup/env; set +a
APP="${HL_APP_DIR:-/var/www/hammamluxury}"
DB="${HL_DB_NAME:-hl_platform}"
HOST_TAG="${HL_HOST_TAG:-$(hostname -s)}"
STATE_DIR=/var/lib/hl-backup
LOG=/var/log/hl-backup.log
mkdir -p "$STATE_DIR"
exec >>"$LOG" 2>&1
echo "=== $(date -Is) début sauvegarde ($HOST_TAG)"

alert() { "$(dirname "$0")/hl-alert.sh" "Sauvegarde ÉCHOUÉE sur $HOST_TAG" "$1
Journal : $LOG" || true; }
trap 'rc=$?; [ $rc -ne 0 ] && { echo "ÉCHEC rc=$rc"; echo "{\"status\":\"failed\",\"at\":\"$(date -Is)\",\"rc\":$rc}" > "$STATE_DIR/last.json"; alert "Code retour $rc à l étape : ${STEP:-?}"; }' EXIT

STEP=init; restic snapshots >/dev/null 2>&1 || restic init

STEP=db
# Dump cohérent (InnoDB, sans verrou global), transmis directement à restic (jamais écrit en clair sur le disque).
mariadb-dump --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 "$DB" \
  | restic backup --stdin --stdin-filename "db/${DB}.sql" --tag db --tag "$HOST_TAG" --host "$HOST_TAG" --quiet

STEP=files
restic backup --tag files --tag "$HOST_TAG" --host "$HOST_TAG" --quiet \
  --exclude "$APP/storage/app/public/tmp" --exclude "$APP/storage/framework" --exclude "$APP/storage/logs" \
  "$APP/storage/app" "$APP/.env" /etc/nginx/sites-available /etc/letsencrypt /etc/hl-backup/env /etc/ssh/sshd_config.d /etc/fail2ban/jail.local /etc/cron.d 2>/dev/null \
  || [ $? -eq 3 ]   # 3 = fichiers ignorés (ex. absents), sauvegarde quand même écrite

STEP=retention
restic forget --host "$HOST_TAG" --group-by host,tags --keep-daily "${HL_KEEP_DAILY:-7}" --keep-weekly "${HL_KEEP_WEEKLY:-4}" --keep-monthly "${HL_KEEP_MONTHLY:-6}" --prune --quiet

STEP=check
restic check --read-data-subset=10% --quiet

STEP=done
DB_SNAP=$(restic snapshots --host "$HOST_TAG" --tag db --latest 1 --json | python3 -c 'import sys,json;s=json.load(sys.stdin);print(s[-1]["short_id"], s[-1]["time"])')
FILES_SNAP=$(restic snapshots --host "$HOST_TAG" --tag files --latest 1 --json | python3 -c 'import sys,json;s=json.load(sys.stdin);print(s[-1]["short_id"], s[-1]["time"])')
echo "{\"status\":\"ok\",\"at\":\"$(date -Is)\",\"db\":\"$DB_SNAP\",\"files\":\"$FILES_SNAP\"}" > "$STATE_DIR/last.json"
echo "OK db=[$DB_SNAP] files=[$FILES_SNAP]"
trap - EXIT
