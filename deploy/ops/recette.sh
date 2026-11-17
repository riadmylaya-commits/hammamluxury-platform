#!/usr/bin/env bash
# RECETTE EN PRODUCTION (root) — actions strictement limitées, chacune nommée :
#   recette.sh directeur    → inscrit le directeur de la publication (pages légales FR/EN), rien d'autre dans les réglages
#   recette.sh nettoyer-qa  → retire les 3 lignes de référence de test « qa-phase1-… » (inactives, inutilisées) venues de la staging
#   recette.sh empreintes   → ré-enregistre les empreintes de la surveillance après les interventions autorisées
#   recette.sh ouvrir       → sauvegarde, mode maintenance (site fermé au public), compte administrateur temporaire, établissement FICTIF publié
#   recette.sh file         → traite la file d'attente (e-mails) pendant la maintenance
#   recette.sh etat         → état des demandes de test, des notifications et des erreurs (lecture seule)
#   recette.sh fermer       → supprime tout ce que la recette a créé (retour exact à la base d'avant), retire le compte temporaire, rouvre le site
# Ne publie aucune des six fiches, n'active pas la facturation, ne touche ni au DNS ni aux e-mails du domaine.
set -euo pipefail
APP=/var/www/hammamluxury; DB=hl_platform; SIX="31,34,35,36,37,38"; MARK=/root/recette-en-cours
ADMIN_EMAIL=hammamluxury26+recette@gmail.com; TEST_SLUG=test-hammamluxury-recette; DIRECTEUR="Ali JAMALI"
STORE=$APP/storage/app/private
q() { mariadb -N -B "$DB" -e "$1"; }
A() { sudo -u deploy -H env HOME=/tmp/deploy-home php "$APP/artisan" "$@"; }
caches() { A optimize:clear -q; A config:cache -q; A route:cache -q; A view:cache -q; A event:cache -q; }
install -d -o deploy -g deploy -m 700 /tmp/deploy-home
case "${1:-}" in
  directeur)
    DATE=$(date +%Y%m%d-%H%M%S); cp -a "$APP/.env" "/root/env-avant-directeur-$DATE"; chmod 600 "/root/env-avant-directeur-$DATE"
    OWN=$(stat -c '%U:%G' "$APP/.env"); MOD=$(stat -c '%a' "$APP/.env"); L="HL_LEGAL_PUBLICATION_DIRECTOR=\"$DIRECTEUR\""
    if grep -q '^HL_LEGAL_PUBLICATION_DIRECTOR=' "$APP/.env"; then L="$L" perl -i -pe 'BEGIN{$l=$ENV{L}} $_="$l\n" if /^HL_LEGAL_PUBLICATION_DIRECTOR=/' "$APP/.env"; else printf '%s\n' "$L" >> "$APP/.env"; fi
    chown "$OWN" "$APP/.env"; chmod "$MOD" "$APP/.env"
    [ "$(diff <(grep -v '^HL_LEGAL_PUBLICATION_DIRECTOR=' "/root/env-avant-directeur-$DATE") <(grep -v '^HL_LEGAL_PUBLICATION_DIRECTOR=' "$APP/.env") | wc -l)" = 0 ] || { echo "autre réglage modifié — restauration"; cp -a "/root/env-avant-directeur-$DATE" "$APP/.env"; exit 1; }
    A config:cache -q; systemctl reload php8.3-fpm
    echo "DIRECTEUR OK : $DIRECTEUR (aucun autre réglage modifié)" ;;
  nettoyer-qa)
    [ "$(q "SELECT COUNT(*) FROM spa_categories sc JOIN categories c ON c.id=sc.category_id WHERE c.slug='qa-phase1-experience'")" = 0 ]
    [ "$(q "SELECT COUNT(*) FROM spa_amenities sa JOIN amenities a ON a.id=sa.amenity_id WHERE a.slug='qa-phase1-equipement'")" = 0 ]
    [ "$(q "SELECT COUNT(*) FROM spas s JOIN cities c ON c.id=s.city_id WHERE c.slug='qa-phase1-ville'")" = 0 ]
    q "DELETE FROM categories WHERE slug='qa-phase1-experience'; DELETE FROM amenities WHERE slug='qa-phase1-equipement'; DELETE FROM cities WHERE slug='qa-phase1-ville';"
    echo "NETTOYAGE QA OK : catégories=$(q 'SELECT COUNT(*) FROM categories') équipements=$(q 'SELECT COUNT(*) FROM amenities') villes=$(q 'SELECT COUNT(*) FROM cities') ; lignes qa restantes=$(q "SELECT (SELECT COUNT(*) FROM categories WHERE slug LIKE 'qa-%')+(SELECT COUNT(*) FROM amenities WHERE slug LIKE 'qa-%')+(SELECT COUNT(*) FROM cities WHERE slug LIKE 'qa-%')")" ;;
  empreintes)
    HL_APP_DIR=$APP /usr/local/sbin/hl-monitor.sh baseline ;;
  ouvrir)
    [ ! -e "$MARK" ] || { echo "une recette est déjà ouverte — arrêt"; exit 1; }
    [ "$(q 'SELECT COUNT(*) FROM bookings')" = 0 ] && [ "$(q "SELECT COUNT(*) FROM spas WHERE status='published'")" = 0 ] && [ "$(q "SELECT COUNT(*) FROM spas WHERE id IN ($SIX) AND status='draft'")" = 6 ] && [ "$(q 'SELECT COUNT(*) FROM spas')" = 6 ] || { echo "état de départ inattendu (réservations ou fiches publiées) — arrêt"; exit 1; }
    [ "$(q "SELECT COUNT(*) FROM users WHERE email='$ADMIN_EMAIL'")" = 0 ] || { echo "compte temporaire déjà présent — arrêt"; exit 1; }
    DATE=$(date +%Y%m%d-%H%M%S); PRE=/root/pre-recette-$DATE; install -d -m 700 "$PRE"
    mariadb-dump --single-transaction --routines --triggers "$DB" | gzip > "$PRE/db.sql.gz"; gunzip -t "$PRE/db.sql.gz"
    q "SELECT COUNT(*) FROM users" > "$PRE/comptes"; /usr/local/sbin/hl-backup.sh >/dev/null
    SECRET=$(openssl rand -hex 16); A down --secret="$SECRET" --retry=60 -q; [ -f "$APP/storage/framework/down" ]
    printf 'PRE=%s\n' "$PRE" > "$MARK"; chmod 600 "$MARK"
    PASS=$(openssl rand -base64 18 | tr '+/' 'xy')
    sudo -u deploy -H env HOME=/tmp/deploy-home HLE="$ADMIN_EMAIL" HLP="$PASS" php "$APP/artisan" tinker --execute='\App\Models\User::create(["first_name"=>"Devin","last_name"=>"Recette","email"=>getenv("HLE"),"password"=>\Illuminate\Support\Facades\Hash::make(getenv("HLP")),"role"=>"admin","locale"=>"fr","email_verified_at"=>now()]);' >/dev/null
    [ "$(q "SELECT role FROM users WHERE email='$ADMIN_EMAIL'")" = admin ]
    W=$(mktemp -d /tmp/recette-XXXXXX); chown deploy:deploy "$W"; chmod 700 "$W"
    sudo -u deploy php -r '$d=$argv[1]; foreach([1,2,3] as $i){ $im=imagecreatetruecolor(1200,800); imagefill($im,0,0,imagecolorallocate($im,40+50*$i,90,110)); imagestring($im,5,480,390,"TEST HAMMAMLUXURY ".$i,imagecolorallocate($im,255,255,255)); imagejpeg($im,"$d/test-$i.jpg",85); }' "$W"
    cat > "$W/test.json" <<'J'
{"spa":{"slug":"test-hammamluxury-recette","name":"Test HammamLuxury (établissement fictif)","category":"hammam-massage","city":"Marrakech","area":"Test","address":"Adresse fictive — test technique","phone":"+212600000000","email":"hammamluxury26@gmail.com","description_fr":"Établissement fictif créé pour un test technique. Ne correspond à aucun lieu réel.","description_en":"Fictitious establishment created for a technical test. Not a real place.","categories":["hammam-massage","hammam"],"amenities":[],"hours":[{"weekday":0,"opens":"09:00","closes":"21:00"},{"weekday":1,"opens":"09:00","closes":"21:00"},{"weekday":2,"opens":"09:00","closes":"21:00"},{"weekday":3,"opens":"09:00","closes":"21:00"},{"weekday":4,"opens":"09:00","closes":"21:00"},{"weekday":5,"opens":"09:00","closes":"21:00"},{"weekday":6,"opens":"09:00","closes":"21:00"}],"capacity":{"hammam_capacity":6,"massage_cabins":3,"therapists":3,"cabin_buffer_min":10},"cancellation_hours":24},
"extras":[{"code":"TX","name_fr":"Option de test","name_en":"Test option","price":100,"extra_min":15,"per_person":true,"max_qty":1,"applies_to":"hammam"}],
"treatments":[
{"code":"T1","category":"hammam","name_fr":"Hammam de test 45 mn","name_en":"Test hammam 45 mn","description_fr":"Soin fictif.","description_en":"Fictitious treatment.","price_per_person":300,"party_min":1,"party_max":6,"steps":[{"kind":"hammam","label_fr":"Hammam","label_en":"Hammam","duration_min":45}],"extras":["TX"]},
{"code":"T2","category":"massage","name_fr":"Massage de test 60 mn","name_en":"Test massage 60 mn","description_fr":"Soin fictif.","description_en":"Fictitious treatment.","price_per_person":400,"party_min":1,"party_max":3,"steps":[{"kind":"massage","label_fr":"Massage","label_en":"Massage","duration_min":60}],"extras":[]},
{"code":"T3","category":"ritual","name_fr":"Rituel de test hammam et massage 1 h 45","name_en":"Test ritual hammam and massage 1 h 45","description_fr":"Soin fictif.","description_en":"Fictitious treatment.","price_per_person":650,"party_min":1,"party_max":3,"steps":[{"kind":"hammam","label_fr":"Hammam","label_en":"Hammam","duration_min":45},{"kind":"massage","label_fr":"Massage","label_en":"Massage","duration_min":60}],"extras":["TX"]}],
"photos":["test-1.jpg","test-2.jpg","test-3.jpg"]}
J
    chown deploy:deploy "$W/test.json"
    A hl:import-spa "$W/test.json" --portfolio="$ADMIN_EMAIL" --photos-dir="$W" --publish > "$W/import.json"; rm -rf "$W"
    TID=$(q "SELECT id FROM spas WHERE slug='$TEST_SLUG'"); [[ "$TID" =~ ^[0-9]+$ ]]
    q "UPDATE spas t JOIN spas s ON s.id=31 SET t.listing_type=s.listing_type, t.managed_by=s.managed_by, t.instant_booking=s.instant_booking, t.waiting_ttl_hours=s.waiting_ttl_hours, t.cancellation_hours=s.cancellation_hours, t.min_lead_minutes=s.min_lead_minutes, t.slot_step_minutes=s.slot_step_minutes, t.info_verified_at=s.info_verified_at WHERE t.id=$TID"
    printf 'TID=%s\n' "$TID" >> "$MARK"; caches; [ -f "$APP/storage/framework/down" ]
    echo "RECETTE OUVERTE — site en maintenance ; fiches publiées : $(q "SELECT COUNT(*) FROM spas WHERE status='published'") (l'établissement fictif n° $TID) ; six fiches en brouillon : $(q "SELECT COUNT(*) FROM spas WHERE id IN ($SIX) AND status='draft'")"
    echo "type de fiche du test : $(q "SELECT CONCAT(listing_type,' / ',status) FROM spas WHERE id=$TID")"
    echo "ACCES lien=https://prod.hammamluxury.com/$SECRET compte=$ADMIN_EMAIL mot_de_passe=$PASS fiche=/fr/spa/$TEST_SLUG" ;;
  file)
    A queue:work redis --force --stop-when-empty --tries=3 --max-time=120 2>&1 | tail -15 ;;
  etat)
    echo "maintenance : $([ -f "$APP/storage/framework/down" ] && echo oui || echo non) ; recette ouverte : $([ -e "$MARK" ] && echo oui || echo non)"
    echo "fiches : $(q "SELECT GROUP_CONCAT(CONCAT(status,'=',n) SEPARATOR ' ') FROM (SELECT status,COUNT(*) n FROM spas GROUP BY status) x") ; comptes : $(q 'SELECT COUNT(*) FROM users')"
    echo "-- demandes"; q "SELECT id, reference, spa_id, status, DATE_FORMAT(created_at,'%H:%i:%s') FROM bookings ORDER BY id"
    echo "-- notifications (demande, événement, destinataire, état, essais, erreur)"; q "SELECT booking_id, event, audience, status, attempts, IFNULL(LEFT(error,120),'') FROM booking_notifications ORDER BY id"
    echo "-- tâches en échec : $(q 'SELECT COUNT(*) FROM failed_jobs')"; q "SELECT LEFT(exception,200) FROM failed_jobs ORDER BY id DESC LIMIT 3"
    echo "-- erreurs applicatives du jour : $(grep -c -E "^\[$(date +%Y-%m-%d)[^]]*\] production\.(ERROR|CRITICAL)" "$APP/storage/logs/laravel.log" 2>/dev/null || true)"
    grep -E "^\[$(date +%Y-%m-%d)[^]]*\] production\.(ERROR|CRITICAL)" "$APP/storage/logs/laravel.log" 2>/dev/null | tail -5 | cut -c1-260 || true
    echo "-- surveillance"; tail -n 6 /var/log/hl-monitor.log 2>/dev/null || true ;;
  fermer)
    [ -e "$MARK" ] || { echo "aucune recette ouverte"; exit 1; }
    PRE=$(sed -n 's/^PRE=//p' "$MARK"); TID=$(sed -n 's/^TID=//p' "$MARK")
    [[ "$PRE" =~ ^/root/pre-recette-[0-9]{8}-[0-9]{6}$ ]] && [ -s "$PRE/db.sql.gz" ] && gunzip -t "$PRE/db.sql.gz"
    echo "== 1 garde-fous : seules des données de test ont été créées"
    [ "$(q "SELECT COUNT(*) FROM spas WHERE id IN ($SIX) AND status='draft'")" = 6 ] || { echo "une des six fiches n'est plus en brouillon — arrêt, rien n'est supprimé"; exit 1; }
    if [ -n "$TID" ]; then
      [ "$(q "SELECT COUNT(*) FROM spas WHERE id NOT IN ($SIX,$TID)")" = 0 ] && [ "$(q "SELECT COUNT(*) FROM bookings WHERE spa_id<>$TID")" = 0 ] || { echo "données hors test détectées — arrêt, rien n'est supprimé"; exit 1; }
    else [ "$(q "SELECT COUNT(*) FROM spas WHERE id NOT IN ($SIX)")" = 0 ] && [ "$(q 'SELECT COUNT(*) FROM bookings')" = 0 ] || { echo "données hors test détectées — arrêt"; exit 1; }; fi
    [ "$(q "SELECT COUNT(*) FROM users WHERE email<>'$ADMIN_EMAIL'")" = "$(cat "$PRE/comptes")" ] || { echo "un compte a été créé ou retiré hors recette — arrêt, rien n'est supprimé"; exit 1; }
    echo "== 2 maintenance maintenue, file vidée"; [ -f "$APP/storage/framework/down" ] || A down --retry=60 -q
    A queue:clear redis --force -q || true
    echo "== 3 retour exact à la base d'avant la recette"
    mariadb -e "DROP DATABASE \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"; gunzip -c "$PRE/db.sql.gz" | mariadb "$DB"
    if [ -n "$TID" ] && [ -d "$STORE/spas/$TID" ]; then case ",$SIX," in *",$TID,"*) echo "identifiant de test invalide"; exit 1;; esac; rm -rf "${STORE:?}/spas/$TID"; fi
    A cache:clear -q || true; caches
    echo "== 4 contrôles"
    [ "$(q "SELECT COUNT(*) FROM users WHERE email='$ADMIN_EMAIL'")" = 0 ] && [ "$(q 'SELECT COUNT(*) FROM bookings')" = 0 ] && [ "$(q "SELECT COUNT(*) FROM spas WHERE status='published'")" = 0 ] && [ "$(q "SELECT COUNT(*) FROM spas WHERE id IN ($SIX) AND status='draft'")" = 6 ] && [ "$(q 'SELECT COUNT(*) FROM spas')" = 6 ]
    A up -q; rm -f "$MARK"; systemctl reload php8.3-fpm; supervisorctl restart hl-queue >/dev/null || true
    /usr/local/sbin/hl-backup.sh >/dev/null || echo "sauvegarde externe à relancer"
    echo "RECETTE FERMEE — comptes=$(q 'SELECT COUNT(*) FROM users') fiches=$(q 'SELECT COUNT(*) FROM spas') publiées=$(q "SELECT COUNT(*) FROM spas WHERE status='published'") demandes=$(q 'SELECT COUNT(*) FROM bookings') partenaires=$(q 'SELECT COUNT(*) FROM partners') ; site rouvert (toujours non indexable)" ;;
  *) echo "usage : recette.sh directeur | nettoyer-qa | empreintes | ouvrir | file | etat | fermer"; exit 2 ;;
esac
