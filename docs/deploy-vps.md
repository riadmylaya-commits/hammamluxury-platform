# Déploiement VPS (Hetzner)

`www.hammamluxury.com` (site actuel) reste inchangé jusqu'à décision explicite ; la nouvelle plateforme est testée sur `staging.hammamluxury.com`.

## Serveurs

| | Type | Ubuntu | Backups | Nom Hetzner | État |
|---|---|---|---|---|---|
| Staging | CPX22 (2 vCPU AMD, 4 Go, 80 Go) | 24.04 LTS | à activer | `hammamluxury-staging-01` (91.99.97.205) | en service — `staging.hammamluxury.com` |
| Production | CPX32 (4 vCPU AMD, 8 Go, 160 Go) | 24.04 LTS | oui | `hammamluxury-prod-01` (2.28.232.115) | en service — `prod.hammamluxury.com` en attendant la bascule `www`/`@` |

Firewall : entrée TCP 22, 80, 443 uniquement (ufw sur la machine ; le firewall Hetzner peut être ajouté en plus). Pas de load balancer, volume, IP flottante ni base managée.

## Pile logicielle

Nginx 1.24 · PHP 8.3-FPM (`php8.3-{cli,fpm,mysql,mbstring,xml,curl,zip,intl,gd,bcmath,redis,opcache}`) · MariaDB 10.11 · Redis 7 (cache/sessions/queues) · Certbot · Supervisor (queue worker) · Composer 2 · Node 22 (build Vite) · fail2ban.

## Scripts (`deploy/`)

À exécuter en root, dans l'ordre, sur une machine Ubuntu 24.04 vierge :

1. `deploy/01-base.sh` — mises à jour, ufw, fail2ban, utilisateur `deploy` (clé SSH copiée depuis root), sudo limité aux reloads.
2. `deploy/02-stack.sh` — installe la pile, crée la base `hl_platform` et l'utilisateur `hl` (mot de passe généré dans `/root/.hl_db_password`, root uniquement), PHP-FPM sous `deploy`.
3. Pousser le code : `git -C /var/www init -b <branche> hammamluxury` (owner `deploy`, `receive.denyCurrentBranch=updateInstead`), puis depuis le poste : `git push deploy@<ip>:/var/www/hammamluxury HEAD:<branche>` — branche `staging` sur le staging, `main` en production.
4. `deploy/03-app.sh` — crée `.env` (redis, `APP_DEBUG=false`), `composer install`, `npm ci && npm run build`, clé, migrations, caches, `filament:assets`, cron `schedule:run`, Supervisor `hl-queue`, vhost Nginx HTTP. Variables : `HL_ENV`, `HL_URL`, `HL_SERVER_NAME`, `HL_MAIL_FROM` (défauts = staging). Production : `HL_ENV=production HL_URL=https://hammamluxury.com HL_SERVER_NAME="hammamluxury.com www.hammamluxury.com prod.hammamluxury.com" bash 03-app.sh`.
5. Référentiels (obligatoires) : `php artisan db:seed --class=ReferenceSeeder --force`. Données de démonstration (staging uniquement, jamais en production) : `php artisan db:seed --force`.
6. Production : renseigner `MAIL_*` (SMTP `no-reply@hammamluxury.com`) dans `.env`, créer le compte admin (`User` rôle `admin`, mot de passe dans `/root/.hl_admin_password`), puis `config:cache` et redémarrage de `hl-queue`.
7. HTTPS une fois le DNS → IP en place : `certbot --nginx -d <hôte> --redirect -m <email> --agree-tos -n`.
8. Mises à jour suivantes : `git push … HEAD:<branche>` puis `deploy/release.sh`.

## Sécurisation après validation

- Authentification SSH par clé uniquement : `PasswordAuthentication no`, `PermitRootLogin prohibit-password` dans `/etc/ssh/sshd_config.d/`, puis changement du mot de passe root Hetzner.
- Backups Hetzner activés (console) ; en production, `/etc/cron.daily/hl-mysqldump` conserve 14 jours de dumps dans `/var/backups/hl-db`.
- Fait en production : `PasswordAuthentication no`, `PermitRootLogin prohibit-password` (`/etc/ssh/sshd_config.d/99-hl.conf`).

Bascule du domaine principal (`www`/`@` → 2.28.232.115) uniquement après validation sur `prod.hammamluxury.com` ; l'ancien site WordPress reste en place jusque-là.
