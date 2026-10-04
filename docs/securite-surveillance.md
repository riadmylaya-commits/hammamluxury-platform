# Sécurité HTTP et surveillance

## En-têtes HTTP (`app/Http/Middleware/SecurityHeaders.php`, toutes les réponses)
| En-tête | Valeur |
|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (HTTPS uniquement ; `HL_HSTS=false` pour désactiver) |
| `Content-Security-Policy[-Report-Only]` | `default-src 'self'` ; scripts/styles `self` + inline/eval (Livewire, Alpine, Filament) + `unpkg.com` (Leaflet) ; polices `fonts.bunny.net` ; images `self`, `data:`, `blob:`, tuiles OpenStreetMap, `picsum.photos` (démo) ; `frame-ancestors 'self'` ; `object-src 'none'` ; `report-uri /csp-report` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | caméra, micro, géolocalisation, paiement, USB désactivés |
| `X-Frame-Options` / `X-Content-Type-Options` | `SAMEORIGIN` / `nosniff` |
| `Cross-Origin-Opener-Policy` | `same-origin` |

`HL_CSP_MODE` : `report` (défaut — les violations sont journalisées dans `storage/logs/csp-*.log` sans bloquer), `enforce` (blocage), `off`. `HL_CSP_EXTRA` ajoute des directives (nouveau CDN…). Passage en `enforce` après au moins une semaine sans violation légitime dans `csp-*.log`. Nginx : `server_tokens off`, `X-Powered-By` masqué.

## Surveillance (`deploy/monitor/`, `install.sh` → `/usr/local/sbin/hl-monitor.sh`, cron toutes les 15 min)
Contrôles : services (nginx, php-fpm, MariaDB, Redis, supervisor/queue, fail2ban, cron), `/up` en HTTPS, base joignable, disque (> 85 %), certificat TLS (< 14 j), **intégrité** des fichiers critiques (`.env`, `public/index.php`, config Nginx/SSH/fail2ban/cron/sudoers, `authorized_keys`, scripts `/usr/local/sbin`), connexions refusées sur les pages de connexion (≥ 30 en 15 min), erreurs applicatives (≥ 5 en 15 min), redémarrage requis.

Alerte e-mail (`hl-alert.sh` → `hl:alert`, cf. `docs/sauvegardes.md`) **une fois par anomalie**, puis « retour à la normale ». Journal : `/var/log/hl-monitor.log` (une ligne par passage). Après une modification légitime d'un fichier surveillé (release, Certbot, changement de config) : `hl-monitor.sh baseline`.

Seuils ajustables dans `/etc/cron.d/hl-monitor` : `HL_DISK_MAX_PCT`, `HL_CERT_MIN_DAYS`, `HL_LOGIN_FAIL_MAX`, `HL_APP_ERR_MAX`, `HL_PUBLIC_URL`.

## Réaction à une alerte
1. Services/HTTP/base : `systemctl status <service>`, `journalctl -u <service> -n 100`, `tail storage/logs/laravel-<date>.log`.
2. Intégrité : comparer le fichier signalé (`diff`), vérifier `last`, `/var/log/auth.log`, `activity_logs` ; si intrusion suspectée → isoler (UFW), changer les clés/mots de passe, restaurer depuis la sauvegarde (`docs/sauvegardes.md`).
3. Connexions refusées : `fail2ban-client status sshd`, IP dans `/var/log/nginx/access.log` ; bannir manuellement si besoin (`fail2ban-client set sshd banip <ip>` ou règle UFW). Cloudflare/WAF prévu avant la production.
