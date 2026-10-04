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
    'auto_complete_after_min' => (int) env('HL_AUTO_COMPLETE_AFTER_MIN', 240),

    // Pas de la grille de créneaux et délai minimum avant le début d'une prestation.
    'slot_step_minutes' => (int) env('HL_SLOT_STEP_MINUTES', 30),
    'min_lead_minutes' => (int) env('HL_MIN_LEAD_MINUTES', 60),

    // Nombre maximal de participants par réservation.
    'max_participants' => 20,
    'min_photos' => (int) env('HL_MIN_PHOTOS', 10),
    'max_photos' => (int) env('HL_MAX_PHOTOS', 40),
    'geocoder_url' => env('HL_GEOCODER_URL', 'https://nominatim.openstreetmap.org/search'),

    // Formulaire Contact public : adresse de réception.
    'contact_email' => env('HL_CONTACT_EMAIL', 'admin@hammamluxury.com'),
    // Alertes techniques (sauvegardes, surveillance).
    'alert_email' => env('HL_ALERT_EMAIL', env('HL_CONTACT_EMAIL', 'admin@hammamluxury.com')),
    // Date de dernière mise à jour affichée sur CGU / confidentialité / FAQ.
    'legal_updated_at' => env('HL_LEGAL_UPDATED_AT', '2026-09-24'),

    // Fonctionnalités mises en attente (code et données conservés).
    'features' => [
        'promotions' => (bool) env('HL_FEATURE_PROMOTIONS', false),
        // Tarif Non remboursable (réduction ≥ 10 % sur le tarif standard) : désactivé, seul le tarif Standard est proposé.
        'non_refundable' => (bool) env('HL_FEATURE_NON_REFUNDABLE', false),
    ],
    'security' => [
        // Double authentification TOTP exigée pour tout compte administrateur.
        'admin_2fa_required' => (bool) env('HL_ADMIN_2FA_REQUIRED', true),
    ],
    'default_commission_pct' => (float) env('HL_DEFAULT_COMMISSION_PCT', 15),
    'lock_timeout_seconds' => 5,
];
