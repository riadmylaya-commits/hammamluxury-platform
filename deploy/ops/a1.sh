#!/usr/bin/env bash
# ÉTAPE A — accès temporaire limité « hl-devin » (à lancer en root). Relançable. S'arrête à la première erreur.
# Ne touche ni au site (/var/www, nginx, php), ni à la base, ni au compte root.
set -euo pipefail
U=https://raw.githubusercontent.com/riadmylaya-commits/hammamluxury-platform/devin/ops-phases-production/deploy/ops
H1=0ef76e9e66f17eabebdc2c648544e4a5489b19a5dbd84f34a455c33a954c01ee
H2=165db0af91f249f1a04b368f696b8a0984519a66f18e6cb06d686b1d4f2c3782
# A1 — compte, clé restreinte, réglage sshd limité à hl-devin
id hl-devin >/dev/null 2>&1 || adduser --disabled-password --gecos "Intervention Devin HammamLuxury" hl-devin
usermod -e 2026-11-30 hl-devin
install -d -m 700 -o hl-devin -g hl-devin /home/hl-devin/.ssh
echo 'restrict ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPNVyo0mYdPI7/X7bVfsEKgm8s6/gSYnloFWOIsiXVpy devin-hammamluxury-prod-2026-11-12' > /home/hl-devin/.ssh/authorized_keys
chown hl-devin:hl-devin /home/hl-devin/.ssh/authorized_keys
chmod 600 /home/hl-devin/.ssh/authorized_keys
rm -f /etc/ssh/sshd_config.d/90-hl-devin.conf
sshd -T -C user=root,host=x,addr=127.0.0.1 | grep -E '^(permitrootlogin|pubkeyauthentication) ' > /root/sshd-root-avant.txt
printf 'Match User hl-devin\n  PasswordAuthentication no\n  AllowTcpForwarding no\n  AllowAgentForwarding no\n  X11Forwarding no\n  PermitTunnel no\n' > /etc/ssh/sshd_config.d/90-hl-devin.conf
sshd -t
sshd -T -C user=root,host=x,addr=127.0.0.1 | grep -E '^(permitrootlogin|pubkeyauthentication) ' > /root/sshd-root-apres.txt
diff /root/sshd-root-avant.txt /root/sshd-root-apres.txt
systemctl reload ssh
echo "A1 OK"
# A2 — deux diagnostics lecture seule, empreinte vérifiée (bloquant), immuables
install -d -m 711 /opt/hl-ops
chattr -i /opt/hl-ops/diagnostic-sans-root.sh /opt/hl-ops/diagnostic-root-minimal.sh 2>/dev/null || true
curl -fsSL "$U/diagnostic-sans-root.sh" -o /tmp/d1.sh
echo "$H1  /tmp/d1.sh" | sha256sum -c -
install -m 555 -o root -g root /tmp/d1.sh /opt/hl-ops/diagnostic-sans-root.sh
curl -fsSL "$U/diagnostic-root-minimal.sh" -o /tmp/d2.sh
echo "$H2  /tmp/d2.sh" | sha256sum -c -
install -m 500 -o root -g root /tmp/d2.sh /opt/hl-ops/diagnostic-root-minimal.sh
chattr +i /opt/hl-ops/diagnostic-sans-root.sh /opt/hl-ops/diagnostic-root-minimal.sh
rm -f /tmp/d1.sh /tmp/d2.sh
echo "A2 OK"
# A3 — sudo pour le seul diagnostic root, fichier immuable
chattr -i /etc/sudoers.d/hl-devin 2>/dev/null || true
printf 'Defaults:hl-devin !requiretty, log_output\nhl-devin ALL=(root) NOPASSWD: /opt/hl-ops/diagnostic-root-minimal.sh\n' > /etc/sudoers.d/hl-devin
chmod 440 /etc/sudoers.d/hl-devin
visudo -cf /etc/sudoers.d/hl-devin
chattr +i /etc/sudoers.d/hl-devin
echo "A3 OK"
echo "ETAPE A TERMINEE"
