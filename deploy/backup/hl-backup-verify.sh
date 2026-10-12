#!/usr/bin/env bash
# Contrôle indépendant (cron, quelques heures après la sauvegarde) : un instantané db ET files de moins de MAX_AGE_H heures
# doit exister dans le dépôt distant, sinon alerte. Vérifie aussi que le dépôt reste lisible.
set -euo pipefail
set -a; source /etc/hl-backup/env; set +a
HOST_TAG="${HL_HOST_TAG:-$(hostname -s)}"
MAX_AGE_H="${HL_MAX_AGE_H:-26}"
fail() { "$(dirname "$0")/hl-alert.sh" "Sauvegarde MANQUANTE ou invalide sur $HOST_TAG" "$1"; echo "ALERTE : $1"; exit 1; }

for tag in db files; do
  json=$(restic snapshots --host "$HOST_TAG" --tag "$tag" --latest 1 --json 2>/dev/null) || fail "Dépôt restic inaccessible ($RESTIC_REPOSITORY)."
  age=$(python3 -c '
import sys,json,datetime
s=json.loads(sys.argv[1])
if not s: print(10**6); sys.exit()
t=datetime.datetime.fromisoformat(s[-1]["time"].split(".")[0]+"+00:00" if "+" not in s[-1]["time"] and "Z" in s[-1]["time"] else s[-1]["time"][:19]+s[-1]["time"][-6:])
print(int((datetime.datetime.now(datetime.timezone.utc)-t).total_seconds()//60))' "$json")
  [ "$age" -le $((MAX_AGE_H*60)) ] || fail "Dernier instantané « $tag » vieux de $((age/60)) h $((age%60)) min (max ${MAX_AGE_H} h)."
done
status=$(python3 -c 'import json;print(json.load(open("/var/lib/hl-backup/last.json"))["status"])' 2>/dev/null || echo unknown)
[ "$status" = ok ] || fail "Dernière exécution de hl-backup.sh : statut « $status »."
echo "$(date -Is) vérification OK (db et files < ${MAX_AGE_H} h, dernier il y a $((age/60)) h $((age%60)) min)"
