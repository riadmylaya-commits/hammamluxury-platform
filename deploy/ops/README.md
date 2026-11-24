# deploy/ops — scripts d'intervention sur la production (exécution uniquement sur accord écrit par phase)

Ces scripts ne modifient pas l'application par eux-mêmes : chacun s'arrête si le fichier d'accord `/root/ACCORD-PHASE-<n>` est absent. Ils sont destinés à être copiés par la propriétaire dans `/opt/hl-ops/` (dossier root, non modifiable par l'utilisateur d'intervention) : la présence d'un script dans ce dossier vaut accord pour la phase correspondante.

| Script | Phase | Effet | Interdits codés |
|---|---|---|---|
| `diagnostic-sans-root.sh` | — | relevé sans privilèges (compte d'intervention) : charge, disque, services, site | — |
| `diagnostic-root-minimal.sh` | — | quatre lectures root : commit déployé, comptes de 4 tables, présence des dossiers de sauvegarde, version restic ; aucun secret | — |
| `phase1-sauvegarde-externe.sh` | 1 | sauvegarde locale complète, dépôt restic chiffré sur le sous-compte Storage Box `hl-prod`, première sauvegarde, vérification | migrate, git dans /var/www, redémarrage, écriture en base, DNS |
| `phase1-test-restauration.sh` | 1 (staging) | restauration dans une base isolée puis suppression ; staging contrôlée identique | — |
| `export-staging-nettoye.sh` | 3 (staging) | export des 6 fiches sans demandes de test ni jobs | — |
| `phase2-code-et-base.sh` | 2 | redémarrage noyau, code `main`, migrations sur base vide, `.env` complété, release | DNS, WordPress, e-mails, HL_NOINDEX, HL_BILLING_* |
| `phase3-import-donnees.sh` | 3 | import des 6 fiches depuis l'export, sommes de contrôle | écrasement d'une base non vide |
| `deploiement-d155c5a.sh` | mise à jour | passe la production de `1afc6f6` à `d155c5a` (empreinte `25534c7b…`), données conservées : sauvegarde externe, maintenance, copie de la base rechargée et comparée table par table, code, 2 migrations d'ajout, contrôles avant réouverture | publication d'une fiche, facturation, modification de `.env`, suppression de données, redémarrage |
| `retour-arriere-code.sh` | mise à jour | remet l'ancien code **sans restaurer la base** : réservations, comptes et notifications reçus depuis le déploiement conservés ; aucune migration annulée | restauration ou vidage de la base, modification de `.env` |

Utilisateur d'intervention recommandé : `hl-devin` (clé SSH restreinte, expiration), `sudo` limité à des scripts **nommés individuellement** dans `/etc/sudoers.d/hl-devin` (un par phase, ajouté par la propriétaire après accord écrit) ; journal dans `/var/log/auth.log` et `/root/phase-<n>-<date>.log`.


Installation d'un script : téléchargement, **vérification SHA-256 bloquante** (`sha256sum -c`), copie en `0500` dans `/opt/hl-ops/`, puis `chattr +i` (immuable : toute modification exige `chattr -i` par root = nouvelle validation).

## Mise à jour vers d155c5a (scripts préparés, non installés)

- `deploiement-d155c5a.sh CONFIRMER` s'arrête **avant toute modification** si : version installée ≠ `1afc6f6`, fiches ≠ 31, 34, 35, 36, 37, 38, fiche non brouillon / non référencée, facturation activée (base ou `.env`), empreinte du contenu téléchargé ≠ `25534c7b1ad8e876ac8eb84890bd59859d6999cd`, sauvegarde externe en échec.
- Après la mise en maintenance, tout échec laisse le site en maintenance et affiche la commande de retour arrière.
- Contrôles avant réouverture : 0 migration en attente, +2 migrations exactement, nombre de lignes **et empreinte SHA-256 du contenu** identiques pour toutes les tables de données (seules trois colonnes ajoutées sont admises), commissions / factures / paiements / facturation à zéro en base et vus par l'application, e-mail de contrôle accepté par le serveur d'envoi, état des six fiches identique, facturation désactivée, `.env` inchangé (somme de contrôle), tâche `hl:expire-waiting` planifiée, file d'envoi en marche. Puis pages publiques, connexion Admin et partenaire en 200, fiche brouillon en 404, en-tête noindex.
- `retour-arriere-code.sh <dossier> CONFIRMER` compare comptes, fiches, réservations, notifications avant et après : tout écart laisse le site en maintenance.
- `retour-arriere.sh` (ancien) **restaure la base** : retiré définitivement, avec `deploiement-code.sh` et `six-fiches.sh`, par `installation-scripts.sh` (reprise possible en le relançant ; `ANNULER-DROITS <archive>` remet les droits d'origine) (fichiers supprimés, droits sudo retirés, copie d'archive en droits 000).
- Verrou commun `/run/lock/hl-intervention.lock` : un déploiement et un retour arrière ne peuvent pas tourner en même temps.
- Base temporaire de contrôle : nom `hl_controle_<14 chiffres>`, refus si une telle base existe déjà, supprimée seulement si le script l'a créée, absence vérifiée ensuite.
- `retour-arriere-code.sh` s'arrête avant tout changement de code si la maintenance n'est pas effective (503 constaté) ou si la file d'envoi n'est pas arrêtée ; il compare l'empreinte du contenu de toutes les tables avant / après ; si un contrôle HTTP échoue après la réouverture, il remet le site en maintenance.
- Pendant les comparaisons de contenu : maintenance constatée (503 + marqueur `storage/framework/down`, qui suspend le planificateur), file d'envoi arrêtée, aucune commande `artisan` en cours ; la file n'est relancée qu'après la dernière comparaison.
- `sommes-de-controle.sh` (lecture seule) : empreinte, propriétaire, droits et verrou des fichiers réellement installés.
- Répétition du 24/11 : `essais` A1–A10 (arrêts préalables), B1–B2 (contenu modifié détecté, retour arrière), C1–C3 (déploiement), D1–D5 (retour arrière et ses arrêts).
- Répétition du 23/11 hors production (copie des six fiches de production, commandes système simulées : sudo, supervisor, fail2ban, sauvegarde externe) : arrêts préalables constatés, déploiement réussi, puis retour arrière avec une réservation et un compte créés entre-temps, tous deux conservés.
- `diagnostic-root-complet.sh` n'affiche plus la valeur de `HL_DEMO_PASSWORD` (seulement « défini » ou « absent »).
