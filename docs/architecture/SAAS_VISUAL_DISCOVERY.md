# Inventaire graphique & solidification des rapprochements — #131

## Ce qui change

Le rapprochement ne commence plus par un JSON ni par un choix dans une fenêtre sans contexte.
Sur `/admin`, le nouvel écran **Gestion SaaS → Instances à qualifier**
montre le parc master **tel qu'il est réellement**, avec son groupe historique
et un état de qualification explicite.

Parcours :
1. **À qualifier** : instances actives du master sans dossier de revue.
2. **En traitement** : brouillons, propositions soumises et rejets à reprendre.
3. **Qualifiées** : classifications approuvées par une autre personne, **sans import SaaS**.
4. **Archivées** : instances historiques en consultation seulement ; pas d'action de qualification.

La recherche porte sur les codes/noms, avec filtre groupe.
Les formulaires sont préremplis à partir de l'instance choisie, et la
liste des groupes/tenants est recherchée par requêtes bornées (30 résultats,
pas de chargement de tout le parc dans un sélecteur). Le formulaire est partagé
avec l'écran **Rapprochement des écoles** dans
`app/Filament/Forms/Saas/ReconciliationDraftFields.php` :
une seule définition pour les deux usages.

Les types d'instance affichés proviennent de `config/saas.php`.
Les catégories commerciales elles-mêmes proviennent des entrées du
catalogue `saas_products`, **jamais des sous-domaines, de noms d'écoles
ou de règles codées avec des clients particuliers**.

## Renforcement de sécurité

L'interface d'inventaire ne sélectionne dans la table `tenants` que :
`id`, `code`, `name`, `group_id`, `status`, `deleted_at`.
Elle ne lit pas les secrets, noms de DB, mots de passe, jetons, prix, emails
ou données métier des établissements.

Un contrôleur **ne peut plus approuver** une proposition lorsqu'une
appartenance de groupe historique a changé depuis la soumission.
Au moment de la soumission, un snapshot minimal des références
(`historical_group_id`, `proposed_group_id`, `product_id`, `kind`,
`revision`) est inscrit dans l'événement d'audit existant.
À la décision, ce snapshot est comparé à l'état courant.
En cas de divergence, on renvoie le dossier à son auteur pour revue.
Aucune nouvelle migration n'est nécessaire pour ces snapshots.

Les droits effectifs sont revérifiés **au niveau du service métier**,
même lorsque le bouton est caché dans l'UI.
Les comptes inactifs et les archivés restent bloqués.
Le groupe historique, les contrats et l'abonnement ne sont jamais modifiés.

## Limites et précautions

Cette étape ne crée **pas** le registre Collège (backend distinct), ne
provisionne aucune école, ne rapproche pas automatiquement USAT à ROSTAN et
n'assimile pas les instances techniques à des établissements facturables.

Aucune migration additionnelle dans ce lot, mais les deux tables
`saas_mapping_reviews`/`saas_mapping_review_events` de la PR #146
restent nécessaires avant la disponibilité du menu de rapprochement.

Les tests automatisés portent sur le PHP, les accès et le parcours Filament.
Un contrôle navigateur réel mobile/tablette et une validation MySQL staging
restent des étapes pré-déploiement distinctes.
