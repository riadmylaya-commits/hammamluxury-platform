#!/usr/bin/env bash
# Installe la compatibilité Cloudflare côté serveur (idempotent, sans activer le filtrage UFW ni l'origin pull) :
# scripts, cron hebdomadaire, real_ip Nginx, vhost par défaut 444, prisons fail2ban Nginx.
set -euo pipefail
cd "$(dirname "$0")"
install -m 0755 cloudflare-ips.sh cf-ufw.sh /usr/local/sbin/
cat >/etc/cron.d/hl-cloudflare <<'CRON'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
17 4 * * 1 root /usr/local/sbin/cloudflare-ips.sh
CRON
install -m 0644 nginx/00-default-444.conf /etc/nginx/conf.d/00-default-444.conf
touch /etc/nginx/conf.d/hl-banned.conf
# Le fichier des IP bannies doit être inclus dans le contexte http : conf.d/*.conf l'est déjà par défaut sous Debian/Ubuntu.
install -m 0644 fail2ban/hl-nginx.local /etc/fail2ban/jail.d/hl-nginx.local
install -m 0644 fail2ban/filter-hl-nginx-abuse.conf /etc/fail2ban/filter.d/hl-nginx-abuse.conf
install -m 0644 fail2ban/filter-hl-nginx-login.conf /etc/fail2ban/filter.d/hl-nginx-login.conf
install -m 0644 fail2ban/action-hl-nginx-deny.conf /etc/fail2ban/action.d/hl-nginx-deny.conf
/usr/local/sbin/cloudflare-ips.sh --force
nginx -t && systemctl reload nginx
fail2ban-client reload >/dev/null && fail2ban-client status | sed -n 's/.*Jail list://p'
echo CLOUDFLARE_COMPAT_INSTALLED
