# Pont legacy Group → SaasOrganization (étape sans backfill)

Épic : https://github.com/James10192/adminKlassci/issues/131

La base historique adminKLASSCI possède déjà `groups`, `group_members`,
et `tenants.group_id`. Ne pas dupliquer les groupes Rostan/USAT/ISLG en
reconstituant des comptes groupe à partir des libellés d'écoles.

Le nouveau champ nullable `saas_organizations.legacy_group_id` relie
**au maximum une organisation SaaS à un groupe existant**, et reste NULL
pour une école indépendante ou un groupe non importé.
Les groupes historiques ne sont pas déplacés ni réécrits.

## Invariants de la migration

- Aucune attribution automatique : ni normalisation de nom ni matching par
  sous-domaine. Les correspondances réelles exigent une revue humaine
  de chaque organisation et des établissements qui lui appartiennent.
- Contrainte UNIQUE : un même groupe legacy ne peut pas être lié à deux
  organisations SaaS différentes.
- `Group::tenants` et `Tenant::group_id` restent opérationnels ; pas de
  modification des rôles et droits `GroupMember`.
- Les écoles du groupe sont représentées par des `SaasEstablishment` distincts
  avec exactement une catégorie et un produit par école (Rostan Collège /
  USAT LMD / ISLG BTS).
- Une lecture de la relation Groupe historique n'autorise **aucune** action
  sur ses établissements : accès par permissions effectives.

## Prérequis du futur import

1. Lister les groupes legacy, leurs codes et tenants (rapport lecture seule).
2. Comparer les contrats et la catégorie scolaire de chaque école, à valider
   par l'équipe ; ne pas déduire BTS vs LMD du moteur Laravel.
3. Enregistrer une table de correspondance approuvée avec audit / provenance.
4. Valider sur staging, contrôler les cas doublons et groupes supprimés ;
   accompagner l'import d'un rollback non destructif.
5. Ne pas provisionner de nouvelle base au cours de l'import historique.
