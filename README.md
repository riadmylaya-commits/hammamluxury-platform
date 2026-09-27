# HammamLuxury Platform

Plateforme de réservation dédiée aux spas, hammams, massages et soins bien-être (FR/EN). Remplace le projet Listeo/WordPress (conservé en archive dans `riadmylaya-commits/hammamluxury`).

Stack : **Laravel 12 · Livewire 3 · Filament 3 · MariaDB/MySQL · PHP 8.3**.

## Trois espaces

| Espace | URL | Rôle |
|---|---|---|
| Client (public) | `/fr`, `/en` | recherche ville/zone, résultats, fiche spa, réservation sans compte, suivi par lien |
| Partenaire | `/partenaire` | espace multi-établissement (Filament, tenant = spa) : profil, photos, horaires, soins/packages/extras, ressources (cabines, thérapeutes…), blocages, réservations |
| Admin | `/admin` | validation partenaires et spas, réservations et commissions, promotions, modération des avis, statistiques |

API JSON `/api/v1` : mêmes services métier que l'interface (voir `docs/api.md`).

## Démarrage local

```bash
composer install            # publie aussi les assets Filament (post-install-cmd)
cp .env.example .env && php artisan key:generate
# créer les bases hl_platform et hl_platform_test (utilisateur hl)
php artisan migrate --seed  # DemoSeeder : Hammam Démo Marrakech + 3 spas fictifs
php artisan serve
```

Comptes de démonstration (`HL_DEMO_PASSWORD`, sinon `ChangeMe-Demo-2026!`, local uniquement) :
`admin@hammamluxury.test` (admin) et `owner@hammamluxury.test` (partenaire de la fiche démo).

Tâche planifiée : `php artisan schedule:run` chaque minute (cron) déclenche `hl:expire-waiting` toutes les 5 min (expiration des demandes `waiting` après `HL_WAITING_TTL_HOURS`, 2 h par défaut).

## Qualité

```bash
php artisan test            # suites Unit, Feature, Engine (moteur porté + API + panneaux)
vendor/bin/pint --test      # style de code
```

La CI GitHub Actions (`.github/workflows/ci.yml`) exécute les deux sur MariaDB.

## Documentation

- `docs/architecture.md` — modèle de données, moteur de capacité, cycle de vie d'une réservation, extensibilité
- `docs/api.md` — contrat `/api/v1`
- `docs/deploy-vps.md` — déploiement Hetzner (staging puis production, `hammamluxury.com`)

## Hors périmètre de cette phase

Paiement en ligne (abstraction prévue), application des promotions au devis (CRUD admin seulement), avis clients côté public, statistiques partenaires avancées, design final.
