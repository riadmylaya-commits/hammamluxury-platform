<?php

return [
    'currency' => env('HL_CURRENCY', 'MAD'),
    'timezone' => env('HL_TIMEZONE', 'Africa/Casablanca'),
    'locales' => ['fr', 'en'],

    // Durée de vie d'une intention de réservation signée (ne bloque aucune capacité).
    'intent_ttl_minutes' => (int) env('HL_INTENT_TTL_MINUTES', 15),

    // Délai avant expiration automatique d'une demande en attente non traitée par le partenaire.
    'waiting_ttl_hours' => (float) env('HL_WAITING_TTL_HOURS', 2),

    // Pas de la grille de créneaux et délai minimum avant le début d'une prestation.
    'slot_step_minutes' => (int) env('HL_SLOT_STEP_MINUTES', 30),
    'min_lead_minutes' => (int) env('HL_MIN_LEAD_MINUTES', 60),

    // Nombre maximal de participants par réservation.
    'max_participants' => 20,

    'default_commission_pct' => (float) env('HL_DEFAULT_COMMISSION_PCT', 15),
    'lock_timeout_seconds' => 5,
];
