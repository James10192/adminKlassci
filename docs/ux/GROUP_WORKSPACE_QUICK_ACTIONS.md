# Groupes : espace de travail rapide et modales intégrées

## Problème utilisateur observé
Sur la capture du portail Master, le groupe affiche deux onglets. « Ajouter un membre » est visible ; « Ajouter un établissement » a disparu. La fiche longue impose aussi de parcourir deux sections de formulaire avant de retrouver les relations.
Le code affichait « Ajouter un établissement » seulement avec la capacité effective saas.groups.membership.manage et le gestionnaire de relation était entièrement read-only lorsque cette capacité manque. Cela pouvait masquer l'action sans indiquer pourquoi. La capture seule ne prouve pas les permissions et le cache effectifs sur LWS.

## Nouveau parcours
- Cliquer sur une ligne Groupe ouvre directement l'espace Gérer le groupe, plutôt qu'une série d'écrans séparés.
- Sur Voir et Modifier un groupe : boutons persistants en tête Ajouter un établissement et Ajouter un membre, chacun ouvrant une modale Filament 3. Ils restent visibles mais désactivés avec une explication si le compte n'est pas habilité ou le groupe suspendu.
- En édition, sections Informations et Identité visuelle repliables et fermées par défaut : tableaux Établissements et Membres accessibles beaucoup plus haut sur la page ; bouton de sauvegarde également en tête.
- Les gestionnaires des deux onglets restent actifs en lecture et ne masquent plus toutes les actions par un isReadOnly() global ; chaque action garde une vérification d'autorisation.
- Une création dans une modale émet un événement Livewire pour rafraîchir les tableaux sans actualiser la page.
- Formulaire d'association unique réutilisé dans les deux interfaces, recherche SQL bornée (30), uniquement tenants existants sans groupe, et justification obligatoire.
- Formulaire membre unique réutilisé pour créer et modifier ; aucun mot de passe haché ne doit être exposé en édition. La création passe par GroupMemberCreator et vérifie le rôle, l'état du groupe, la permission effective et le vrai group_id du serveur.

## Contrats et isolation
Le rattachement passe uniquement par GroupTenantMembershipService : journal transactionnel, refus transfert d'un groupe vers l'autre, blocage des archivés ou des rapprochements approuvés, aucun changement d'abonnement/SSO implicite. Les écoles Collège gérées par un backend FastAPI ne sont pas faussement créées comme tenants Laravel.
Créer un membre n'accorde aucun droit d'administrateur master. Les permissions respectent config/saas.php ; aucune règle basée sur nom Rostan/ISLG/USAT.

## Livraison
Aucune migration. Vérifier la CI Pest sur PHP 8.3/8.4 et Sécurité. Valider ensuite en vrai navigateur : admin habilité, support lecture seule, groupe suspendu, liste vide, groupes avec 50+ écoles, sélection via clavier, bascule onglets, effet AJAX et modal responsive.
Fusion GitHub ne signifie pas déploiement sur LWS : git pull --ff-only, procédure de cache Laravel/Filament, vérifier les permissions avec rôle réel et éventuel config:cache selon procédure. Ne pas élargir la permission à tous pour rendre le bouton visible.