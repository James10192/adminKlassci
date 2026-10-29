# Incident 500 et sécurisation des accès membres — 29 octobre 2026

## Diagnostic vérifié par les tests
La page /groupe/definir-mot-de-passe servait bien le formulaire GET. Après le POST, le contrôleur redirigeait vers le nom de route inexistant filament.group.pages.dashboard, déclenchant une RouteNotFoundException 500 (reproduit dans la CI). Le code utilise désormais le générateur d'URL officiel GroupDashboard::getUrl(panel: 'group'), pour les réponses GET et POST.
Le journal de production n'a pas été consulté : ceci confirme une cause de code du 500, pas l'absence de tout autre problème.

## Sécurité de l'édition des membres
- Le champ password n'apparaît jamais lors de l'édition, même dans le formulaire Livewire. Ne pas afficher le hash, même masqué. Création manuelle seulement si invitation automatique impossible.
- La liste présente l'état Accès (mot de passe défini, activation en attente, accès à initialiser) ; aucun mot de passe ou token affiché.
- L'action Réinitialiser l'accès est distincte et protégée par la capacité saas.groups.members.manage, configurable. Compte actif, email présent, même groupe, rate-limit et contrôle serveur requis.
- Une réinitialisation ne remplace PLUS immédiatement le mot de passe. Elle crée un token haché, stocke la date, et met en file après commit un mail contenant uniquement un lien signé expirant. Aucune valeur en clair n'est incluse dans l'e-mail.
- Le membre ouvre le lien signé, choisit son mot de passe personnel et confirme. Le POST revérifie le token avec verrou SQL, le consomme de manière irréversible et renvoie vers la connexion. L'ancien mot de passe continue de fonctionner tant que cette étape n'est pas terminée, même si la file mail échoue après commit.
- Liens anciens révoqués lors d'un nouveau reset, refus des comptes désactivés et de la réutilisation du lien, limitation de débit GET et POST.

## UX / confidentialité
- Page responsive avec palette claire/sombre, boutons afficher/masquer pour chaque champ, navigation clavier, messages d'erreur, validation Laravel/HTML cohérente, aucun mot de passe rempli en HTML.
- GET privé non mis en cache et Referrer-Policy no-referrer sur les URL signées, sans ressources tierces injectées par le formulaire.
- Rotation de la session après la première définition, refus d'utiliser la route d'activation pour changer plus tard un mot de passe sans contrôle du secret courant.
- Pas de reset administrateur par interface pour un membre sans email vérifié. Ne jamais afficher ses anciens identifiants ; utiliser le support vérifié hors interface.

## Déploiement
PR sans migration. CI PHP 8.3/8.4 + sécurité requises avant fusion. Les tests couvrent vrai GET/POST et redirection, modale Filament, hash jamais visible, MailFake, queue défaillante, token signé/à usage unique, faux groupe, compte inactif, rate limit, expiration.
Après déploiement du main, contrôler les deux parcours en staging/production et la configuration du worker de file mail. Ne pas partager de token, URL de récupération, hash ou fichier .env.

Journal principal : https://github.com/James10192/adminKlassci/issues/131