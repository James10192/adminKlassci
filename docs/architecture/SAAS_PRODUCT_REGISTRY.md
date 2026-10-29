# Registre multi-produit KLASSCI — lot fondation (migration additive)

Épic de suivi : https://github.com/James10192/adminKlassci/issues/131

## Objectif

Distinguer le client commercial (organisation), l'établissement physique,
le produit vendu (Université ou Collège) et l'instance technique. On peut
donc associer plusieurs produits au même établissement sans mettre les
bases métier dans une base commune.

## Tables ajoutées

- saas_organizations : client commercial.
- saas_establishments : établissements rattachés au client.
- saas_products : catalogue technique, avec code et adapter_key; inactif par défaut.
- saas_product_instances : référence chaque instance produit. La clé
  external_tenant_code est unique **par produit**, pas mondialement.
  Le legacy_tenant_id optionnel relie l'ancien modèle Tenant pour KLASSCIv2,
  mais n'est pas nécessaire pour Collège.

## Impact sur les clients existants

**Aucune mise à jour ni conversion automatique de tenants.** La migration
ajoute quatre tables, sans changer les données ni colonnes des tenants
historiques. Aucun job, écran, endpoint, abonnement, DNS ou connexion DB
métier n'est activé. Le catalogue est vide à l'installation, de façon
délibérée pour éviter qu'un nouveau produit fictif se retrouve actif.

Prochaine migration contrôlée à écrire : rapprochement des établissements
existants avec leur organisation, validation manuelle des correspondances
incertaines (ISLG/rostan, branches par tenant, anciens slugs), puis
import College. Ne pas attribuer le plan Free par défaut à un tenant
découvert : cf. issue #73.

## Invariants

- Un établissement peut être client des deux produits.
- Deux produits peuvent utiliser le même code tenant externe ; dans un
  même produit, le code n'est pas réutilisé.
- Un établissement ne porte qu'une instance par produit dans ce modèle
  initial. Évaluer le cas multi-campus avant évolution.
- Les produits restent inactifs tant que leurs adaptateurs ne passent pas
  les tests de contrat.
- Aucune clé d'infrastructure ou de DB ne vit dans ce registre public.
- Le plan, les droits et la facturation sont hors portée du lot : ne pas
  interpréter cette migration comme un système de subscription opérationnel.
