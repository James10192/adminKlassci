# Changelog — adminKlassci

Toutes les évolutions notables de l'application maître (provisionnement, déploiement,
supervision des instances KLASSCI) sont consignées ici.

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), groupé par mois.
Sections autorisées : Ajouts, Améliorations, Suppressions, Corrections, Sécurité.

## Septembre 2026

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
