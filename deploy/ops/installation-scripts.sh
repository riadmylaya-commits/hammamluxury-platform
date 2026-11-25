#!/usr/bin/env bash
# INSTALLATION DES SCRIPTS (mode de secours de l'hébergeur, disque du serveur monté dans /mnt) — à lancer depuis le dossier contenant les
# quatre fichiers ci-dessous. Dépose les nouveaux scripts vérifiés, RETIRE DÉFINITIVEMENT les trois anciens (deploiement-code.sh,
# retour-arriere.sh qui restaurait la base, six-fiches.sh) et leur droit d'exécution. Ne touche ni au site, ni à la base, ni à .env, ni à root.
#
# Sécurité de l'opération :
# - Phase A (lecture seule) : tout est vérifié avant la première écriture — sommes de contrôle, syntaxe, aucune tâche planifiée ni service
#   ne dépend des anciens scripts, nouveaux droits validés par visudo. Un échec ici ne modifie rien.
# - Phase B (écritures) : chaque étape est inscrite dans le journal $ARCH/journal.txt ; les droits d'origine et les anciens scripts sont
#   archivés avant d'être touchés.
# - RÉCUPÉRATION si l'installation est interrompue ou partielle : relancer ce même script. Il reprend là où il s'est arrêté (chaque étape
#   constate l'état réel avant d'agir) et ne se termine par « INSTALLATION OK » que si l'état final complet est constaté.
#   Aucune étape ne touche au démarrage du serveur, au site ou à la base : quel que soit le point d'interruption, le serveur redémarre
#   normalement ; au pire le compte d'intervention n'a temporairement plus le droit de lancer un script, ce qui ne modifie rien.
# - Retour à l'état d'origine des droits : installation-scripts.sh ANNULER-DROITS <dossier d'archive> (remet le fichier de droits archivé).
set -euo pipefail
M=/mnt; OPS=$M/opt/hl-ops; SUD=$M/etc/sudoers.d/hl-devin
NOUVEAUX="deploiement-d155c5a.sh retour-arriere-code.sh diagnostic-root-complet.sh sommes-de-controle.sh"
ANCIENS="deploiement-code.sh retour-arriere.sh six-fiches.sh"
# Compte d'intervention : valable jusqu'au 20/12/2026 inclus (accord du 25/11/2026) ; il s'éteint seul le 21/12. Aucun autre droit n'est modifié.
EXP_AVANT=$(( $(date -u -d 2026-11-30 +%s) / 86400 )); EXP_FIN=$(( $(date -u -d 2026-12-21 +%s) / 86400 ))
lire_exp() { awk -F: '$1=="hl-devin"{print $8}' $M/etc/shadow; }
fixer_exp() { chroot $M /usr/bin/chage -E 2026-12-21 hl-devin; }
DROITS='Defaults:hl-devin !requiretty, log_output\nhl-devin ALL=(root) NOPASSWD: /opt/hl-ops/diagnostic-root-minimal.sh, /opt/hl-ops/diagnostic-root-complet.sh, /opt/hl-ops/sommes-de-controle.sh, /opt/hl-ops/prep-sauvegarde.sh, /opt/hl-ops/prep-test-restauration.sh, /opt/hl-ops/recette.sh, /opt/hl-ops/deploiement-d155c5a.sh, /opt/hl-ops/retour-arriere-code.sh\n'
mountpoint -q $M || mount /dev/sda1 $M
grep -q '^hl-devin:' $M/etc/passwd; [ -d "$OPS" ]; [ -f "$SUD" ]
valide() { chroot $M /usr/sbin/visudo -cf "/etc/sudoers.d/$1"; }

if [ "${1:-}" = ANNULER-DROITS ]; then
    A="${2:-}"; [[ "$A" =~ ^$M/root/anciens-scripts-[0-9]{8}-[0-9]{6}$ ]] && [ -s "$A/sudoers.hl-devin.avant" ] || { echo "dossier d'archive invalide"; exit 2; }
    chattr -i "$SUD" 2>/dev/null || true; install -m 440 -o 0 -g 0 "$A/sudoers.hl-devin.avant" "$SUD.tmp"
    if valide hl-devin.tmp; then mv "$SUD.tmp" "$SUD"; chattr +i "$SUD"; sync; echo "DROITS D'ORIGINE REMIS"; exit 0; else rm -f "$SUD.tmp"; chattr +i "$SUD"; echo "droits archivés invalides — rien n'est changé"; exit 1; fi
