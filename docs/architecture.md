# Architecture

## Principe

Monolithe Laravel. Toute la logique métier vit dans `app/Domain` et est consommée à l'identique par :

- les composants Livewire publics (`app/Livewire/Site`) ;
- les panneaux Filament partenaire / admin (`app/Filament`) ;
- l'API JSON (`app/Http/Controllers/Api/V1`).

Il n'existe **qu'un seul calcul** de prix, de durée et de disponibilité : `app/Domain/Booking`.

## Modèle de données (`database/migrations`)

```
users ─┬─ partners ─── spas ─┬─ spa_photos
       │  (société,        ├─ spa_hours            (horaires par jour)
       │   statut, %comm.) ├─ resource_types ── resources   (pool: capacité n / unit: 1 personne)
       │                   ├─ treatments ─┬─ treatment_steps (type de ressource, offset, durée)
       │                   │              └─ extras           (prix, +minutes, par personne, qté max)
       │                   ├─ blocks                (fermetures / indispos ressource ou spa)
       │                   ├─ bookings ─┬─ booking_participants (soin, extras, prix par personne)
       │                   │            ├─ allocations         (ressource × créneau × participant)
       │                   │            └─ booking_events      (journal)
       │                   ├─ reviews               (vérifié si booking_id)
       │                   └─ promotions
       └─ ledger_entries   (commissions, futurs paiements/reversements)
```

Un **package** (« Hammam + Massage ») est un `treatment` à plusieurs `treatment_steps` séquentielles ; le client le voit comme une seule expérience, le moteur réserve chaque ressource à son tour.

## Moteur de capacité (`app/Domain/Booking`)

| Classe | Rôle |
|---|---|
| `QuoteBuilder` | participants[] → items normalisés, prix (solo/couple/groupe + extras), durée totale (étapes + extras) |
| `CapacityEngine` | `needs()` par étape/participant, `check()` d'un horaire (horaires, blocages, allocations actives), `availability()` par pas `HL_SLOT_STEP_MINUTES`, verrou `GET_LOCK` par spa |
| `IntentService` | intent signé HMAC (sélection + prix + horaire), TTL `HL_INTENT_TTL_MINUTES`, usage unique |
| `BookingService` | `prepare()` → intent ; `confirmIntent()` revalide sous verrou puis insère booking/participants/allocations ; `accept/decline/cancel` ; `expireWaiting()` idempotent |
| `ContactMasker` | masque téléphone/adresse du spa tant que la réservation n'est pas confirmée |

Les 127 scénarios historiques sont portés dans `tests/Engine` (capacité simple et complexe, concurrence sur le dernier créneau, quote, availability, intent, cycle de vie, expiration, confidentialité), complétés par `ApiV1Test` et `PanelsTest`.

## Cycle de vie d'une réservation

```
intent (15 min, ne bloque rien)
  └─ confirmIntent (verrou) → waiting  ──accept──▶ confirmed ──▶ completed | no_show
                                │      ──decline─▶ declined   (allocations libérées)
                                │      ──cancel──▶ cancelled  (allocations libérées)
                                └──── > HL_WAITING_TTL_HOURS ─▶ expired (allocations libérées)
```

## Multi-tenant partenaire

Panneau Filament `partner` avec tenant `Spa` : un partenaire ne voit que ses établissements (`User::getTenants`, `canAccessTenant`) ; un admin accède à tous. Les ressources Filament sont scoppées par `Filament::getTenant()`.

## Publication d'un établissement (`app/Domain/Catalogue/PublicationChecklist`)

Une fiche ne devient `published` (visible côté client et API) que si toutes les conditions sont remplies — la checklist est **bloquante**, dans l'action « Publier » comme dans le formulaire d'édition admin :

- nom, catégorie, ville et description FR ; adresse et téléphone ;
- au moins `HL_MIN_PHOTOS` photos (10 par défaut) ;
- horaires renseignés ; au moins un soin actif avec tarif ;
- au moins une ressource active, et chaque étape des soins actifs couverte par un type de ressource ayant une ressource active ;
- partenaire validé.

## Extensibilité prévue

- **Paiement / acompte** : `ledger_entries` + point d'entrée unique `BookingService::confirmIntent` ; ajout d'un `PaymentGateway` sans toucher au moteur.
- **Promotions** : table et CRUD admin présents ; l'application au devis se fera dans `QuoteBuilder` (unique point de calcul).
- **Avis vérifiés** : `reviews.booking_id` + modération admin + recalcul `spas.rating`.
- **Statistiques / mobile** : `/api/v1` expose déjà catalogue, devis, disponibilités et réservation.
