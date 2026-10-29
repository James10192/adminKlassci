# Incident : accès membre du groupe, erreur 500 et sécurité des mots de passe

Signalé le 29/10/2026 : /groupe/definir-mot-de-passe retourne HTTP 500 et la modale d'édition membre montre un champ mot de passe. La cause serveur exacte n'est pas confirmée sans logs de production.

## Réparation
- Le mot de passe n'apparaît plus dans le formulaire d'édition d'un membre. La création possède un champ manuel uniquement si une invitation e-mail automatique n'est pas disponible.
- L'action Réinitialiser l'accès requiert la permission effective saas.groups.members.manage, valide la relation groupe-membre et l'état actif, et limite les tentatives. Elle crée un token signé expirant et met en file après commit un message d'invitation avec un nouveau secret temporaire, sans prétendre que le mail est livré.
- Si la file refuse le travail, la transaction de renouvellement est annulée ; aucun hash ou mot de passe n'est exposé à l'administrateur.
- L'écran de définition du mot de passe devient responsive, comporte deux boutons d'afficher/masquer accessibles, des erreurs claires, une règle de longueur partagée avec Laravel, no-store et régénération de session.

## Validation
- Tests de la vraie route GET/POST, création/édition Filament, permissions, reset, rate limit, comportement sur échec d'envoi et confidentialité.
- Aucune migration. Déployer après CI PHP 8.3/8.4 et sécurité vertes. Si la 500 persiste, récupérer les informations d'exception Laravel horodatées et expurgées des secrets.
- Pour les comptes sans email, ne pas inventer d'adresse ni montrer d'identifiants : employer une procédure d'identification manuelle validée.

Suivi https://github.com/James10192/adminKlassci/issues/131