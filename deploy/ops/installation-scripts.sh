#!/usr/bin/env bash
# INSTALLATION DES SCRIPTS (mode de secours de l'hébergeur, disque du serveur monté dans /mnt) — à lancer depuis le dossier contenant les
# quatre fichiers ci-dessous. Dépose les nouveaux scripts vérifiés, RETIRE DÉFINITIVEMENT les deux anciens (deploiement-code.sh et
# retour-arriere.sh, ce dernier restaurait la base) et leur droit d'exécution. Ne touche ni au site, ni à la base, ni à .env, ni à root.
# Tout est vérifié avant la première écriture ; en cas d'échec de la vérification des droits, l'ancien fichier de droits est conservé.
set -euo pipefail
M=/mnt; OPS=$M/opt/hl-ops; SUD=$M/etc/sudoers.d/hl-devin
NOUVEAUX="deploiement-d155c5a.sh retour-arriere-code.sh diagnostic-root-complet.sh sommes-de-controle.sh"
ANCIENS="deploiement-code.sh retour-arriere.sh"
mountpoint -q $M || mount /dev/sda1 $M
grep -q '^hl-devin:' $M/etc/passwd; [ -d "$OPS" ]; [ -f "$SUD" ]
sha256sum -c <<'L'
a2999202f8965cf654a6415bd7bcb373246d34076429a3db4341790ac66803bf  deploiement-d155c5a.sh
f8e3cb84470d55079d468a6d7e792fbbc0c27e2fa9dd25d00aa764d06e946451  retour-arriere-code.sh
918b293c015d77f0e7199db43009eb9b88762c7a3d616684b1a8c8e5789ccbbc  diagnostic-root-complet.sh
03e54b87cb0da04ff592606379e988927586eb5d59b88a044ef6faa205f01896  sommes-de-controle.sh
L
for f in $NOUVEAUX; do bash -n "$f"; done
ARCH=$M/root/anciens-scripts-$(date +%Y%m%d-%H%M%S); install -d -m 700 "$ARCH"; cp -a "$SUD" "$ARCH/sudoers.hl-devin.avant"
# 1. Droits : les deux anciens scripts disparaissent de la liste, les nouveaux y entrent. Vérifié par visudo avant remplacement.
chattr -i "$SUD"
printf 'Defaults:hl-devin !requiretty, log_output\nhl-devin ALL=(root) NOPASSWD: /opt/hl-ops/diagnostic-root-minimal.sh, /opt/hl-ops/diagnostic-root-complet.sh, /opt/hl-ops/sommes-de-controle.sh, /opt/hl-ops/prep-sauvegarde.sh, /opt/hl-ops/prep-test-restauration.sh, /opt/hl-ops/recette.sh, /opt/hl-ops/six-fiches.sh, /opt/hl-ops/deploiement-d155c5a.sh, /opt/hl-ops/retour-arriere-code.sh\n' > "$SUD.tmp"
chown 0:0 "$SUD.tmp"; chmod 440 "$SUD.tmp"
if chroot $M /usr/sbin/visudo -cf /etc/sudoers.d/hl-devin.tmp; then mv "$SUD.tmp" "$SUD"; else rm -f "$SUD.tmp"; chattr +i "$SUD"; echo "DROITS INVALIDES — rien n'est changé"; exit 1; fi
chattr +i "$SUD"
# 2. Anciens scripts : copie d'archive illisible et non exécutable (droits 000, root), puis suppression du fichier d'origine.
for f in $ANCIENS; do
    if [ -e "$OPS/$f" ]; then chattr -i "$OPS/$f"; install -m 000 -o 0 -g 0 "$OPS/$f" "$ARCH/$f.retire"; rm -f "$OPS/$f"; fi
    [ ! -e "$OPS/$f" ] || { echo "ancien script $f toujours présent — arrêt"; exit 1; }
done
# 3. Nouveaux scripts : root seul, exécution seule, non modifiables.
for f in $NOUVEAUX; do
    chattr -i "$OPS/$f" 2>/dev/null || true
    install -m 500 -o 0 -g 0 "$f" "$OPS/$f"; chattr +i "$OPS/$f"
    [ "$(sha256sum < "$OPS/$f")" = "$(sha256sum < "$f")" ] || { echo "empreinte de $f différente après installation — arrêt"; exit 1; }
done
# 4. Constat sur les fichiers réellement installés.
(cd "$OPS" && sha256sum *); ls -l "$OPS" | awk '{print $1,$3,$9}'; lsattr "$OPS"/* "$SUD" | awk '{print $1,$2}'
if grep -qE 'deploiement-code\.sh|/retour-arriere\.sh' "$SUD"; then echo "un ancien script figure encore dans les droits — arrêt"; exit 1; fi
sync; umount $M
echo "INSTALLATION OK — anciens scripts retirés"
