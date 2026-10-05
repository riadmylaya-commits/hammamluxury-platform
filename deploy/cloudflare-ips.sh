#!/usr/bin/env bash
# Met à jour les plages IP Cloudflare utilisées par Nginx (real_ip), Laravel (proxys de confiance) et UFW.
# Lancement hebdomadaire par cron (/etc/cron.d/hl-cloudflare) et à la main : `cloudflare-ips.sh [--ufw]`.
# Sans Cloudflare devant le serveur, les en-têtes CF-Connecting-IP n'existent pas et ce fichier est sans effet.
set -euo pipefail
APP=/var/www/hammamluxury
NGINX_CONF=/etc/nginx/conf.d/cloudflare-realip.conf
STATE=/var/lib/hl/cloudflare-ips.txt
ALERT=/usr/local/sbin/hl-alert.sh
mkdir -p "$(dirname "$STATE")"

fail() { echo "cloudflare-ips: $*" >&2; [ -x "$ALERT" ] && "$ALERT" "Cloudflare IP ranges" "$*" || true; exit 1; }

tmp=$(mktemp)
for v in ips-v4 ips-v6; do
    curl -fsS --max-time 20 "https://www.cloudflare.com/$v" >>"$tmp" || fail "téléchargement $v impossible, listes inchangées"
    echo >>"$tmp"
done
grep -E '^[0-9a-fA-F.:]+/[0-9]+$' "$tmp" | sort -u >"$tmp.clean"
count=$(wc -l <"$tmp.clean")
[ "$count" -ge 15 ] || fail "liste suspecte ($count plages), listes inchangées"

if [ -f "$STATE" ] && cmp -s "$STATE" "$tmp.clean" && [ "${1:-}" != "--force" ] && [ "${1:-}" != "--ufw" ]; then
    rm -f "$tmp" "$tmp.clean"; exit 0
fi
cp "$tmp.clean" "$STATE"

{
    echo "# Généré par deploy/cloudflare-ips.sh — ne pas éditer"
    sed 's/^/set_real_ip_from /; s/$/;/' "$STATE"
    echo "real_ip_header CF-Connecting-IP;"
    echo "real_ip_recursive on;"
} >"$NGINX_CONF.tmp"
nginx -t -c /etc/nginx/nginx.conf >/dev/null 2>&1 || true
mv "$NGINX_CONF.tmp" "$NGINX_CONF"
nginx -t >/dev/null 2>&1 && systemctl reload nginx || { rm -f "$NGINX_CONF"; fail "nginx -t a échoué, fichier real_ip retiré"; }

install -o deploy -g deploy -m 0644 "$STATE" "$APP/storage/app/trusted-proxies.txt"
sudo -u deploy -H php "$APP/artisan" config:cache >/dev/null

if [ "${1:-}" = "--ufw" ] || [ -f /var/lib/hl/cloudflare-ufw.enabled ]; then
    "$(dirname "$0")/cf-ufw.sh" on
fi
rm -f "$tmp" "$tmp.clean"
echo "cloudflare-ips: $count plages appliquées"
