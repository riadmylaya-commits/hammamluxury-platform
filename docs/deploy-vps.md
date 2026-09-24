# Déploiement VPS (Hetzner)

`www.hammamluxury.com` (site actuel) reste inchangé jusqu'à décision explicite ; la nouvelle plateforme est testée sur `staging.hammamluxury.com`.

## Serveurs

| | Type | Ubuntu | Backups | Nom Hetzner | État |
|---|---|---|---|---|---|
| Staging | CPX22 (2 vCPU AMD, 4 Go, 80 Go) | 24.04 LTS | à activer | `hammamluxury-prod-01` (91.99.97.205) | provisionné |
| Production | CPX31 (4 vCPU, 8 Go, 160 Go) | 24.04 | oui | `hl-prod` | à commander plus tard |

Firewall : entrée TCP 22, 80, 443 uniquement (ufw sur la machine ; le firewall Hetzner peut être ajouté en plus). Pas de load balancer, volume, IP flottante ni base managée.

## Pile logicielle

Nginx 1.24 · PHP 8.3-FPM (`php8.3-{cli,fpm,mysql,mbstring,xml,curl,zip,intl,gd,bcmath,redis,opcache}`) · MariaDB 10.11 · Redis 7 (cache/sessions/queues) · Certbot · Supervisor (queue worker) · Composer 2 · Node 22 (build Vite) · fail2ban.

## Scripts (`deploy/`)

À exécuter en root, dans l'ordre, sur une machine Ubuntu 24.04 vierge :

1. `deploy/01-base.sh` — mises à jour, ufw, fail2ban, utilisateur `deploy` (clé SSH copiée depuis root), sudo limité aux reloads.
2. `deploy/02-stack.sh` — installe la pile, crée la base `hl_platform` et l'utilisateur `hl` (mot de passe généré dans `/root/.hl_db_password`, root uniquement), PHP-FPM sous `deploy`.
3. Pousser le code : `git -C /var/www/hammamluxury init -b staging` (owner `deploy`, `receive.denyCurrentBranch=updateInstead`), puis depuis le poste : `git push deploy@<ip>:/var/www/hammamluxury HEAD:staging`.
4. `deploy/03-app.sh` — crée `.env` (redis, `APP_ENV=staging`, `APP_DEBUG=false`, `HL_DEMO_PASSWORD` généré dans `/root/.hl_demo_password`), `composer install`, `npm ci && npm run build`, clé, migrations, caches, `filament:assets`, cron `schedule:run`, Supervisor `hl-queue`, vhost Nginx HTTP.
5. Données de démonstration : `php artisan db:seed --force` (en `deploy`).
6. HTTPS une fois le DNS `staging` → IP en place : `certbot --nginx -d staging.hammamluxury.com --redirect -m <email> --agree-tos -n`.
7. Mises à jour suivantes : `git push … HEAD:staging` puis `deploy/release.sh`.

## Sécurisation après validation

- Authentification SSH par clé uniquement : `PasswordAuthentication no`, `PermitRootLogin prohibit-password` dans `/etc/ssh/sshd_config.d/`, puis changement du mot de passe root Hetzner.
- Backups Hetzner activés (console) ; `mysqldump` quotidien vers Object Storage à ajouter avant la production.
- `MAIL_MAILER=log` sur staging : les e-mails sont écrits dans `storage/logs/laravel-*.log` tant qu'aucun SMTP n'est configuré.

Bascule production : même procédure sur `hl-prod`, puis changement DNS de `www`/`@` seulement après validation sur staging.
