#!/usr/bin/env bash
# PHASE 3 (production, root) — import des 6 fiches depuis l'export staging nettoyé, puis contrôle par sommes de contrôle. Usage : bash phase3-import-donnees.sh /root/export-prod-<date>
set -euo pipefail
[ -f /root/ACCORD-PHASE-3 ] || { echo "Pas d'accord écrit — arrêt."; exit 1; }
SRC="${1:?dossier d'export}"; APP=/var/www/hammamluxury; DATE=$(date +%Y%m%d-%H%M%S); exec > >(tee -a /root/phase-3-$DATE.log) 2>&1
( cd "$SRC" && sha256sum -c SHA256SUMS )
echo "== C1 base de destination vide ?"; [ "$(mysql -N hl_platform -e 'SELECT COUNT(*) FROM spas')" = "0" ] || { echo "spas non vide — arrêt (aucun écrasement)"; exit 1; }
echo "== C2 sauvegarde"; /usr/local/sbin/hl-backup.sh
echo "== C3 import"; sudo -u deploy -H env HOME=/tmp/deploy-home php $APP/artisan down --retry=60 || true
gunzip -c "$SRC/donnees-6-fiches.sql.gz" | mysql hl_platform
tar xzf "$SRC/photos.tgz" -C $APP/storage/app && chown -R deploy:www-data $APP/storage/app
echo "== C4 sommes de contrôle (doivent être identiques à checksums-staging.txt)"; mysql -N hl_platform -e "CHECKSUM TABLE amenities, categories, cities, spas, spa_amenities, spa_categories, spa_hours, spa_photos, treatments, treatment_steps, extras, resources, resource_types, blocks, partners, users" > /root/checksums-prod-$DATE.txt
diff "$SRC/checksums-staging.txt" /root/checksums-prod-$DATE.txt && echo "6 fiches identiques à la staging"
sudo -u deploy -H env HOME=/tmp/deploy-home php $APP/artisan optimize:clear -q; sudo -u deploy -H env HOME=/tmp/deploy-home php $APP/artisan config:cache -q; sudo -u deploy -H env HOME=/tmp/deploy-home php $APP/artisan up
for u in /fr /fr/spas; do curl -s -o /dev/null -w "$u → %{http_code}\n" "https://prod.hammamluxury.com$u"; done
echo "== Recette interne ensuite (1 h 30) puis vérification de Fatima sur téléphone. Aucune bascule DNS."
