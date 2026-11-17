#!/usr/bin/env bash
# Transfert des SIX fiches validées (31, 34, 35, 36, 37, 38) de la staging vers la production — et rien d'autre :
# ni comptes, ni fiche de test, ni réservations, ni journaux. Root.
#   six-fiches.sh dump-sql      → écrit sur la sortie standard les données des six fiches (LECTURE SEULE, staging ou production)
#   six-fiches.sh migrations    → liste des migrations appliquées (lecture seule)
#   six-fiches.sh photos-list   → empreintes des fichiers photo des six fiches (lecture seule)
#   six-fiches.sh import <dir>  → PRODUCTION : importe <dir> (donnees.sql, photos.tar, migrations.txt, photos.sha256, SHA256SUMS)
#                                 dans une base SANS fiche, en une transaction, puis prouve l'identité avec la staging.
set -euo pipefail
DB=hl_platform; APP=/var/www/hammamluxury; IDS="31,34,35,36,37,38"; PARTNER=29; ADMIN=1
STORE=$APP/storage/app/private
q() { mariadb -N -B "$DB" -e "$1"; }
d() { mariadb-dump --single-transaction --no-create-info --skip-triggers --complete-insert --compact --skip-extended-insert --order-by-primary --hex-blob --default-character-set=utf8mb4 --no-tablespaces "$@" | { grep -v '^/\*M!999999' || true; }; }
dump_sql() {
  echo "SET NAMES utf8mb4;"; echo "START TRANSACTION;"
  d --replace "$DB" cities amenities categories
  d "$DB" partners --where="id=$PARTNER AND user_id=$ADMIN"
  d "$DB" spas --where="id IN ($IDS) AND partner_id=$PARTNER AND manager_user_id IS NULL"
  for t in resource_types resources treatments; do d "$DB" $t --where="spa_id IN ($IDS)"; done
  d "$DB" treatment_steps --where="treatment_id IN (SELECT id FROM treatments WHERE spa_id IN ($IDS))"
  for t in extras spa_amenities spa_categories spa_hours spa_photos; do d "$DB" $t --where="spa_id IN ($IDS)"; done
  d "$DB" blocks --where="spa_id IN ($IDS) AND (created_by IS NULL OR created_by=$ADMIN)"
  echo "COMMIT;"
}
photos_list() { ( cd "$STORE" && for i in ${IDS//,/ }; do [ -d "spas/$i" ] && find "spas/$i" -type f -print0; done | sort -z | xargs -0 -r sha256sum ); }
case "${1:-}" in
  dump-sql) dump_sql ;;
  migrations) q "SELECT migration FROM migrations ORDER BY migration" ;;
  photos-list) photos_list ;;
  import)
    SRC="${2:?dossier export requis}"; DATE=$(date +%Y%m%d-%H%M%S); W=$(mktemp -d /root/six-fiches-XXXXXX); trap 'rm -rf "$W"' EXIT
    exec > >(tee -a "/root/six-fiches-$DATE.log") 2>&1
    echo "== 1 copie de l'export en zone root + intégrité"
    for f in donnees.sql photos.tar migrations.txt photos.sha256 SHA256SUMS; do [ -f "$SRC/$f" ] && [ ! -L "$SRC/$f" ]; cp "$SRC/$f" "$W/$f"; done
    ( cd "$W" && sha256sum -c SHA256SUMS )
    echo "== 2 contenu autorisé seulement"
    BAD=$({ grep -v -E '^(SET NAMES utf8mb4;|START TRANSACTION;|COMMIT;|REPLACE INTO `(cities|amenities|categories)` \(|INSERT INTO `(partners|spas|resource_types|resources|treatments|treatment_steps|extras|spa_amenities|spa_categories|spa_hours|spa_photos|blocks)` \()' "$W/donnees.sql" || true; } | wc -l)
    [ "$BAD" = 0 ] || { echo "instructions non autorisées dans donnees.sql : $BAD — arrêt"; exit 1; }
    [ "$(grep -c '^INSERT INTO `spas`' "$W/donnees.sql")" = 6 ] || { echo "il faut exactement 6 fiches — arrêt"; exit 1; }
    if grep '^INSERT INTO `spas`' "$W/donnees.sql" | grep -v -E 'VALUES \((31|34|35|36|37|38),' | grep -q .; then echo "fiche hors des six validées — arrêt"; exit 1; fi
    if grep '^INSERT INTO `partners`' "$W/donnees.sql" | grep -v -E 'VALUES \(29,1,' | grep -q .; then echo "partenaire inattendu — arrêt"; exit 1; fi
    [ "$(grep -c '^INSERT INTO `partners`' "$W/donnees.sql")" = 1 ]
    if tar tf "$W/photos.tar" | grep -v -E '^spas/(31|34|35|36|37|38)/[A-Za-z0-9._-]+$|^spas/(31|34|35|36|37|38)/$' | grep -q .; then echo "chemins non autorisés dans photos.tar — arrêt"; exit 1; fi
    if tar tvf "$W/photos.tar" | grep -v -E '^[-d]' | grep -q .; then echo "photos.tar : seuls fichiers et dossiers autorisés — arrêt"; exit 1; fi
    echo "== 3 production prête ? (aucune fiche, même schéma, administrateur présent)"
    for t in spas partners treatments spa_photos resources resource_types extras bookings; do [ "$(q "SELECT COUNT(*) FROM $t")" = 0 ] || { echo "table $t non vide — arrêt, aucun écrasement"; exit 1; }; done
    [ "$(q "SELECT role FROM users WHERE id=$ADMIN")" = admin ] || { echo "utilisateur $ADMIN non administrateur — arrêt"; exit 1; }
    q "SELECT migration FROM migrations ORDER BY migration" | diff -q - "$W/migrations.txt" >/dev/null || { echo "schéma différent de la staging (migrations) — arrêt"; exit 1; }
    for i in 31 34 35 36 37 38; do [ ! -e "$STORE/spas/$i" ] || { echo "dossier photos spas/$i déjà présent — arrêt"; exit 1; }; done
    echo "== 4 sauvegardes avant import"; install -d -m 700 "/root/pre-six-fiches-$DATE"
    mariadb-dump --single-transaction --routines --triggers "$DB" | gzip > "/root/pre-six-fiches-$DATE/db.sql.gz"; [ -s "/root/pre-six-fiches-$DATE/db.sql.gz" ]
    /usr/local/sbin/hl-backup.sh
    echo "== 5 import (une transaction : tout ou rien)"
    mariadb --default-character-set=utf8mb4 "$DB" < "$W/donnees.sql"
    echo "== 6 photos"; install -d -o deploy -g deploy -m 700 "$STORE" "$STORE/spas"
    tar xpf "$W/photos.tar" -C "$STORE" --no-same-owner
    for i in 31 34 35 36 37 38; do chown -R deploy:deploy "$STORE/spas/$i"; done
    echo "== 7 preuves d'identité avec la staging"
    dump_sql > "$W/relu.sql"; cmp "$W/donnees.sql" "$W/relu.sql" && echo "données : identiques à la staging"
    photos_list > "$W/relu.sha256"; cmp "$W/photos.sha256" "$W/relu.sha256" && echo "photos : identiques à la staging ($(wc -l < "$W/relu.sha256") fichiers)"
    M=0; while read -r p; do [ -f "$STORE/$p" ] || { echo "photo absente : $p"; M=1; }; done < <(q "SELECT path FROM spa_photos"); [ "$M" = 0 ] && echo "chaque photo référencée existe"
    NS=$(q "SELECT COUNT(*) FROM spas"); NP=$(q "SELECT COUNT(*) FROM spas WHERE status='published'"); NT=$(q "SELECT COUNT(*) FROM treatments"); NF=$(q "SELECT COUNT(*) FROM spa_photos"); NU=$(q "SELECT COUNT(*) FROM users"); NB=$(q "SELECT COUNT(*) FROM bookings")
    echo "fiches=$NS publiées=$NP soins=$NT photos=$NF comptes=$NU réservations=$NB"
    sudo -u deploy -H env HOME=/tmp/deploy-home php "$APP/artisan" optimize:clear -q || true
    sudo -u deploy -H env HOME=/tmp/deploy-home php "$APP/artisan" config:cache -q || true
    [ "$M" = 0 ] && echo "IMPORT SIX FICHES OK — journal /root/six-fiches-$DATE.log" || exit 1 ;;
  *) echo "usage : six-fiches.sh dump-sql | migrations | photos-list | import <dossier>"; exit 2 ;;
esac
