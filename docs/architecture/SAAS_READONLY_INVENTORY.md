# Inventaire de rapprochement (lecture seule) — épopée SaaS #131

## Objectif

Avant l'import des établissements réels, inspecter les liaisons **déjà
prouvées en base** entre `groups`, `tenants.group_id`,
`saas_organizations.legacy_group_id`,
`saas_establishments` et `saas_product_instances.legacy_tenant_id`.

Les catégories Collège, Université LMD et Grande école BTS ne sont **jamais**
devinées à partir de `name`, `code`, `subdomain`, `git_branch`,
du mot « Rostan » ni du moteur `laravel-v2`.

## Exécution et résultat

Commandes sur un environnement d'administration autorisé, après migration du master :

```bash
php artisan saas:inventory
php artisan saas:inventory --format=json --no-ansi
```

Cela **lit seulement** les tables du master. Pas de lecture des `.env`
des écoles, de leurs bases métier, ni d'API extérieures. Le JSON est sur
stdout, **aucun fichier n'est créé**, aucun changement SQL n'est fait.

Les champs de sortie proviennent d'une liste fermée de colonnes :
identifiants et noms commerciaux, codes groupe/tenant, état et plan historique,
liens explicites, type scolaire si configuré, points à revoir.
Sont exclus : `database_credentials`, `api_token`, mot de passe,
email/téléphone/adresse, factures, données des élèves, notes et metadata.

Un groupe sans organisation liée est marqué `a_valider`. Un tenant
sans instance SaaS explicite est marqué `instance_saas_non_rattachee`
et `categorie_a_confirmer`. Le rapport montre les conflits de groupes,
y compris les anciens tenants archivés, sans les modifier.

Pour Rostan, l'objectif métier est le **groupe** avec trois établissements
différents : Rostan Collège (collège), USAT (université LMD), ISLG (grande
école BTS). **Ne pas affirmer que les liens réels existent déjà en base** :
le rapport indiquera ce qui manque et devra être revu par un opérateur.

## Sécurité et décision

- La commande doit être exécutée **uniquement par un opérateur technique
  autorisé**, pas par une route publique, une action Nanan ni un cron.
- Les sorties, même sans secrets techniques, peuvent révéler des clients
  et leurs plans : ne pas les publier dans GitHub ni des journaux partagés.
- `saas:inventory` n'a volontairement ni `--apply` ni `--import` et
  n'appelle jamais `tenant:discover`, qui peut créer des tenants/Free.
- Un rapprochement validé exige une **autre commande dédiée**, avec
  mapping explicite ID vers ID, audit et double vérification avant toute
  écriture. Ne jamais importer depuis une similarité de nom.
- La base Master peut être présente sous cPanel ou Contabo ; la commande
  ne suppose pas le serveur. Elle n'a pas été exécutée sur une base réelle
  dans le cadre de cette PR : les résultats métier restent à recueillir.

## Prochaines étapes

1. Obtenir la sortie JSON en environnement privé et vérifier les entrées
   avec le responsable technique et la direction des établissements.
2. Formaliser la liste de correspondance exacte groupe/école/catégorie
   avant toute création du nouveau registre.
3. Ajouter le backend control-plane Collège (adaptateur santé, quotas),
   puis l'interface de supervision groupe, protégée par permissions.
4. Pour DevOps : inventorier les cron jobs existants
   (`routes/console.php` **et** `bootstrap/app.php` planifient déjà
   des tâches), afin d'éviter de dupliquer les workers au passage à Dokploy.

Suivi : https://github.com/James10192/adminKlassci/issues/131
