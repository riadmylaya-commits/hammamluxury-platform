#!/usr/bin/env bash
# PRÉPARATION — ajoute au compte temporaire hl-devin le droit de lancer trois scripts nommés, vérifiés par empreinte et immuables :
# diagnostic complet (lecture seule), sauvegarde externe chiffrée, test de restauration isolé. À lancer en root. Relançable.
# Ne touche ni au site, ni à la base, ni au compte root. Aucun autre droit administrateur n'est accordé.
set -euo pipefail
U=https://raw.githubusercontent.com/riadmylaya-commits/hammamluxury-platform/devin/ops-phases-production/deploy/ops
id hl-devin >/dev/null
install -d -m 711 /opt/hl-ops
while read -r h f; do
  curl -fsSL "$U/$f" -o "/tmp/$f"
  echo "$h  /tmp/$f" | sha256sum -c -
  chattr -i "/opt/hl-ops/$f" 2>/dev/null || true
  install -m 500 -o root -g root "/tmp/$f" "/opt/hl-ops/$f"
  chattr +i "/opt/hl-ops/$f"; rm -f "/tmp/$f"
done <<'L'
515d6a3e4a70b9bdbc37139ca74797b56e0b390b8ae88c52e1d4f355caf08bda diagnostic-root-complet.sh
e694a096c2c34c91108b3e20b4120e108fb6118b8a98a62e0a7e40e07dbb30da prep-sauvegarde.sh
821f8397a222f9f2e00dc454b6d7d86afa20ad220e46d39fedf31a6d6be9dc96 prep-test-restauration.sh
L
chattr -i /etc/sudoers.d/hl-devin 2>/dev/null || true
printf 'Defaults:hl-devin !requiretty, log_output\nhl-devin ALL=(root) NOPASSWD: /opt/hl-ops/diagnostic-root-minimal.sh, /opt/hl-ops/diagnostic-root-complet.sh, /opt/hl-ops/prep-sauvegarde.sh, /opt/hl-ops/prep-test-restauration.sh\n' > /etc/sudoers.d/hl-devin.tmp
visudo -cf /etc/sudoers.d/hl-devin.tmp
chmod 440 /etc/sudoers.d/hl-devin.tmp; mv /etc/sudoers.d/hl-devin.tmp /etc/sudoers.d/hl-devin
chattr +i /etc/sudoers.d/hl-devin
echo "PREPARATION AUTORISEE"
