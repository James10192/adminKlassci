# Incident — bouton « Ajouter un membre » grisé dans Groupe

Capture signalée le 29 octobre 2026 : sur `/admin/groups/1/edit`, « Ajouter un établissement » est bleu, « Ajouter un membre » est gris et présenté comme non cliquable. L'utilisateur est administrateur du master.

## Diagnostic du code

Le rôle `super_admin` du fichier `config/saas.php` contient **les deux** permissions `saas.groups.membership.manage` et `saas.groups.members.manage`. `GroupWorkspaceActions::addMember()` utilisait `->color('gray')` *même quand autorisée*, et `->disabled(! Gate::allows(...))`. La capture ne permet donc pas à elle seule de déterminer si le bouton est désactivé ou simplement de couleur secondaire.

La PR précédente a introduit la nouvelle permission membres **après** la permission rattachement : un cache `bootstrap/cache/config.php` datant d'un ancien déploiement peut conserver la première permission sans contenir la seconde. Une vraie interdiction est également possible si le compte n'a pas le rôle/configuration attendus. Il serait dangereux de contourner les permissions ou de se fier au seul titre d'« admin ».

## Correctif UX et sécurité

- Boutons autorisés avec couleur `primary`, uniforme : plus de faux état grisé.
- Si le groupe est suspendu ou qu'une permission manque, le bouton ouvre une **modale explicative**, sans formulaire ni action de soumission. Le serveur garde le Gate dans `GroupMemberCreator` et `GroupTenantMembershipService` ; cliquer sur la modale ne modifie aucune donnée.
- `GroupWorkspacePermissionInfo` centralise le diagnostic d'interface : groupe archivé/suspendu, compte inactif, permission manquante ou décalage avec le Gate. Pas de rôles codés en dur ni d'élévation automatique.
- Commande `php artisan saas:permissions:diagnose` : inspection **lecture seule** du cache de configuration, des rôles configurés et des deux permissions. En option `--admin-id=N` pour tester les Gates d'un compte master par ID interne, sans afficher son e-mail, mot de passe ni token. Ne modifie pas les rôles.
- Tests Livewire réels sur `EditGroup` : super_admin, support, cache de configuration fictivement périmé, groupe suspendu, refus du service malgré une requête artificielle. Tests de commande de diagnostic.

## Vérifier la production LWS (après fusion et déploiement)

Dans le répertoire réel d'adminKLASSCI :

```bash
git pull --ff-only
php artisan saas:permissions:diagnose
php artisan optimize:clear
php artisan saas:permissions:diagnose
```

La deuxième commande permet de constater l'état initial ; la dernière confirme l'état après purge. **Ne pas mettre à jour le rôle du compte uniquement pour faire disparaître le grisé** et ne pas désactiver le Gate. Si un processus PHP Octane, OPcache ou le serveur conserve un ancien artefact, suivre la procédure d'exploitation adaptée sans purge de base de données.

Le test CI PHP 8.3/8.4 et l'audit sécurité ne remplacent pas une inspection du compte et de la configuration du serveur LWS. La PR n'introduit aucune migration SQL ni changement d'abonnement.

Suivi : https://github.com/James10192/adminKlassci/issues/131
