# Registre KLASSCI : groupe / établissement / produit / instance

Suivi transverse : https://github.com/James10192/adminKlassci/issues/131
PR de mise en place : https://github.com/James10192/adminKlassci/pull/135

## Décision métier impérative du 29 octobre 2026

**Un établissement appartient à une seule catégorie et utilise un seul produit KLASSCI.**
Un groupe peut détenir plusieurs établissements différents et mutualiser l'accès au
pilotage commercial. Il ne faut **jamais** afficher « Collège + Université »
sur une même fiche établissement.

Exemple :
- Groupe **Rostan** (organisation cliente/facturable).
  - **Rostan Collège** : catégorie « collège » ; plateforme `fastapi-college`.
  - **USAT** : catégorie « université LMD » ; plateforme `laravel-v2`.
  - **ISLG** : catégorie « grande école BTS » ; plateforme `laravel-v2`.

Une école indépendante possède aussi un compte organisation, mais avec un
seul établissement. L'organisation est un conteneur de propriété/facturation,
PAS une instance technique ni une catégorie scolaire.

## Contraintes de données

- `saas_organizations` : compte commercial, supporte plusieurs établissements.
- `saas_products` : 1 code commercial par catégorie `establishment_type` ;
  l'adaptateur technique `adapter_key` peut être partagé (LMD + BTS).
  Les catégories sont des données de catalogue, jamais déduites du sous-domaine.
- `saas_establishments` : appartient à une organisation et à **exactement un
  produit** (FK product_id NOT NULL).
- `saas_product_instances` : au maximum **une instance par établissement**
  (contrainte UNIQUE establishment_id). Une FK composite
  (establishment_id, product_id) force le produit de l'instance à être celui
  de l'établissement. Les codes tenant sont uniques par produit.

Ne pas exposer d'édition libre du produit/catégorie après activation sans
workflow de migration explicite (nouvelle instance, validation, audit).
Ne pas confondre groupe et établissement dans l'authentification : un
responsable groupe peut voir plusieurs établissements, mais chaque rôle
et jeton d'accès est contrôlé par établissement.

## Compatibilité et migration

Migration **strictement additive** : aucune écriture sur les tables historiques
`tenants`, abonnements ou bases métiers. Aucune création ou import automatique
des vraies écoles : exemple Rostan = scénarios de test uniquement.
Le catalogue est volontairement vide et désactivé : pas de trial ni billing
activé par cette PR. Les rapprochements entre les vrais codes historiques
et les structures groupe demandent un inventaire avant import.

**Next** : orchestration B2B groupe/école, parcours d'inscription distinct pour
une école indépendante ou un groupe avec plusieurs écoles, entitlement/quotas
par école, vue consolidée groupe, adapter collège et adapter v2 LMD/BTS.
