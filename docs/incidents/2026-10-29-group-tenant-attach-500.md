# Incident 500 — Ajout d'un établissement au groupe (29/10/2026)

## Reproduction

Capture de l'utilisateur depuis `https://admin.klassci.com/admin/groups/1/edit`,
clic sur **Ajouter un établissement**, affiche `500 SERVER ERROR`.

**Cause de code identifiée** : `Group::tenants()` est une relation
`HasMany` (FK `tenants.group_id`), alors que
`TenantsRelationManager` utilisait `AttachAction` et `DetachAction`
(qui attendent un pivot `BelongsToMany`). Cette incompatibilité explique
la forte probabilité d'une erreur Livewire lors de l'ouverture du modal.
La **cause SQL exacte** de production n'est pas prouvée sans extrait
redacté des logs Laravel au même instant.

Filament 3 : pour `HasMany`, il faut associer/dissocier, pas
`attach`/`detach` sur un pivot. Ici des actions Filament **standard
`Action`** délèguent au service métier pour éviter toute mutation SSO
sans contrôle, au-delà d'un simple remplacement par `AssociateAction`.

## Fonctionnement après le correctif

- Bouton **Ajouter un établissement** : recherche Ajax du nom/code
  parmi les tenants existants et sans groupe ; liste SQL bornée à
  30 éléments, sans précharger les bases, tokens, emails ou mots de passe.
- Justification obligatoire (10–500 caractères), avertissement sur la
  visibilité dans le portail groupe, confirmation dans le modal.
- `GroupTenantMembershipService` revérifie la capacité effective
  `saas.groups.membership.manage` au serveur ; attribution contrôlée
  par `config/saas.php` ; défaut : seul `super_admin`.
- Transaction SQL + verrous des lignes groupe et tenant ;
  refus des groupes archivés/suspendus, tenants archivés,
  tenant déjà assigné et **transfert intergroupe silencieux**.
- Refus lorsqu'un rapprochement commercial est soumis/approuvé ou
  que l'instance est déjà reliée au registre SaaS.
- Journal `tenant_activity_logs` **dans la même transaction** :
  opérateur, tenant, groupe avant/après, motif et source ; toute erreur
  d'audit annule la modification.
- Bouton **Retirer du groupe** : confirmation, motif obligatoire, aucune
  suppression du tenant, aucun arrêt d'abonnement.
- Dans la fiche d'édition du tenant, `group_id` ne se change plus
  directement : passer par la fiche Groupe pour assurer la traçabilité.
  La création de tenants et les API externes ne sont pas modifiées.

## Déploiement

PR sans migration SQL. Tests automatisés prévus : PHP 8.3, PHP 8.4,
sécurité, modal Livewire, changement de groupe, journal, permissions,
tenant archivé, groupe suspendu, retrait.

Sur LWS : déployer le code seulement après CI verte et contrôle
du merge ; si la config a été mise en cache, exécuter
`php artisan config:clear` selon les procédures d'exploitation.
Tester la modale sur **un compte habilité**, puis effectuer un
rattachement de tenant indépendant approuvé. Ne pas exécuter
`migrate:fresh` ou `migrate:refresh`.

Si une 500 demeure, récupérer uniquement la portion **non sensible**
du fichier `storage/logs/laravel.log` correspondant au clic
(heure, exception, emplacement de code), sans inclure SQL avec
identifiants, mots de passe, tokens, stack trace nominative.
