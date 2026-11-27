# Transmission du projet HammamLuxury

Document destiné à la propriétaire et à tout intervenant qui reprend le projet. Il ne contient aucun secret : les mots de passe, clés et codes sont à conserver dans le gestionnaire de mots de passe de la propriétaire.

## 1. Ce qui existe

| Élément | Emplacement | Remarque |
|---|---|---|
| Code | dépôt GitHub `riadmylaya-commits/hammamluxury-platform`, branche `main` | Laravel 11, Livewire, Filament (Admin et espace partenaire), MariaDB, Redis |
| Production | serveur `hammamluxury-prod-01`, dossier `/var/www/hammamluxury`, `https://prod.hammamluxury.com` | site non indexable (`HL_NOINDEX=true`) tant que l'ouverture commerciale n'est pas décidée |
| Staging (essais) | `https://staging.hammamluxury.com` | seules des données fictives ; base jamais copiée vers la production |
| Sauvegardes | dépôt restic chiffré sur le sous-compte Storage Box `hl-prod` | voir `docs/sauvegardes.md` |
| Scripts d'intervention | `/opt/hl-ops/` sur la production, sources dans `deploy/ops/` | voir `deploy/ops/README.md` |
| Documentation | `docs/` : `architecture.md`, `deploy-vps.md`, `sauvegardes.md`, `securite-surveillance.md`, `correction-finance.md`, `api.md` | |

## 2. Accès à conserver par la propriétaire

- Compte GitHub propriétaire du dépôt.
- Console de l'hébergeur (serveurs de production et de staging, Storage Box).
- Clé SSH root de la production (la seule autorisée pour root).
- Mot de passe du dépôt restic (`/etc/hl-backup/restic.password`) : **sans lui, aucune sauvegarde n'est restaurable**.
- Compte Admin du site (e-mail + double authentification) et ses codes de secours.
- Boîte d'envoi `no-reply@hammamluxury.com` (hébergeur de messagerie) et boîte de réception des alertes.
- Registraire du domaine et DNS.

Le compte d'intervention `hl-devin` expire le 20/12/2026 inclus. Après cette date : vérifier `chage -l hl-devin` (compte expiré), puis supprimer `/etc/sudoers.d/hl-devin` et la clé dans `/home/hl-devin/.ssh/authorized_keys`.

## 3. Les trois décisions séparées

Chacune exige un accord écrit de la propriétaire ; aucune n'entraîne les autres.

1. **Déploiement technique** : mise à jour du code. Ne publie aucune fiche, n'active aucune facturation.
2. **Publication commerciale** : passage d'une fiche de « brouillon » à « publiée » dans l'Admin, après accord écrit de l'établissement.
3. **Activation de la facturation** : voir section 6.

## 4. Ordre des déploiements à venir

| Étape | Contenu | Base de données | Contrôles avant de passer à la suivante |
|---|---|---|---|
| 1 | `d155c5a` | 2 migrations d'ajout | script `deploiement-d155c5a.sh` : arrêts préalables, sauvegarde externe, empreinte du contenu, e-mail de contrôle reçu, puis `ROUVRIR` ; essais réels ; 24 h de surveillance sans alerte |
| 2 | PR #68 (périodes commerciales) | 1 migration d'ajout : table `partner_commission_periods` | mêmes contrôles ; six fiches référencées, brouillon, 0 % ; aucune période créée |
| 3 | PR #69 (facturation, verrou fermé) | aucune migration | mêmes contrôles ; `hl:billing-status` : émission bloquée ; 0 facture, 0 règlement |

Pour les étapes 2 et 3, le script de déploiement est dérivé de celui de l'étape 1 une fois la fusion faite (la version exacte et l'empreinte du contenu ne sont connues qu'à ce moment), relu, puis installé par la propriétaire avec vérification SHA-256.

**Retour arrière** : `retour-arriere-code.sh <dossier /root/pre-deploiement-…> CONFIRMER` remet l'ancien code sans toucher à la base. Les tables ajoutées restent en place, inutilisées. Vérifié hors production le 27/11/2026 : le code `d155c5a` fonctionne sur une base portant la migration de la PR #68 (accueil, recherche, fiche, connexions Admin et partenaire, tâches planifiées).

## 5. Maintenance courante

| Fréquence | Geste | Comment |
|---|---|---|
| Chaque jour (automatique) | sauvegarde 03:15 UTC, vérification 07:15 UTC, surveillance toutes les 15 min | un e-mail d'alerte n'arrive qu'en cas d'anomalie |
| Chaque semaine | lire `/var/log/hl-monitor.log` et `/var/log/hl-backup.log` ; Admin → Notifications en échec | `diagnostic-root-complet.sh` affiche l'essentiel |
| Chaque mois | mises à jour système (automatiques pour la sécurité) ; redémarrage si un nouveau noyau est en attente, hors heures d'ouverture | `needrestart -r l` |
| Chaque trimestre | test de restauration isolé | `prep-test-restauration.sh` : restaure la dernière sauvegarde dans un dossier et une base temporaires, compare, supprime |
| Avant tout déploiement | sauvegarde fraîche | faite par le script de déploiement lui-même (`hl-backup.sh` + copie locale `/root/pre-deploiement-<date>`) ; arrêt si elle échoue |
| Certificat TLS | renouvellement automatique (Certbot) | alerte si moins de 14 jours |

En cas d'alerte : `docs/securite-surveillance.md`, section « Réaction à une alerte ». En cas de perte du serveur : `docs/sauvegardes.md`, « Procédure de reprise ».

## 6. Facturation : pourquoi elle ne peut pas s'activer par accident

L'émission d'une facture exige **toutes** les conditions suivantes ; une seule manquante suffit à la refuser (`php artisan hl:billing-status` les liste) :

1. Verrou dans le code (`BillingGate::ISSUANCE_LOTS_DELIVERED = false`) : ne se lève que par une modification de code, donc une nouvelle PR, une fusion et un déploiement.
2. Référence de recette et référence d'autorisation écrite de la propriétaire dans la configuration du serveur.
3. `HL_BILLING_ENABLED=true`, période de départ, régime et taux de TVA, validation du comptable.
4. Identité complète de l'émetteur, sans terme d'essai.
5. Empreinte de l'émetteur (`HL_BILLING_ISSUER_FINGERPRINT`) égale à celle calculée par `hl:billing-issuer-fingerprint` sur ce serveur : une configuration copiée depuis la staging est refusée.
6. Identité de facturation complète du partenaire.
7. L'émission reste un geste manuel de l'administrateur, facture par facture, avec confirmation.

Un déploiement ne modifie jamais `.env` ; le script de déploiement s'arrête si la facturation est activée en base ou dans `.env`.

## 7. Données fictives

- Elles n'existent que dans la base de la staging. Aucun script ne copie la base de la staging vers la production ; `export-staging-nettoye.sh` n'exportait que les six fiches, sans demande, facture ni règlement.
- La branche `staging-only/recette-facturation` (verrou levé pour les essais) ne doit jamais être fusionnée dans `main` ; elle peut être supprimée à la fin de la recette.
- En production, les compteurs de factures, reçus et avoirs sont vierges : la première facture sera `HL-<année>-000001`.

## 8. Reste à faire après la transmission

- Accords écrits des six établissements, puis publication fiche par fiche.
- Éléments comptables (identité de l'émetteur, régime de TVA, RIB) et validation du comptable avant toute activation de la facturation.
- Durées, capacités et droits photos à compléter avec les établissements.
