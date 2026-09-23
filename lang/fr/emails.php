<?php

return [
    'created' => [
        'client' => ['subject' => 'Demande de réservation :ref — :spa', 'title' => 'Votre demande :ref est enregistrée', 'intro' => 'Merci :name. :spa va confirmer votre demande dans les meilleurs délais. Vous recevrez un e-mail dès sa réponse.'],
        'partner' => ['subject' => 'Nouvelle demande :ref', 'title' => 'Nouvelle demande de réservation :ref', 'intro' => 'Une nouvelle demande vient d’arriver pour :spa. Connectez-vous à votre espace partenaire pour l’accepter ou la refuser.', 'deadline' => 'Sans réponse sous :hours h, la demande expirera automatiquement et le créneau sera libéré.'],
    ],
    'confirmed' => [
        'client' => ['subject' => 'Réservation confirmée :ref — :spa', 'title' => 'Votre réservation :ref est confirmée', 'intro' => 'Bonne nouvelle :name, :spa a confirmé votre réservation. Les coordonnées de l’établissement sont disponibles sur votre page de suivi.'],
        'partner' => ['subject' => 'Réservation :ref confirmée', 'title' => 'Réservation :ref confirmée', 'intro' => 'Vous avez confirmé cette réservation pour :spa.'],
    ],
    'declined' => [
        'client' => ['subject' => 'Réservation :ref refusée — :spa', 'title' => 'Votre demande :ref n’a pas pu être acceptée', 'intro' => 'Nous sommes désolés :name, :spa ne peut pas honorer cette demande. Vous pouvez choisir un autre créneau ou un autre établissement.'],
        'partner' => ['subject' => 'Réservation :ref refusée', 'title' => 'Réservation :ref refusée', 'intro' => 'Vous avez refusé cette demande pour :spa. Le créneau est libéré.'],
    ],
    'cancelled' => [
        'client' => ['subject' => 'Réservation :ref annulée — :spa', 'title' => 'Réservation :ref annulée', 'intro' => 'Votre réservation chez :spa est annulée.'],
        'partner' => ['subject' => 'Réservation :ref annulée', 'title' => 'Réservation :ref annulée', 'intro' => 'La réservation de :name chez :spa est annulée. Le créneau est libéré.'],
    ],
    'expired' => [
        'client' => ['subject' => 'Demande :ref expirée — :spa', 'title' => 'Votre demande :ref a expiré', 'intro' => 'Désolé :name, :spa n’a pas répondu dans le délai prévu. Aucun montant n’est dû ; vous pouvez renouveler votre demande ou choisir un autre établissement.'],
        'partner' => ['subject' => 'Demande :ref expirée', 'title' => 'Demande :ref expirée sans réponse', 'intro' => 'La demande de :name pour :spa a expiré faute de réponse. Le créneau est libéré. Pensez à traiter vos demandes plus rapidement pour ne pas perdre de clients.'],
    ],
];
