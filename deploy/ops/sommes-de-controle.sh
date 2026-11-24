#!/usr/bin/env bash
# SOMMES DE CONTRÔLE (production, root) — LECTURE SEULE. Affiche l'empreinte SHA-256, le propriétaire, les droits et le verrou
# « non modifiable » des fichiers d'intervention réellement installés, ainsi que les droits accordés au compte hl-devin.
# Ne modifie rien, n'affiche aucun secret.
set -uo pipefail
for f in /opt/hl-ops/* /usr/local/sbin/hl-backup.sh /usr/local/sbin/hl-restore.sh /etc/sudoers.d/hl-devin; do
    [ -f "$f" ] || { echo "ABSENT  $f"; continue; }
    printf '%s  %s  %s  %s\n' "$(sha256sum < "$f" | cut -d' ' -f1)" "$(stat -c '%U:%G %a' "$f")" "$(lsattr "$f" 2>/dev/null | cut -d' ' -f1 | grep -q i && echo verrouillé || echo NON-VERROUILLÉ)" "$f"
done
echo "== droits du compte hl-devin"; grep -v '^Defaults' /etc/sudoers.d/hl-devin | tr ',' '\n' | sed 's/^.*NOPASSWD: *//; s/^ *//'
echo "== anciens scripts (doivent être absents)"; for f in deploiement-code.sh retour-arriere.sh six-fiches.sh; do [ -e "/opt/hl-ops/$f" ] && echo "PRÉSENT : $f" || echo "absent : $f"; done
