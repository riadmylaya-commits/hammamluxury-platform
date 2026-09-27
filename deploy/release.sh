#!/usr/bin/env bash
# Mise à jour d'une release déjà installée (à lancer en root après un `git push <serveur> HEAD:staging`).
set -euo pipefail
APP=/var/www/hammamluxury
sudo -u deploy -H bash -euo pipefail <<'EOS'
cd /var/www/hammamluxury
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
npm ci --no-audit --no-fund && npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan filament:assets
EOS
systemctl reload php8.3-fpm
supervisorctl restart hl-queue
echo RELEASED
