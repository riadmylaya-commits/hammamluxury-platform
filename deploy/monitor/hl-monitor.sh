#!/usr/bin/env bash
# Surveillance HammamLuxury (cron toutes les 15 min). Alerte e-mail via hl-alert.sh (PR sauvegardes) en cas d'anomalie,
# une seule fois par anomalie (état dans /var/lib/hl-monitor), puis message de retour à la normale.
# Usage : hl-monitor.sh [baseline]   — « baseline » (ré)enregistre les empreintes des fichiers critiques.
set -uo pipefail
APP="${HL_APP_DIR:-/var/www/hammamluxury}"
URL="${HL_PUBLIC_URL:-https://staging.hammamluxury.com}"
STATE=/var/lib/hl-monitor; mkdir -p "$STATE"
LOG=/var/log/hl-monitor.log
DISK_MAX="${HL_DISK_MAX_PCT:-85}"; CERT_MIN_DAYS="${HL_CERT_MIN_DAYS:-14}"
LOGIN_MAX="${HL_LOGIN_FAIL_MAX:-30}"      # tentatives de connexion refusées (429/422) en 15 min, toutes IP confondues
ERR_MAX="${HL_APP_ERR_MAX:-5}"            # lignes ERROR/CRITICAL Laravel en 15 min

# Fichiers dont toute modification doit être signalée (code déployé exclu : il change à chaque release).
INTEGRITY=( "$APP/.env" "$APP/public/index.php" "$APP/public/.htaccess" /etc/nginx/nginx.conf /etc/nginx/sites-available/hammamluxury
  /etc/ssh/sshd_config /etc/fail2ban/jail.d /etc/cron.d /etc/hl-backup/env /usr/local/sbin /etc/sudoers /etc/sudoers.d /root/.ssh/authorized_keys /home/deploy/.ssh/authorized_keys )

fingerprint() { for f in "${INTEGRITY[@]}"; do [ -e "$f" ] && find "$f" -type f -exec sha256sum {} + 2>/dev/null; done | sort -k2; }

if [ "${1:-}" = baseline ]; then fingerprint > "$STATE/integrity.baseline"; echo "Empreintes enregistrées : $(wc -l < "$STATE/integrity.baseline") fichiers"; exit 0; fi

ts() { date -Is; }
log() { echo "$(ts) $*" >> "$LOG"; }
alert() { # alert <clé> <sujet> <corps>  — n'envoie qu'au premier déclenchement
  local key="$1" subject="$2" body="$3"
  if [ ! -f "$STATE/active.$key" ]; then
    : > "$STATE/active.$key"; log "ALERTE[$key] $subject"
    [ -x /usr/local/sbin/hl-alert.sh ] && /usr/local/sbin/hl-alert.sh "Surveillance : $subject" "$body" >/dev/null 2>&1 || logger -t hl-monitor "ALERTE $subject"
  fi
}
resolve() { local key="$1"; if [ -f "$STATE/active.$key" ]; then rm -f "$STATE/active.$key"; log "RÉTABLI[$key]"
  [ -x /usr/local/sbin/hl-alert.sh ] && /usr/local/sbin/hl-alert.sh "Surveillance : retour à la normale ($key)" "L'anomalie « $key » n'est plus détectée." >/dev/null 2>&1; fi; }
check() { # check <clé> <condition_ok:0|1> <sujet> <corps>
  if [ "$2" -eq 0 ]; then resolve "$1"; else alert "$1" "$3" "$4"; fi
}

# 1. Services
down=(); for s in nginx php8.3-fpm mariadb redis-server supervisor fail2ban cron; do systemctl is-active --quiet "$s" || down+=("$s"); done
supervisorctl status hl-queue 2>/dev/null | grep -q RUNNING || down+=("hl-queue")
check services $([ ${#down[@]} -eq 0 ] && echo 0 || echo 1) "service(s) arrêté(s) : ${down[*]:-}" "Services non actifs : ${down[*]:-}"

# 2. Site et base joignables
code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$URL/up" || echo 000)
check http $([ "$code" = 200 ] && echo 0 || echo 1) "site injoignable (HTTP $code)" "$URL/up a répondu $code."
mariadb -N -e "SELECT 1" >/dev/null 2>&1; check db $? "base MariaDB injoignable" "SELECT 1 a échoué."

# 3. Disque
use=$(df --output=pcent / | tail -1 | tr -dc 0-9)
check disk $([ "$use" -lt "$DISK_MAX" ] && echo 0 || echo 1) "disque à ${use} %" "Partition / remplie à ${use} % (seuil ${DISK_MAX} %)."

# 4. Certificat TLS
exp=$(echo | openssl s_client -servername "${URL#https://}" -connect "${URL#https://}:443" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
days=$(( ( $(date -d "${exp:-now}" +%s) - $(date +%s) ) / 86400 ))
check cert $([ "$days" -ge "$CERT_MIN_DAYS" ] && echo 0 || echo 1) "certificat TLS expire dans $days j" "Expiration : ${exp:-inconnue}."

# 5. Intégrité des fichiers critiques
if [ -f "$STATE/integrity.baseline" ]; then
  diff=$(diff <(cat "$STATE/integrity.baseline") <(fingerprint) | grep '^[<>]' | awk '{print $1" "$3}' | sort -u | head -40)
  check integrity $([ -z "$diff" ] && echo 0 || echo 1) "fichier(s) critique(s) modifié(s)" "Différences (< baseline, > actuel) :"$'\n'"$diff"$'\n\n'"Si légitime : hl-monitor.sh baseline"
fi

# 6. Connexions refusées et erreurs applicatives (15 dernières minutes)
since=$(date -d '-15 min' '+%d/%b/%Y:%H:%M')
fails=$(awk -v s="$since" '{ t=substr($4,2,17); if (t>=s && $6=="\"POST" && $7 ~ /(login|two-factor|2fa|mot-de-passe)/ && ($9==429 || $9==422)) n++ } END{print n+0}' /var/log/nginx/access.log 2>/dev/null)
check logins $([ "${fails:-0}" -lt "$LOGIN_MAX" ] && echo 0 || echo 1) "$fails connexions refusées en 15 min" "Possible attaque par force brute : $fails réponses 429/422 sur les pages de connexion."
errs=$(find "$APP/storage/logs" -name 'laravel*.log' -mmin -15 -exec grep -hE "^\[[^]]+\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY)" {} + 2>/dev/null | awk -v s="$(date -u -d '-15 min' '+%Y-%m-%d %H:%M')" '{ if (substr($1,2,16) >= s) n++ } END{print n+0}')
check apperrors $([ "${errs:-0}" -lt "$ERR_MAX" ] && echo 0 || echo 1) "$errs erreurs applicatives en 15 min" "Voir $APP/storage/logs/laravel-$(date +%F).log"

# 7. Redémarrage requis (mises à jour noyau) — information, une fois
[ -f /var/run/reboot-required ] && check reboot 1 "redémarrage requis (mise à jour noyau)" "Appliqué automatiquement à 04:30 UTC." || resolve reboot

# 8. Bannissements fail2ban (information dans le log uniquement)
log "ok services=${#down[@]} http=$code disk=${use}% cert=${days}j logins15=$fails errs15=$errs f2b=$(fail2ban-client status sshd 2>/dev/null | grep -oP 'Currently banned:\s*\K\d+')"
