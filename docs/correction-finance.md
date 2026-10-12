# Corrections financières et annulations — partie 1

Ce document accompagne le patch. Aucun paiement ni remboursement réel n'est exécuté par ce code.

## Règles retenues

| Situation | Montant acquis | Commission due |
| --- | --- | --- |
| Demande en attente ou réservation confirmée | 0 ; le devis reste une prévision | 0 |
| Prestation terminée | Total figé au devis | Commission figée au devis |
| Client absent, frais appliqués | Frais d'absence, plafonnés au total | Commission proportionnelle aux frais |
| Client absent, frais abandonnés | 0 | 0 |
| Partenaire absent, refus, expiration ou annulation | 0 | 0 |
| Remboursement intégral déclaré (`refunded`) | 0, sans modifier le statut de réalisation | 0 ; contre-écriture si déjà comptabilisée |
| Paiement `paid`, `partial` ou `on_site` déclaré | Ne change pas la réalisation ou le montant acquis | Ne crée pas de commission avant réalisation/frais |
| Réservation saisie par le partenaire (`source=partner`) | Selon réalisation/frais | 0 |

La commission est acquise à la réalisation ou aux frais d'absence, indépendamment de l'encaissement déclaré. Cette règle et l'annulation intégrale de commission en cas de remboursement doivent correspondre au contrat partenaire avant déploiement. `partial` signifie paiement partiel, jamais remboursement partiel : aucun montant de remboursement partiel n'est inventé à partir d'un simple statut.

Une réservation en attente ou confirmée peut être annulée par son client ou l'administration strictement avant son début. Le partenaire passe par une demande motivée ; l'administration décide. Aucun délai tarifaire nouveau (par exemple 24 heures), frais d'annulation ou remboursement automatique n'est ajouté ici. Une prestation terminée ne peut pas être annulée. Les corrections après clôture passent par les opérations administratives d'absence et le statut de remboursement, avec un journal.

## Grand livre

- Les premières commissions sont positives ; une correction ajoute `commission_reversal` (montant négatif) ou `commission_adjust` (montant positif).
- Chaque correction conserve la référence de réservation, l'acteur, la cause en note et un lien vers l'écriture précédente.
- Le solde est la somme de **tous** les types de `LedgerEntry::COMMISSION_TYPES`. Ne plus interpréter seulement les lignes `type=commission` comme le solde dû.
- Une clé SQL unique et la mutation verrouillée de la réservation empêchent les doubles écritures. Rejouer le même état ne change pas le solde.
- Déclarer ensuite qu'un remboursement était erroné ajoute une nouvelle écriture positive ; l'écriture de remboursement reste conservée.
- Le modèle interdit modification et suppression d'une écriture existante. La clé étrangère partenaire interdit sa suppression en cascade. Les requêtes SQL brutes restent sous la responsabilité des outils administratifs et des droits SQL : aucun trigger global n'est installé.

## Migration et historique existant

La migration `2026_10_12_100001_commission_ledger_integrity.php` vérifie les doublons historiques de commissions **avant** de modifier le schéma. En cas de doublon, elle échoue avec les identifiants concernés : aucune suppression automatique. Un rapprochement comptable documenté est nécessaire.

Elle ajoute des clés techniques aux commissions historiques sans changer leurs montants, dates ou notes. Les anciennes réservations pouvant déjà avoir un état incompatible avec leur solde doivent faire l'objet d'un rapprochement explicite ; les corrections nouvelles sont appliquées à chaque mutation financière sous verrou. Les écritures déjà supprimées par l'ancien code ne peuvent pas être reconstruites de façon fiable à partir de ce patch.

La commande `php artisan hl:reconcile-commissions` est en lecture seule par défaut : elle compare solde et règle actuelle, affiche les références et sort avec code 1 si des écarts ou écritures orphelines nécessitent une revue. `--booking=123` limite le périmètre et peut être répété. Après revue comptable et autorisation explicite sur l'environnement concerné, `--apply` ajoute des corrections liées sous `BookingMutation`, sans supprimer de ligne. La migration doit déjà être appliquée. En présence de doublons historiques, la commande refuse tout ajustement automatique. Ces commandes n'ont pas été exécutées pendant la préparation du patch.

La migration doit être validée sur une copie isolée et sauvegardée. MySQL/MariaDB peut valider implicitement les instructions de schéma : un échec après une DDL nécessite inspection et reprise contrôlée. Le retour arrière est refusé dès qu'une correction financière a été créée, pour éviter de réactiver un code qui en ignorerait le sens.

## Vérifications prévues

`FinanceIntegrityTest` couvre les règles ci-dessus, les annulations API/Livewire, les corrections liées, les replays, l'unicité SQL et l'arrêt sur doublons historiques. `IntegrityCircuitTest` attend désormais conservation de l'historique et solde nul après correction.

Les tests Laravel/MariaDB n'ont pas été exécutés dans l'environnement de préparation. Ils écrivent des données et doivent être lancés uniquement après autorisation sur une base de test séparée. La réception réelle des notifications, les paiements, le rapprochement des espèces et les remboursements partiels relèvent d'une validation distincte.