fi

echo "== A. vérifications (lecture seule)"
sha256sum -c <<'L'
92e2adfe9264203682ba8b9513574e9648ce8bb04dad8a607ae7afc17a0aba06  deploiement-d155c5a.sh
27224d72c5078dcd259413b21ce007f2017e8c39b545b5a81abcbefe844ae377  retour-arriere-code.sh
918b293c015d77f0e7199db43009eb9b88762c7a3d616684b1a8c8e5789ccbbc  diagnostic-root-complet.sh
4943eb1820f4b1aef11a2f969b1cf216b642e3cde546485bc49dcb1221a9d5de  sommes-de-controle.sh
L
for f in $NOUVEAUX; do bash -n "$f"; done
E=$(lire_exp); [ "$E" = "$EXP_AVANT" ] || [ "$E" = "$EXP_FIN" ] || { echo "date d'expiration du compte d'intervention inattendue ($E) — arrêt, rien n'est modifié"; exit 1; }
# Aucune tâche planifiée, aucun service, aucun autre script ne doit appeler un ancien script.
DEP=$(grep -rIlE 'deploiement-code\.sh|retour-arriere\.sh|six-fiches\.sh' $M/etc/cron.d $M/etc/cron.daily $M/etc/cron.hourly $M/etc/cron.weekly $M/etc/cron.monthly $M/etc/crontab $M/var/spool/cron $M/etc/systemd/system $M/lib/systemd/system $M/etc/supervisor $M/usr/local/sbin $M/usr/local/bin $M/etc/sudoers $M/etc/sudoers.d $M/etc/rc.local $M/etc/profile.d $M/root/.bashrc $M/root/.profile $M/opt/hl-ops 2>/dev/null | grep -vE "^($SUD|$OPS/(deploiement-code|retour-arriere|six-fiches|sommes-de-controle)\.sh)$" || true)
[ -z "$DEP" ] || { echo "un ancien script est encore appelé par : $DEP — arrêt, rien n'est modifié"; exit 1; }
echo "aucune tâche planifiée, aucun service, aucun autre script n'appelle les anciens scripts"
printf "$DROITS" > $M/etc/sudoers.d/.hl-devin-essai; chown 0:0 $M/etc/sudoers.d/.hl-devin-essai; chmod 440 $M/etc/sudoers.d/.hl-devin-essai
if valide .hl-devin-essai; then rm -f $M/etc/sudoers.d/.hl-devin-essai; else rm -f $M/etc/sudoers.d/.hl-devin-essai; echo "NOUVEAUX DROITS INVALIDES — rien n'est modifié"; exit 1; fi

