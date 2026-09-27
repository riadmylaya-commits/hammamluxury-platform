# API `/api/v1`

Publique, sans authentification, limitée à 120 req/min par IP (`RateLimiter 'api'`). Langue : `?locale=fr|en` ou en-tête `Accept-Language`. Montants en MAD.

| Méthode | Route | Description |
|---|---|---|
| GET | `/spas?q=marrakech` | catalogue des spas publiés (photos, prix « à partir de », note) |
| GET | `/spas/{slug}` | fiche : soins, étapes, formules, extras — **sans** téléphone/adresse |
| POST | `/spas/{slug}/quote` | devis : `treatment`, `party`, `extras{id:qty}` ou `participants[]` → `total`, `duration_min`, `items` |
| POST | `/spas/{slug}/availability` | idem + `date=YYYY-MM-DD` → `times[]` réellement réservables |
| POST | `/spas/{slug}/booking-intent` | idem + `time=HH:MM` → intent signé (`expires_at`), 20 req/min |
| POST | `/spas/{slug}/bookings` | `intent`, `first_name`, `last_name`, `email`, `phone`, `hotel?`, `note?` → réservation `waiting`, 10 req/min |
| GET | `/bookings/{manage_token}` | suivi ; coordonnées du spa visibles uniquement si `confirmed` |
| POST | `/bookings/{manage_token}/cancel` | annulation client, libère les allocations |

Erreurs : `422` validation/devis invalide (`errors[]`), `409` indisponible / intent rejoué / transition impossible (`reason`), `410` intent expiré ou invalide, `404` spa non publié ou token inconnu.
