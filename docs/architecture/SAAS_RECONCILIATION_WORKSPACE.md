# Espace de rapprochement des écoles — Fondations SaaS v1

## Problème résolu

Les commandes `saas:inventory` et `saas:mapping-review` ont servi aux audits techniques,
mais un outil commercial ne doit pas demander aux opérateurs d'éditer des fichiers JSON
sur un serveur Linux.

Ce lot crée deux **écrans Filament 3 natifs**, accessibles depuis le menu
**Gestion SaaS** de `/admin`, adaptés au mobile, aux écrans larges et au mode sombre :

- **Catalogue des produits** : produit commercial, catégorie scolaire unique,
  adaptateur technique, disponibilité pour la qualification.
- **Rapprochement des écoles** : dossiers persistants, qualification humaine,
  filtre par état, groupes proposés, indicateur de conflit, historique, contrôle par
  un autre administrateur et justification obligatoire.

Les données du master du 29/10/2026 recensent un groupe ROSTAN et quatorze tenants
historiques. `islg` est lié à ROSTAN, mais `usat` ne l'est pas dans l'ancienne
table `tenants`. Le dossier peut proposer commercialement le groupe ROSTAN pour
USAT et afficher l'écart : un **second valideur** doit reconnaître et documenter
la divergence. Ce geste ne modifie jamais le portail SSO du groupe.

Les anciennes instances techniques et les démos peuvent être qualifiées par un
opérateur, **jamais déduites automatiquement** de leur code.

## Architecture

- `config/saas.php` : capacités effectives par rôle, adaptateurs pris en charge.
  Les contrôleurs et les Pages n'effectuent aucune comparaison de rôle ;
  ils utilisent `Gate`, vérifié à nouveau par le service de domaine.
- `SaasServiceProvider` : enregistre les capacités de consultation, proposition,
  contre-vérification et gestion du catalogue.
- `saas_mapping_reviews` : **une proposition par tenant historique**, avec
  révisions, auteur, checker, état, justification, produit et groupe proposés.
- `saas_mapping_review_events` : chronologie des changements, auteur et
  snapshot de décision, avec suppression restreinte (journal conservé).
- `SaasMappingWorkflow` : seule porte d'écriture des dossiers ;
  transactions et verrous ligne, validation Laravel, vérification de la
  catégorie, du catalogue actif, des archives et des collisions.
- Les écrans Filament ne manipulent directement que les données du catalogue
  administrable. **Ils ne créent jamais une instance SaaS.**

### États

`draft` → `submitted` → `approved` OU `rejected`.
Une proposition rejetée retourne en brouillon chez son auteur avec un numéro
de révision, sans effacer la décision précédente.

**Maker ≠ checker** : un même compte ne peut pas approuver son propre dossier,
même avec toutes les permissions.

Une classification approuvée n'est **pas importée**. Un workflow séparé, avec
confirmation contractuelle, backfill idempotent et rollback staging, est requis
avant toute écriture dans `saas_organizations`, `saas_establishments`,
`saas_product_instances`, `tenants.group_id` ou les abonnements.

## Catalogue

Le code, le type d'établissement et l'adaptateur sont des valeurs permanentes
après création pour empêcher de requalifier silencieusement des écoles.
Le nom affiché et la disponibilité de qualification sont modifiables seulement
par un rôle doté de `saas.catalog.manage`. La catégorie n'est **pas** la
filière BTS/LMD dans un même établissement : un établissement est UNIQUEMENT
collège, université LMD ou grande école BTS selon son produit, tandis que le
groupe peut posséder les trois écoles.

Les produits ne sont pas pré-créés dans la migration, afin de ne pas inventer
de prix, contrats, catégories client ou règles d'habilitation. Les opérateurs
configurent les catégories depuis **Catalogue des produits**. L'activation de
qualification ne provisionne ni le moteur Collège ni KLASSCIv2.

## Déploiement et vérification

La PR contient une **migration additive** créant uniquement les tables
`saas_mapping_reviews` et `saas_mapping_review_events`. Les données historiques
ne sont jamais migrées automatiquement.

Sur staging, sauvegarder la base, vérifier `migrate:status` et `migrate --pretend`,
valider toutes les migrations en attente, puis appliquer selon la procédure
habituellement autorisée. La fusion GitHub **ne déclenche aucune migration sur LWS**.
Ne jamais lancer `migrate:fresh` sur les bases en production.

Tests attendus : PHP 8.3 et 8.4, contrôle du panel de ressources, refus
opérateur inactif, support incapable d'approuver, Maker/Checker obligatoire,
conflit ROSTAN/USAT explicite, tenant archivé bloqué, rejet/révision historique,
aucun changement de contrat, tenant, SSO ou SaaS instance.

## Limites de ce premier lot

- Il n'importe pas les établissements ni le collège externe.
- Il ne crée pas de devis, d'abonnement ni d'autorisation dans les écoles.
- L'interface d'examen repose sur les tenants du **master Laravel** uniquement.
  Un adaptateur Collège séparé devra identifier les écoles FastAPI avant
  une réunion groupe multi-produits complète.
- Les produits sont créés manuellement sous supervision, sans seeder de
  montants ni de clients. Leurs clés d'adaptateur sont configurées, sans moteur
  de provisioning actif.
- La validation Maker/Checker ne remplace pas l'approbation contractuelle ni
  la réconciliation du SSO historique.

Suivi central : https://github.com/James10192/adminKlassci/issues/131
