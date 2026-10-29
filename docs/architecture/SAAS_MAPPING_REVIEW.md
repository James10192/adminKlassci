# Revue des correspondances réelles — aucune écriture (lot #131)

## État du master constaté sur LWS le 29/10/2026

Les deux migrations SaaS ont été exécutées avec succès (confirmé par la sortie de l'opérateur) :
- 1 groupe historique `ROSTAN`, 14 tenants historiques dont `islg-rostan` archivé ;
- aucune organisation/école/instance encore créée dans le registre SaaS ;
- `islg` a déjà `tenants.group_id = ROSTAN` ;
- `usat` n'a PAS de groupe historique, malgré son appartenance commerciale au groupe Rostan signalée par le métier ;
- le collège Rostan relève de la plateforme Collège **distincte** et ne doit pas être créé depuis `tenants` Laravel.

**Ne pas modifier automatiquement `tenants.group_id`** : l'accès SSO portail Groupe, les membres et les contrats peuvent dépendre du rattachement existant.

## Objectif du lot

La commande `saas:mapping-review` accepte des propositions explicites
fournies dans un **fichier JSON privé d'opérateur**. Elle se contente de :
- vérifier les codes tenant/groupe existants (non archivés), comparer les liens enregistrés ;
- signaler les doublons et les différences entre rattachement proposé et groupe historique ;
- vérifier la catégorie proposée contre le **catalogue SaaS existant** (qui est encore vide à ce stade) ;
- exiger une référence de justification pour déclarer un tenant établissement ou une instance technique/de démonstration ;
- produire un tableau / JSON avec `blocked`, `pending_human_approval` et surtout **`can_apply: false`** ;
- ne JAMAIS importer d'organisation, de classe, d'utilisateur, de tarif ou d'établissement.

Ce n'est PAS une commande de validation définitive ni un import.

## Exemple du manifeste d'étude (ne pas le committer avec des données nominatives)

Écrire un fichier privé hors dépôt Git, par exemple `/chemin/prive/mapping-revue.json` :

```json
{
  "schema_version": 1,
  "proposals": [
    {
      "tenant_code": "islg",
      "kind": "school",
      "group_code": "ROSTAN",
      "establishment_type": "grande_ecole_bts",
      "evidence_reference": "validation-contractuelle-a-archiver"
    },
    {
      "tenant_code": "usat",
      "kind": "school",
      "group_code": "ROSTAN",
      "establishment_type": "universite_lmd",
      "evidence_reference": "validation-contractuelle-a-archiver"
    }
  ]
}
```

Le service est paramétrique, **aucune école ou catégorie n'est écrite en dur dans le code**.

Usage (lecture seule) :

```bash
php artisan saas:inventory
php artisan saas:mapping-review /chemin/prive/mapping-revue.json
php artisan saas:mapping-review /chemin/prive/mapping-revue.json --format=json
```

Le catalogue étant actuellement vide, la vérification signalera `product_catalogue_not_ready` pour les établissements tant que le catalogue n'est pas préparé/validé. Pour USAT, elle signalera aussi `historical_group_disagreement` : **ce résultat est attendu et il ne faut PAS tenter de le corriger en SQL sans workflow de réconciliation**.

L'analyse de la plateforme Collège nécessitera un adaptateur distinct, et une liaison explicite vers une organisation sans utiliser les identifiants legacy Laravel.

Les noms commerciaux, références de preuve et plans restent des données internes : ne pas envoyer de manifeste ni de sortie JSON publique sur GitHub.

## Garde-fous

La commande n'a ni `--apply` ni `--import`, ne lit aucun fichier d'environnement d'école et n'exécute **aucune écriture**. Elle lit un JSON local limité à 64 Kio puis consulte seulement le master et écrit le rapport sur stdout. Aucune donnée nominative d'élève, aucun mot de passe, jeton de connexion ou identifiant MySQL n'est lu. Une proposition sans référence de justification est bloquée ; les tenants archivés ou les rattachements contradictoires sont bloqués ; les instances techniques ne sont jamais détectées automatiquement.

**Ne pas utiliser `php artisan migrate --force` pour cette PR : elle ne contient aucune migration.**

Étape suivante : construire le catalogue commercial paramétrable (Collège, Université LMD, Grande école BTS) puis un véritable workflow d'approbation Maker/Checker et import audité, d'abord en staging, avec préservation des contrats existants.

Suivi : https://github.com/James10192/adminKlassci/issues/131
