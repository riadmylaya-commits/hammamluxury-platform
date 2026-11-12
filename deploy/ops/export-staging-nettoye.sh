#!/usr/bin/env bash
# PHASE 3 — préparation (staging, root, lecture seule sur la base) : export des 6 fiches et de la configuration, SANS les demandes de test, journaux, jobs échoués, sessions, caches.
set -euo pipefail
OUT=/root/export-prod-$(date +%Y%m%d-%H%M%S); install -d -m 700 "$OUT"
KEEP="amenities categories cities spas spa_amenities spa_categories spa_hours spa_photos treatments treatment_steps extras resources resource_types blocks partners users"
EXCL="bookings booking_declines booking_events booking_messages booking_notes booking_notifications booking_participants activity_logs failed_jobs jobs job_batches sessions cache cache_locks password_reset_tokens spa_photo_uploads cancellation_requests client_incidents reviews review_photos promotions allocations migrations billing_notifications billing_settings invoices invoice_lines invoice_overpayments invoice_payments invoice_sequences credit_notes credit_note_lines ledger_entries partner_credits partner_credit_applications commission_disputes"
mysqldump --single-transaction --no-create-info --skip-triggers --complete-insert hl_platform $KEEP | gzip > "$OUT/donnees-6-fiches.sql.gz"
tar czf "$OUT/photos.tgz" -C /var/www/hammamluxury/storage/app private/spas 2>/dev/null || tar czf "$OUT/photos.tgz" -C /var/www/hammamluxury/storage/app spas
mysql -N hl_platform -e "CHECKSUM TABLE $(echo $KEEP | sed 's/ /, /g')" > "$OUT/checksums-staging.txt"
mysql -N hl_platform -e "SELECT id, name, is_published FROM spas ORDER BY id" > "$OUT/spas.txt"
( cd "$OUT" && sha256sum * > SHA256SUMS ); echo "Export : $OUT (tables exclues : $EXCL)"; cat "$OUT/spas.txt"
