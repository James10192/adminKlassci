# Changelog — adminKlassci

Toutes les évolutions notables de l'application maître (provisionnement, déploiement,
supervision des instances KLASSCI) sont consignées ici.

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), groupé par mois.
Sections autorisées : Ajouts, Améliorations, Suppressions, Corrections, Sécurité.

## Septembre 2026

### Ajouts
- **Consommation d'IA des écoles** (assistant Nanan de KLASSCI). `tenant:sync-ai-usage` rapatrie chaque
  heure les lignes `assistant_consommations` de chaque école (lecture seule, par identifiant, sans
  doublon) dans `tenant_ai_usages`. Nouvelle page Facturation → Consommation IA (super admin et
  facturation) : chaque appel avec école, personne, palier, modèle, jetons et coût en FCFA, filtres
  et total ; en tête, le mois en cours école par école rapporté au budget. Tuile « Assistant IA »
  au tableau de bord (coût du mois comparé à la même période du mois dernier, écoles au-delà de
  leur budget). Budget mensuel d'IA par école dans la fiche du tenant, transmis par
  `/api/tenants/{code}/limits` (`assistant.budget_mensuel_fcfa`).
- `care:fonctionnalites <code>` affiche, active ou désactive les fonctionnalités KLASSCI Care d'une
  école (`--activer=tout`, `--desactiver=support_screenshot`). Elles étaient désactivées par défaut
  et ne s'activaient qu'en écrivant dans `tenant_features` à la main : une école munie de son
  identifiant n'affichait donc toujours pas le bouton d'aide. Seul ce qui change réellement est
  journalisé ; l'école le voit sous 5 minutes (cache du bootstrap).

### Corrections
- `tenant:verifier-restauration` n'avait jamais abouti en production : sur cPanel, l'utilisateur MySQL
  d'une instance ne peut pas créer de base, et la commande commençait par `DROP/CREATE DATABASE`.
  La base d'essai est désormais créée une fois dans cPanel (`SAUVEGARDE_BASE_ESSAI`, partagée par toutes
  les instances) et vidée table par table avant et après chaque essai. Trois verrous avant d'y toucher :
  nom sûr, suffixe `_verif_restauration`, aucune instance déclarée sur cette base.
- La restauration d'essai retire les clauses `DEFINER` du dump : une vue créée par un autre compte
  faisait échouer la restauration faute de privilège SUPER.
- La raison d'un échec de vérification est tronquée à la taille de sa colonne et garde la ligne
  `ERROR` du client MySQL. Une raison trop longue faisait échouer l'enregistrement du verdict lui-même.
- `tenant:cleanup-backups` ne supprime plus le dossier de sauvegardes d'une instance. `backup_path`
  désigne ce dossier, et le nettoyage le supprimait en entier pour une seule archive expirée :
  celle de la nuit partait avec. Seuls les fichiers de la sauvegarde expirée (et leur sceau) sont retirés.

### Sécurité
- Injection de commande par le nom de branche fermée sur le déploiement des tenants.
  `tenant:deploy` n'exécute plus aucune commande dans un shell : git, composer, artisan et chmod
  reçoivent leurs arguments sous forme de liste (`Process::run([...])`).
- Nom de branche validé à chaque entrée (`App\Support\Git\NomDeBranche`, règle `NomDeBrancheGit`) :
  webhook `POST /api/deploy` (422 via `DeployWebhookRequest`), formulaires Filament de déploiement
  et de tenant, option `--branch` de `tenant:deploy` et `tenant:provision`, branche enregistrée en base.
- `tenant:deploy` vérifie que la branche existe sur origin (`git ls-remote`) avant la mise en maintenance.
- Workflow GitHub `deploy-tenant.yml` : les entrées du formulaire ne sont plus collées dans le script
  (passage par `env:`, charge utile construite par `jq`).
- `tenant:provision` ne relit plus aucune valeur dans un shell local : commandes en argv. En production,
  la commande transmise à ssh est citée mot par mot (`App\Support\Shell\CommandeDistante`).
- `tenant:provision` refuse avant toute écriture un code ou un sous-domaine hors étiquette DNS
  (code limité à 54 caractères pour tenir dans un nom de base MySQL), et un nom d'établissement contenant
  `"`, `\`, `$` ou un caractère de contrôle, qui aurait pu ajouter des clés au `.env` de l'école.
- Archives de sauvegarde authentifiées. Le chiffrement AES-256-CBC n'empêchait pas de modifier une
  archive sans la clé : elle se déchiffrait quand même. Chaque archive chiffrée reçoit désormais un
  sceau HMAC-SHA256 (fichier `.sceau`, copié hors site avec elle), calculé avec une clé dérivée de
  `SAUVEGARDE_CLE` et lié au nom du fichier (`App\Domain\Exploitation\Sauvegarde\SceauSauvegarde`).
- `tenant:verifier-restauration` vérifie le sceau avant de déchiffrer, et refuse une archive modifiée
  ou une sauvegarde scellée dont le sceau a disparu (colonne `tenant_backups.est_authentifie`).
  Les archives prises avant le scellement se relisent encore, avec un avertissement.

