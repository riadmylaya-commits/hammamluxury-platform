#!/usr/bin/env bash
# Runs as root on the server. Expects code already pushed to /var/www/hammamluxury (git, owner deploy).
set -euo pipefail
APP=/var/www/hammamluxury
DBPASS=$(cat /root/.hl_db_password)
DEMO_FILE=/root/.hl_demo_password
[ -f $DEMO_FILE ] || { openssl rand -base64 18 | tr -d '/+=' | head -c 16 >$DEMO_FILE; chmod 600 $DEMO_FILE; }
DEMOPASS=$(cat $DEMO_FILE)

cd $APP
if [ ! -f .env ]; then
  cp .env.example .env
  sed -i "s|^APP_ENV=.*|APP_ENV=staging|; s|^APP_DEBUG=.*|APP_DEBUG=false|; s|^APP_URL=.*|APP_URL=https://staging.hammamluxury.com|" .env
  sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DBPASS}|" .env
  sed -i "s|^SESSION_DRIVER=.*|SESSION_DRIVER=redis|; s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|; s|^CACHE_STORE=.*|CACHE_STORE=redis|" .env
  sed -i "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=\"no-reply@staging.hammamluxury.com\"|" .env
  grep -q '^HL_DEMO_PASSWORD=' .env || echo "HL_DEMO_PASSWORD=${DEMOPASS}" >>.env
  grep -q '^LOG_CHANNEL=' .env && sed -i "s|^LOG_CHANNEL=.*|LOG_CHANNEL=daily|" .env
  chown deploy:deploy .env; chmod 640 .env
fi

sudo -u deploy -H bash -euo pipefail <<'EOS'
cd /var/www/hammamluxury
export COMPOSER_ALLOW_SUPERUSER=0
composer install --no-dev --optimize-autoloader --no-interaction --no-progress 2>&1 | tail -3
npm ci --no-audit --no-fund 2>&1 | tail -2
npm run build 2>&1 | tail -3
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
php artisan storage:link || true
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan filament:assets
EOS

chown -R deploy:deploy $APP
chmod -R ug+rwX $APP/storage $APP/bootstrap/cache
setfacl -R -m u:www-data:rwX -m d:u:www-data:rwX $APP/storage $APP/bootstrap/cache

# Cron (scheduler)
echo "* * * * * cd $APP && php artisan schedule:run >> /dev/null 2>&1" | crontab -u deploy -

# Supervisor queue worker
cat >/etc/supervisor/conf.d/hl-queue.conf <<EOF
[program:hl-queue]
command=php $APP/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
directory=$APP
user=deploy
autostart=true
autorestart=true
stopwaitsecs=60
stdout_logfile=$APP/storage/logs/queue.log
redirect_stderr=true
EOF
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
supervisorctl restart hl-queue >/dev/null || supervisorctl start hl-queue
sleep 2; supervisorctl status hl-queue

# Nginx vhost (HTTP for now; certbot will add TLS)
cat >/etc/nginx/sites-available/hammamluxury <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name staging.hammamluxury.com 91.99.97.205;
    root /var/www/hammamluxury/public;
    index index.php;
    charset utf-8;
    client_max_body_size 25m;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ /\.(?!well-known).* { deny all; }
    location ~* \.(css|js|jpg|jpeg|png|gif|webp|svg|woff2?)$ { expires 30d; access_log off; }
}
EOF
ln -sf /etc/nginx/sites-available/hammamluxury /etc/nginx/sites-enabled/hammamluxury
nginx -t && systemctl reload nginx
echo DONE
