# Sauvegardes et restauration (restic)

Scripts : `deploy/backup/` (installés dans `/usr/local/sbin/` par `install.sh`). Dépôt restic **chiffré** (AES-256, mot de passe dans `/etc/hl-backup/restic.password`, à conserver aussi dans un gestionnaire de mots de passe : sans lui, rien n'est restaurable).

## Ce qui est sauvegardé (quotidien, 03:15 UTC, cron `/etc/cron.d/hl-backup`)
| Tag | Contenu |
|---|---|
| `db` | dump MariaDB complet (`--single-transaction`, routines, triggers, événements), transmis à restic en flux : jamais écrit en clair sur le disque |
| `files` | `storage/app` (photos établissements, photos d'avis, justificatifs privés), `.env` (chiffré dans le dépôt), `/etc/nginx/sites-available`, `/etc/letsencrypt`, `/etc/hl-backup/env`, `/etc/ssh/sshd_config.d`, `/etc/fail2ban/jail.local`, `/etc/cron.d` |

**Exclus** (régénérables ou versionnés dans Git) : code applicatif, `vendor`, `node_modules`, `public/build`, `storage/framework` (caches, sessions), `storage/logs`, `storage/app/public/tmp`.

## Rétention
`restic forget --prune` à chaque exécution : 7 quotidiennes, 4 hebdomadaires, 6 mensuelles (par hôte et par tag). Variables `HL_KEEP_*` dans `/etc/hl-backup/env`.

## Contrôles et alertes
- Après chaque sauvegarde : `restic check --read-data-subset=10%` (intégrité du dépôt + relecture de 10 % des données) ; état écrit dans `/var/lib/hl-backup/last.json` ; journal `/var/log/hl-backup.log`.
- 07:15 UTC, `hl-backup-verify.sh` (indépendant) : exige un instantané `db` **et** `files` de moins de 26 h dans le dépôt distant et un dernier statut `ok`.
- Toute erreur (sauvegarde, contrôle, dépôt inaccessible) → e-mail via `php artisan hl:alert` à `HL_ALERT_EMAIL` (défaut `HL_CONTACT_EMAIL`), SMTP de l'application.

## Installation / changement de destination
```bash
HL_RESTIC_REPOSITORY='sftp:uXXXX@uXXXX.your-storagebox.de:/hl-staging' HL_HOST_TAG=hl-staging bash deploy/backup/install.sh
/usr/local/sbin/hl-backup.sh && /usr/local/sbin/hl-backup-verify.sh
```
Destinations possibles : `sftp:` (Hetzner Storage Box, clé SSH root → storage box), `s3:` (AWS/Scaleway/MinIO, `AWS_ACCESS_KEY_ID/SECRET`), `b2:` (Backblaze). Utiliser un compte/sous-compte dédié, droits limités au dépôt.

## Test de restauration (isolé, sans toucher au serveur en service)
Sur une autre machine disposant de `restic` et d'un MariaDB :
```bash
export RESTIC_REPOSITORY=... RESTIC_PASSWORD_FILE=/chemin/restic.password
deploy/backup/hl-restore.sh /tmp/hl-restore hl_restore_test hl-staging        # ou ids de snapshots
```
Le script restaure fichiers + base dans `/tmp/hl-restore/files` et la base `hl_restore_test`, puis affiche le nombre de tables, `users/partners/spas/bookings/...` et le nombre de fichiers. À réaliser **au moins une fois par trimestre** et après tout changement de destination.

## Procédure de reprise après panne complète du serveur
1. Récupérer : mot de passe restic (gestionnaire de mots de passe), accès à la destination (Storage Box / S3), dépôt Git.
2. Nouveau serveur Ubuntu 24.04 : suivre `docs/deploy-vps.md` (paquets, Nginx, PHP 8.3, MariaDB, Redis, Supervisor, utilisateur `deploy`, clone du dépôt dans `/var/www/hammamluxury`, `composer install`, `npm run build`). Ne pas lancer `migrate --seed`.
3. Installer restic et restaurer vers un dossier isolé :
   `hl-restore.sh /root/restore hl_platform hl-staging` (crée/alimente la base `hl_platform` ; vérifier les compteurs affichés).
4. Remettre en place : `cp -a /root/restore/files/var/www/hammamluxury/storage/app/. /var/www/hammamluxury/storage/app/`, `cp /root/restore/files/var/www/hammamluxury/.env /var/www/hammamluxury/.env` (adapter `DB_PASSWORD` au nouveau MariaDB, créer l'utilisateur `hl`), `chown -R deploy:www-data storage`, `php artisan storage:link`.
5. Nginx/TLS : `cp /root/restore/files/etc/nginx/sites-available/* /etc/nginx/sites-available/`, `/etc/letsencrypt` restauré ou `certbot --nginx` après bascule DNS.
6. `php artisan migrate --force` (applique d'éventuelles migrations postérieures au dump), `php artisan optimize`, redémarrer PHP-FPM, Supervisor (`hl-queue`), cron `deploy`.
7. Vérifier : page d'accueil, connexion Admin (2FA), une fiche établissement avec photos, `/api/v1/spas`, une réservation existante, envoi d'un e-mail.
8. Réinstaller les sauvegardes sur le nouveau serveur (`install.sh` avec la même destination, `HL_HOST_TAG` identique) puis `hl-backup.sh`.
9. Basculer le DNS, puis supprimer le dossier `/root/restore`.

Temps estimé : 1 à 2 h, dépendant surtout de la réinstallation du serveur.
