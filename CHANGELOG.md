# Changelog — adminKlassci

Toutes les évolutions notables de l'application maître (provisionnement, déploiement,
supervision des instances KLASSCI) sont consignées ici.

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), groupé par mois.
Sections autorisées : Ajouts, Améliorations, Suppressions, Corrections, Sécurité.

## Septembre 2026

### Corrections
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

