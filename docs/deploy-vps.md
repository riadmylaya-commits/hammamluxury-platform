# Déploiement VPS (Hetzner)

Rien n'est encore commandé ni déployé. `www.hammamluxury.com` (site actuel) reste inchangé jusqu'à décision explicite ; la nouvelle plateforme sera d'abord testée sur `staging.hammamluxury.com`.

## Serveurs

| | Type | Ubuntu | Backups | Nom |
|---|---|---|---|---|
| Production | CPX31 (4 vCPU, 8 Go, 160 Go) | 24.04 | oui | `hl-prod` |
| Staging | CPX21 (3 vCPU, 4 Go, 80 Go) | 24.04 | oui | `hl-staging` |

Firewall Hetzner : entrée TCP 22, 80, 443 uniquement. Pas de load balancer, volume, IP flottante ni base managée.

## Pile logicielle

Nginx · PHP 8.3-FPM (`php8.3-{cli,fpm,mysql,mbstring,xml,curl,zip,intl,gd,bcmath}`) · MariaDB 10.11 · Redis (cache/sessions/queues) · Certbot · Supervisor (queue worker) · Composer · Node LTS (build Vite).

## Étapes

1. Utilisateur `deploy`, clé SSH, `ufw allow 22,80,443`.
2. `git clone` dans `/var/www/hammamluxury` ; `.env` de production (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://staging.hammamluxury.com`, DB, `MAIL_*`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `HL_*`).
3. `composer install --no-dev --optimize-autoloader && npm ci && npm run build`
4. `php artisan key:generate && php artisan migrate --force && php artisan storage:link`
5. `php artisan config:cache route:cache view:cache filament:assets`
6. Cron : `* * * * * php /var/www/hammamluxury/artisan schedule:run` (expiration des waiting).
7. Supervisor : `php artisan queue:work redis --tries=3`.
8. Nginx vhost → `public/`, `client_max_body_size 20m` (photos), Certbot `--nginx -d staging.hammamluxury.com`.
9. Sauvegardes : snapshots Hetzner + `mysqldump` quotidien vers Object Storage.

Bascule production : même procédure sur `hl-prod`, puis changement DNS de `www`/`@` seulement après validation sur staging.
