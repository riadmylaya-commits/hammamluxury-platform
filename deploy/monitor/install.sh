#!/usr/bin/env bash
# Installe la surveillance : script, cron (toutes les 15 min), logrotate, baseline d'intégrité.
set -euo pipefail
SRC="$(cd "$(dirname "$0")" && pwd)"
install -m 700 "$SRC/hl-monitor.sh" /usr/local/sbin/hl-monitor.sh
cat >/etc/cron.d/hl-monitor <<CRON
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
${HL_MONITOR_ENV:-}
*/15 * * * * root /usr/local/sbin/hl-monitor.sh
CRON
cat >/etc/logrotate.d/hl-monitor <<'LR'
/var/log/hl-monitor.log /var/log/hl-backup.log { weekly rotate 8 compress missingok notifempty }
LR
/usr/local/sbin/hl-monitor.sh baseline
echo "Installé : /usr/local/sbin/hl-monitor.sh (cron */15), log /var/log/hl-monitor.log"
