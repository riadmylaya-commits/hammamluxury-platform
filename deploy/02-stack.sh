#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

apt-get install -y -q nginx mariadb-server redis-server supervisor certbot python3-certbot-nginx \
  php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-gd php8.3-bcmath php8.3-redis php8.3-opcache

# Composer
if ! command -v composer >/dev/null; then
  curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
  rm -f /tmp/composer-setup.php
fi

# Node 22 LTS (NodeSource)
if ! command -v node >/dev/null; then
  install -d /etc/apt/keyrings
  curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
  echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" >/etc/apt/sources.list.d/nodesource.list
  apt-get update -q && apt-get install -y -q nodejs
fi

# PHP tuning
PHPINI=/etc/php/8.3/fpm/php.ini
sed -i 's/^upload_max_filesize.*/upload_max_filesize = 20M/; s/^post_max_size.*/post_max_size = 25M/; s/^memory_limit.*/memory_limit = 256M/; s/^;\?date.timezone.*/date.timezone = UTC/' $PHPINI
sed -i 's/^user = www-data/user = deploy/; s/^group = www-data/group = deploy/' /etc/php/8.3/fpm/pool.d/www.conf
usermod -aG deploy www-data

# Redis: local only (default), memory cap
sed -i 's/^# maxmemory <bytes>/maxmemory 256mb/; s/^# maxmemory-policy noeviction/maxmemory-policy allkeys-lru/' /etc/redis/redis.conf

# MariaDB: DB + user (password generated server-side, stored root-only)
DBPASS_FILE=/root/.hl_db_password
if [ ! -f $DBPASS_FILE ]; then
  openssl rand -base64 30 | tr -d '/+=' | head -c 32 >$DBPASS_FILE
  chmod 600 $DBPASS_FILE
fi
DBPASS=$(cat $DBPASS_FILE)
mysql <<SQL
CREATE DATABASE IF NOT EXISTS hl_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'hl'@'localhost' IDENTIFIED BY '${DBPASS}';
ALTER USER 'hl'@'localhost' IDENTIFIED BY '${DBPASS}';
GRANT ALL PRIVILEGES ON hl_platform.* TO 'hl'@'localhost';
FLUSH PRIVILEGES;
SQL
# harden: remove anonymous/test
mysql -e "DELETE FROM mysql.user WHERE User=''; DROP DATABASE IF EXISTS test; FLUSH PRIVILEGES;"

rm -f /etc/nginx/sites-enabled/default
install -d -o deploy -g deploy /var/www

systemctl enable --now nginx php8.3-fpm mariadb redis-server supervisor
systemctl restart php8.3-fpm redis-server

echo "== versions"
nginx -v 2>&1; php -v | head -1; mariadb --version; redis-server --version | cut -d' ' -f1-3; composer --version 2>/dev/null | head -1; node -v; npm -v; certbot --version
systemctl is-active nginx php8.3-fpm mariadb redis-server supervisor
