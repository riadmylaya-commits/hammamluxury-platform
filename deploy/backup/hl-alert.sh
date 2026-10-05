#!/usr/bin/env bash
# Envoie une alerte e-mail via l'application (SMTP configuré dans .env, destinataire hl.alert_email).
set -uo pipefail
APP="${HL_APP_DIR:-/var/www/hammamluxury}"
subject="$1"; body="${2:-}"
printf '%s\n\nServeur : %s\nDate : %s\n' "$body" "$(hostname -f)" "$(date -Is)" \
  | sudo -u deploy -H php "$APP/artisan" hl:alert "$subject" \
  || logger -t hl-backup "ALERTE non envoyée : $subject"