echo "== B. installation"
# Reprise : on réutilise l'archive d'une installation interrompue (elle contient les droits d'origine), sinon on en crée une.
ARCH=$(ls -d $M/root/anciens-scripts-*/ 2>/dev/null | while read -r d; do [ -f "$d/termine" ] || echo "${d%/}"; done | tail -1 || true)
if [ -n "$ARCH" ]; then echo "reprise de l'installation interrompue : $ARCH"; else ARCH=$M/root/anciens-scripts-$(date +%Y%m%d-%H%M%S); install -d -m 700 "$ARCH"; fi
J="$ARCH/journal.txt"; note() { echo "$(date +%H:%M:%S) $*" | tee -a "$J"; }
[ -s "$ARCH/sudoers.hl-devin.avant" ] || { cp -a "$SUD" "$ARCH/sudoers.hl-devin.avant"; note "droits d'origine archivés"; }
# 1. Droits : les trois anciens scripts disparaissent de la liste, les nouveaux y entrent.
chattr -i "$SUD" 2>/dev/null || true
printf "$DROITS" > "$SUD.tmp"; chown 0:0 "$SUD.tmp"; chmod 440 "$SUD.tmp"
if valide hl-devin.tmp; then mv "$SUD.tmp" "$SUD"; else rm -f "$SUD.tmp"; chattr +i "$SUD"; echo "DROITS INVALIDES — droits d'origine conservés"; exit 1; fi
chattr +i "$SUD"; note "1 droits mis à jour"
# 2. Anciens scripts : copie d'archive illisible et non exécutable (droits 000, root), puis suppression du fichier d'origine.
for f in $ANCIENS; do
    if [ -e "$OPS/$f" ]; then
        chattr -i "$OPS/$f"; install -m 000 -o 0 -g 0 "$OPS/$f" "$ARCH/$f.retire"
        [ "$(sha256sum < "$OPS/$f")" = "$(sha256sum < "$ARCH/$f.retire")" ] || { echo "archive de $f incomplète — arrêt"; exit 1; }
        rm -f "$OPS/$f"; note "2 ancien script retiré : $f"
    fi
    [ ! -e "$OPS/$f" ] || { echo "ancien script $f toujours présent — arrêt"; exit 1; }
done
# 3. Nouveaux scripts : root seul, exécution seule, non modifiables.
for f in $NOUVEAUX; do
    chattr -i "$OPS/$f" 2>/dev/null || true
    install -m 500 -o 0 -g 0 "$f" "$OPS/$f"; chattr +i "$OPS/$f"; note "3 installé : $f"
done

echo "== C. constat sur les fichiers réellement installés"
for f in $NOUVEAUX; do
    [ "$(sha256sum < "$OPS/$f")" = "$(sha256sum < "$f")" ] || { echo "empreinte de $f différente après installation — arrêt"; exit 1; }
    [ "$(stat -c '%U:%G %a' "$OPS/$f")" = "root:root 500" ] || { echo "droits de $f inattendus — arrêt"; exit 1; }
    lsattr "$OPS/$f" | cut -d' ' -f1 | grep -q i || { echo "$f n'est pas verrouillé — arrêt"; exit 1; }
done
for f in $ANCIENS; do [ ! -e "$OPS/$f" ] || { echo "ancien script $f présent — arrêt"; exit 1; }; done
if grep -qE 'deploiement-code\.sh|/retour-arriere\.sh|six-fiches\.sh' "$SUD"; then echo "un ancien script figure encore dans les droits — arrêt"; exit 1; fi
[ "$(stat -c '%U:%G %a' "$SUD")" = "root:root 440" ] && lsattr "$SUD" | cut -d' ' -f1 | grep -q i || { echo "fichier de droits mal protégé — arrêt"; exit 1; }
for f in "$OPS"/* "$SUD"; do printf '%s  %s  %s  %s\n' "$(sha256sum < "$f" | cut -d' ' -f1)" "$(stat -c '%U:%G %a' "$f")" "$(lsattr "$f" | cut -d' ' -f1 | grep -q i && echo verrouillé || echo NON-VERROUILLÉ)" "${f#$M}"; done
grep -v '^Defaults' "$SUD"
note "installation terminée"; touch "$ARCH/termine"
# 4. Prolongation du compte d'intervention (dernière étape : un échec ici ne remet pas en cause l'installation, il est signalé).
PROL="compte d'intervention valable jusqu'au 20/12/2026 inclus"
if [ "$(lire_exp)" != "$EXP_FIN" ]; then note "4 expiration avant : jour $(lire_exp)"; fixer_exp || true; fi
if [ "$(lire_exp)" = "$EXP_FIN" ]; then note "4 $PROL"; else PROL="PROLONGATION DU COMPTE NON FAITE (expiration inchangée : jour $(lire_exp))"; note "4 $PROL"; fi
[ "$(awk -F: '$1=="hl-devin"' $M/etc/shadow | cut -d: -f2 | cut -c1)" != '$' ] && echo "compte toujours sans mot de passe utilisable"
sync; umount $M
echo "INSTALLATION OK — trois anciens scripts retirés — archive : ${ARCH#$M} — $PROL"
