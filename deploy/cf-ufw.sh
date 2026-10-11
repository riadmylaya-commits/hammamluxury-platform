#!/usr/bin/env bash
# `cf-ufw.sh on`  : ports 80/443 ouverts uniquement aux plages Cloudflare (protection de l'IP d'origine).
# `cf-ufw.sh off` : retour arrière immédiat, 80/443 ouverts à tous (état d'origine).
# Le port 22 n'est jamais modifié.
set -euo pipefail
STATE=/var/lib/hl/cloudflare-ips.txt
FLAG=/var/lib/hl/cloudflare-ufw.enabled
mode=${1:-}
[ "$mode" = on ] || [ "$mode" = off ] || { echo "usage: $0 on|off" >&2; exit 2; }

# Retire toutes les règles 80/443 existantes (globales et par plage), de la dernière à la première.
while read -r num; do ufw --force delete "$num" >/dev/null; done < <(ufw status numbered | grep -E '\b(80|443)/tcp\b' | sed -E 's/^\[ *([0-9]+)\].*/\1/' | sort -rn)

if [ "$mode" = on ]; then
    [ -s "$STATE" ] || { echo "liste Cloudflare absente : lancer cloudflare-ips.sh d'abord" >&2; exit 1; }
    while read -r cidr; do
        ufw allow proto tcp from "$cidr" to any port 80,443 comment cloudflare >/dev/null
    done <"$STATE"
    touch "$FLAG"
else
    ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null
    rm -f "$FLAG"
fi
ufw status | grep -cE '\b(80|443)|80,443' | xargs -I{} echo "cf-ufw: mode $mode, {} règles 80/443"
