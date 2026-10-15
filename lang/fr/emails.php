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

    'spa' => [
        'reason' => 'Motif',
        'submitted' => ['subject' => 'Nouvelle fiche à valider : :spa', 'title' => 'Nouvelle fiche à valider', 'intro' => 'Le partenaire :partner a envoyé la fiche « :spa » (:city) pour validation.', 'button' => 'Ouvrir dans l’administration'],
        'published' => ['subject' => 'Votre établissement :spa est en ligne', 'title' => 'Félicitations, :spa est publié !', 'intro' => 'Votre fiche a été validée par notre équipe et est maintenant visible des clients. Vous recevrez les demandes de réservation par e-mail.', 'button' => 'Accéder à mon espace partenaire'],
        'refused' => ['subject' => 'Votre fiche :spa nécessite des modifications', 'title' => 'Des modifications sont nécessaires', 'intro' => 'Notre équipe a examiné la fiche « :spa » et ne peut pas la publier en l’état. Corrigez les points ci-dessous puis renvoyez-la pour validation.', 'button' => 'Compléter ma fiche'],
    ],

    'cancellation' => [
        'reason' => 'Motif indiqué par l’établissement',
        'decision_note' => 'Commentaire HammamLuxury',
        'response' => ['accepted' => 'a accepté', 'refused' => 'a refusé', 'none' => 'n’a pas encore répondu'],
        'button' => ['client' => 'Répondre depuis ma réservation', 'partner' => 'Voir la réservation', 'admin' => 'Traiter la demande'],
        'requested' => [
            'client' => ['subject' => 'Demande d’annulation de :spa — réservation :ref', 'title' => ':spa demande à annuler votre réservation :ref', 'intro' => 'Bonjour :name, l’établissement :spa a demandé l’annulation de votre réservation. Rien n’est annulé pour l’instant : votre réservation reste confirmée tant que HammamLuxury n’a pas pris de décision. Merci de nous indiquer depuis votre page de suivi si vous acceptez ou refusez cette annulation.'],
            'admin' => ['subject' => 'Demande d’annulation :ref — :spa', 'title' => 'Nouvelle demande d’annulation', 'intro' => 'L’établissement :spa demande l’annulation de la réservation de :name. Le client a été informé ; la décision finale vous appartient.'],
        ],
        'client_response' => [
            'admin' => ['subject' => 'Réponse du client — demande d’annulation :ref', 'title' => 'Le client a répondu', 'intro' => 'Le client :name :response la demande d’annulation de :spa. Vous pouvez maintenant prendre la décision finale.'],
            'partner' => ['subject' => 'Réponse du client — demande d’annulation :ref', 'title' => 'Le client a répondu à votre demande', 'intro' => 'Le client :name :response votre demande d’annulation. HammamLuxury va prendre la décision finale ; la réservation reste confirmée jusque-là.'],
        ],
        'refused' => [
            'partner' => ['subject' => 'Demande d’annulation :ref refusée', 'title' => 'Votre demande d’annulation est refusée', 'intro' => 'HammamLuxury a refusé votre demande d’annulation de la réservation de :name. La réservation reste confirmée : merci d’accueillir le client comme prévu.'],
            'client' => ['subject' => 'Votre réservation :ref est maintenue — :spa', 'title' => 'Votre réservation est maintenue', 'intro' => 'Bonne nouvelle :name : HammamLuxury a refusé la demande d’annulation de :spa. Votre réservation reste confirmée.'],
        ],
        'proposed' => [
            'client' => ['subject' => 'Proposition de nouvelle date — réservation :ref', 'title' => 'HammamLuxury vous propose une nouvelle date', 'intro' => 'Bonjour :name, suite à la demande de :spa, HammamLuxury vous propose de déplacer votre réservation à la date indiquée ci-dessous. Votre réservation actuelle reste confirmée tant que vous n’avez pas accepté. Vous pouvez accepter ou refuser depuis votre page de suivi.'],
        ],
        'rescheduled' => [
            'client' => ['subject' => 'Votre réservation :ref a été déplacée — :spa', 'title' => 'Nouvelle date confirmée', 'intro' => 'Bonjour :name, votre réservation chez :spa est confirmée à la nouvelle date ci-dessous.'],
            'partner' => ['subject' => 'Réservation :ref déplacée à une nouvelle date', 'title' => 'Le client a accepté la nouvelle date', 'intro' => 'Le client :name a accepté la nouvelle date proposée par HammamLuxury. La réservation est confirmée au créneau ci-dessous et la demande d’annulation est close.'],
            'admin' => ['subject' => 'Nouvelle date acceptée — réservation :ref', 'title' => 'Le client a accepté la nouvelle date', 'intro' => 'Le client :name a accepté la nouvelle date pour la réservation chez :spa. La demande d’annulation est close.'],
        ],
        'proposal_refused' => [
            'admin' => ['subject' => 'Nouvelle date refusée — demande d’annulation :ref', 'title' => 'Le client a refusé la nouvelle date', 'intro' => 'Le client :name a refusé la nouvelle date proposée pour la réservation chez :spa. La réservation d’origine reste confirmée ; la décision finale vous appartient.'],
        ],
        'proposed_date' => 'Nouvelle date proposée',
        'reminder' => [
            'admin' => ['subject' => 'Rappel — demande d’annulation :ref sans décision (rendez-vous demain)', 'title' => 'Demande d’annulation à traiter avant le rendez-vous', 'intro' => 'La demande d’annulation de l’établissement :spa pour la réservation :ref n’a pas encore reçu de décision et le rendez-vous a lieu dans moins de 24 heures. Merci de trancher avant la prestation.'],
        ],
        'arbitration' => [
            'admin' => ['subject' => 'À arbitrer — réservation :ref passée avec demande d’annulation ouverte', 'title' => 'Décision requise : prestation réalisée ou annulée ?', 'intro' => 'Le rendez-vous de la réservation :ref (:spa) est passé alors qu’une demande d’annulation de l’établissement était encore sans réponse. La clôture automatique et la commission sont suspendues jusqu’à votre décision.'],
        ],
        'proposal_note' => 'Précisions HammamLuxury',
    ],
    'guest_report' => [
        'subject' => 'Signalement client — réservation :ref (:spa)',
        'title' => 'Nouveau signalement de comportement client',
        'intro' => 'L’établissement :spa a signalé un incident concernant le client de la réservation :ref. Aucune mesure automatique n’est prise : merci d’examiner le signalement.',
        'category' => 'Motif',
        'button' => 'Voir le signalement',
    ],
    'message' => [
        'button' => 'Ouvrir la conversation',
        'footer' => 'Ne répondez pas à cet e-mail : utilisez la messagerie HammamLuxury pour que l’échange reste attaché à la réservation.',
        'partner' => ['subject' => 'Nouveau message client — :ref', 'title' => 'Nouveau message de :name', 'intro' => 'Le client de la réservation :ref chez :spa vous a écrit :'],
        'client' => ['subject' => 'Nouveau message de :spa — réservation :ref', 'title' => 'Nouveau message de :spa', 'intro' => 'Bonjour :name, l’établissement vous a écrit au sujet de votre réservation :'],
    ],

    'review_invite' => [
        'subject' => 'Comment s’est passée votre expérience chez :spa ?',
        'title' => 'Votre avis sur :spa',
        'intro' => 'Bonjour :name, merci pour votre visite chez :spa le :date. Partagez votre expérience en quelques minutes : votre avis aide les prochains clients et l’établissement.',
        'button' => 'Donner mon avis',
        'footer' => 'Ce lien est personnel et lié à votre réservation. Les avis sont vérifiés par HammamLuxury avant publication.',
    ],

    'billing' => [
        'receipt_line' => 'Votre reçu :receipt est téléchargeable dans votre espace partenaire.',
        'statement_available' => [
            'subject' => 'Relevé provisoire de commissions — :period',
            'title' => 'Votre relevé provisoire :period est disponible',
            'intro' => 'Le relevé provisoire des commissions HammamLuxury de :partner pour :period est consultable dans votre espace partenaire, rubrique Finances. Ce document n’est pas une facture.',
            'window' => 'Vous pouvez vérifier chaque ligne et contester une commission jusqu’au :day du mois inclus. Une contestation ouverte retire uniquement la réservation concernée du relevé.',
        ],
        'dispute_opened' => [
            'subject' => '[Facturation] Contestation reçue — :booking',
            'title' => 'Nouvelle contestation de commission',
            'intro' => 'Le partenaire :partner conteste la commission de la réservation :booking. La ligne est retirée du relevé facturable jusqu’à votre décision dans l’Admin (Finances › Contestations).',
        ],
        'dispute_decided' => [
            'subject' => 'Décision sur votre contestation — :booking',
            'title' => 'Votre contestation a été examinée',
            'intro' => 'HammamLuxury a pris une décision sur la contestation de la réservation :booking : :decision. Le détail est consultable dans votre espace partenaire, rubrique Finances.',
        ],
        'invoice_issued' => [
            'subject' => 'Facture :number — commissions HammamLuxury :period',
            'title' => 'Votre facture :number est disponible',
            'intro' => 'La facture :number des commissions HammamLuxury de :partner pour la période :period a été émise. Le PDF est téléchargeable dans votre espace partenaire, rubrique Finances › Factures. Montant TTC : :total, à régler avant le :due par virement bancaire ou en espèces contre reçu.',
        ],
        'invoice_pdf_failed' => [
            'subject' => '[Facturation] Échec de génération du PDF — :number',
            'title' => 'PDF de facture non généré',
            'intro' => 'La facture :number (:partner, :period) est bien émise mais son PDF n’a pas pu être généré. Aucune seconde facture n’a été créée ; la génération est relancée automatiquement (hl:billing-pdf-retry). Le partenaire ne sera notifié qu’une fois le PDF prêt.',
        ],
        'payment_partial' => [
            'subject' => 'Paiement enregistré sur la facture :number — solde restant :balance',
            'title' => 'Paiement de :amount enregistré',
            'intro' => 'Un paiement de :amount (:method, :paid_on) a été enregistré sur votre facture :number (:period). Le solde restant dû est de :balance. Le détail des règlements est consultable dans votre espace partenaire, rubrique Finances › Factures.',
        ],
        'payment_received' => [
            'subject' => 'Facture :number soldée — merci',
            'title' => 'Votre facture :number est soldée',
            'intro' => 'Nous avons bien reçu votre paiement de :amount (:method, :paid_on). La facture :number (:period) est désormais intégralement réglée. :receipt_line',
        ],
        'overpayment_detected' => [
            'subject' => '[Facturation] Trop-perçu de :total sur la facture :number',
            'title' => 'Trop-perçu constaté',
            'intro' => 'Le paiement de :amount enregistré sur la facture :number (:partner) dépasse le montant dû de :total. La facture est soldée ; l’excédent est signalé dans Finances › Trop-perçus et doit être régularisé manuellement (aucune imputation automatique).',
        ],
        'receipt_pdf_failed' => [
            'subject' => '[Facturation] Échec de génération du reçu :receipt',
            'title' => 'Reçu d’espèces non généré',
            'intro' => 'Le reçu :receipt (facture :number) n’a pas pu être généré : :error. Le paiement et le numéro de reçu sont conservés ; la génération est relancée automatiquement (hl:billing-receipt-retry) et le partenaire ne sera notifié qu’une fois le reçu prêt.',
        ],
        'reminder_before_due' => [
            'subject' => 'Rappel — facture :number à régler avant le :due',
            'title' => 'Échéance dans 5 jours',
            'intro' => 'La facture :number (:period) arrive à échéance le :due. Solde restant dû : :balance. Règlement par virement bancaire ou en espèces contre reçu, dans la limite légale.',
        ],
        'reminder_due' => [
            'subject' => 'Rappel — facture :number échue aujourd’hui',
            'title' => 'Échéance aujourd’hui',
            'intro' => 'La facture :number (:period) arrive à échéance aujourd’hui (:due). Solde restant dû : :balance. Si votre règlement est déjà en cours, merci de ne pas tenir compte de ce rappel.',
        ],
        'reminder_late_1' => [
            'subject' => 'Relance — facture :number en retard de paiement',
            'title' => 'Facture en retard (7 jours)',
            'intro' => 'La facture :number (:period), échue le :due, reste impayée. Solde restant dû : :balance. Merci de procéder au règlement ou de nous contacter si une difficulté est survenue.',
        ],
        'reminder_late_2' => [
            'subject' => 'Dernière relance — facture :number (15 jours de retard)',
            'title' => 'Facture en retard (15 jours)',
            'intro' => 'Malgré nos rappels, la facture :number (:period), échue le :due, reste impayée. Solde restant dû : :balance. À défaut de règlement, HammamLuxury pourra suspendre la visibilité de vos établissements conformément aux conditions partenaires.',
        ],
        'invoice_late' => [
            'subject' => '[Facturation] Facture :number impayée depuis 15 jours — :partner',
            'title' => 'Impayé à 15 jours',
            'intro' => 'La facture :number (:partner, :period), échue le :due, reste impayée après la dernière relance. Solde dû : :balance.',
        ],
        'footer' => 'Message automatique de la plateforme HammamLuxury ; la facturation réelle n’est pas active tant que son activation n’a pas été validée.',
    ],
];
