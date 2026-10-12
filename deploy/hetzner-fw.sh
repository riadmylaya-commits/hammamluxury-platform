#!/usr/bin/env bash
# Pare-feu Hetzner Cloud (seconde couche devant UFW) : SSH 22 ouvert, 80/443 depuis les plages Cloudflare, ICMP ; "create" ne l attache pas, "attach"/"detach" visent le seul serveur HL_SERVER_IP.
set -euo pipefail
API=https://api.hetzner.cloud/v1
H=(-H "Authorization: Bearer ${HCLOUD_TOKEN:?jeton API Hetzner Cloud (projet)}" -H "Content-Type: application/json")
STAGING_IP=${HL_SERVER_IP:-91.99.97.205}
NAME=${HL_FW_NAME:-hl-staging-cloudflare}
v4=$(curl -fsS https://www.cloudflare.com/ips-v4); v6=$(curl -fsS https://www.cloudflare.com/ips-v6)
n=$(printf '%s\n%s\n' "$v4" "$v6" | grep -c /); [ "$n" -ge 15 ] || { echo "plages CF suspectes ($n)"; exit 1; }
cf=$(printf '%s\n%s\n' "$v4" "$v6" | jq -R . | jq -s .)
rules=$(jq -n --argjson cf "$cf" '[
 {direction:"in",protocol:"tcp",port:"22",source_ips:["0.0.0.0/0","::/0"],description:"SSH"},
 {direction:"in",protocol:"tcp",port:"80",source_ips:$cf,description:"HTTP Cloudflare"},
 {direction:"in",protocol:"tcp",port:"443",source_ips:$cf,description:"HTTPS Cloudflare"},
 {direction:"in",protocol:"icmp",source_ips:["0.0.0.0/0","::/0"],description:"ICMP"}]')
case "${1:-}" in
 servers) curl -fsS "${H[@]}" "$API/servers" | jq -r '.servers[] | "\(.id) \(.name) \(.public_net.ipv4.ip) fw=\(.public_net.firewalls|map(.id)|join(","))"';;
 create) curl -fsS "${H[@]}" -X POST "$API/firewalls" -d "$(jq -n --arg n "$NAME" --argjson r "$rules" '{name:$n,rules:$r}')" | jq -r '.firewall | "id=\(.id) rules=\(.rules|length)"';;
 show) curl -fsS "${H[@]}" "$API/firewalls" | jq -r --arg n "$NAME" '.firewalls[]|select(.name==$n)|"id=\(.id) applied_to=\(.applied_to|map(.server.id)|join(",")) rules=\(.rules|map("\(.protocol):\(.port // "-") \(.source_ips|length) src")|join(" | "))"';;
 attach|detach)
   sid=$(curl -fsS "${H[@]}" "$API/servers" | jq -r --arg ip "$STAGING_IP" '.servers[]|select(.public_net.ipv4.ip==$ip)|.id'); [ -n "$sid" ] || { echo "serveur staging introuvable"; exit 1; }
   fid=$(curl -fsS "${H[@]}" "$API/firewalls" | jq -r --arg n "$NAME" '.firewalls[]|select(.name==$n)|.id'); [ -n "$fid" ] || { echo "pare-feu introuvable"; exit 1; }
   act=$([ "$1" = attach ] && echo apply_to_resources || echo remove_from_resources)
   curl -fsS "${H[@]}" -X POST "$API/firewalls/$fid/actions/$act" -d "$(jq -n --argjson s "$sid" '{"'"$([ "$1" = attach ] && echo apply_to || echo remove_from)"'":[{type:"server",server:{id:$s}}]}')" | jq -r '.actions[]|"\(.command) \(.status)"';;
 *) echo "usage: $0 servers|create|show|attach|detach"; exit 2;;
esac
