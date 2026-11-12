# deploy/ops — scripts d'intervention sur la production (exécution uniquement sur accord écrit par phase)

Ces scripts ne modifient pas l'application par eux-mêmes : chacun s'arrête si le fichier d'accord `/root/ACCORD-PHASE-<n>` est absent. Ils sont destinés à être copiés par la propriétaire dans `/root/hl-ops/` (dossier root, non modifiable par l'utilisateur d'intervention) : la présence d'un script dans ce dossier vaut accord pour la phase correspondante.

| Script | Phase | Effet | Interdits codés |
|---|---|---|---|
| `etat-lecture-seule.sh` | — | relevé de l'état du serveur, aucune écriture | — |
| `phase1-sauvegarde-externe.sh` | 1 | sauvegarde locale complète, dépôt restic chiffré sur le sous-compte Storage Box `hl-prod`, première sauvegarde, vérification | migrate, git dans /var/www, redémarrage, écriture en base, DNS |
| `phase1-test-restauration.sh` | 1 (staging) | restauration dans une base isolée puis suppression ; staging contrôlée identique | — |
| `export-staging-nettoye.sh` | 3 (staging) | export des 6 fiches sans demandes de test ni jobs | — |
| `phase2-code-et-base.sh` | 2 | redémarrage noyau, code `main`, migrations sur base vide, `.env` complété, release | DNS, WordPress, e-mails, HL_NOINDEX, HL_BILLING_* |
| `phase3-import-donnees.sh` | 3 | import des 6 fiches depuis l'export, sommes de contrôle | écrasement d'une base non vide |

Utilisateur d'intervention recommandé : `hl-devin` (clé SSH restreinte, expiration), `sudo` limité à `/root/hl-ops/*` ; journal dans `/var/log/auth.log` et `/root/phase-<n>-<date>.log`.
