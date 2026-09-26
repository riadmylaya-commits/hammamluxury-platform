<?php

return [
    'currency' => env('HL_CURRENCY', 'MAD'),
    'timezone' => env('HL_TIMEZONE', 'Africa/Casablanca'),
    'locales' => ['fr', 'en'],

    // Durée de vie d'une intention de réservation signée (ne bloque aucune capacité).
    'intent_ttl_minutes' => (int) env('HL_INTENT_TTL_MINUTES', 15),

    // Délai avant expiration automatique d'une demande en attente non traitée par le partenaire.
    'waiting_ttl_hours' => (float) env('HL_WAITING_TTL_HOURS', 2),
    // Délai (minutes) après l'heure de fin avant passage automatique en « terminée ».
    'auto_complete_after_min' => (int) env('HL_AUTO_COMPLETE_AFTER_MIN', 60),

    // Pas de la grille de créneaux et délai minimum avant le début d'une prestation.
    'slot_step_minutes' => (int) env('HL_SLOT_STEP_MINUTES', 30),
    'min_lead_minutes' => (int) env('HL_MIN_LEAD_MINUTES', 60),

    // Nombre maximal de participants par réservation.
    'max_participants' => 20,
    'min_photos' => (int) env('HL_MIN_PHOTOS', 10),
    'max_photos' => (int) env('HL_MAX_PHOTOS', 40),
    'geocoder_url' => env('HL_GEOCODER_URL', 'https://nominatim.openstreetmap.org/search'),

    'default_commission_pct' => (float) env('HL_DEFAULT_COMMISSION_PCT', 15),
    'lock_timeout_seconds' => 5,
];
