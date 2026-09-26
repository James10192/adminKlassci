# Changelog — adminKlassci

Toutes les évolutions notables de l'application maître (provisionnement, déploiement,
supervision des instances KLASSCI) sont consignées ici.

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), groupé par mois.
Sections autorisées : Ajouts, Améliorations, Suppressions, Corrections, Sécurité.

## Septembre 2026

### Améliorations
- Interface du panneau reprise pour l'équipe support :
  - couleur primaire au bleu KLASSCI : les liens, onglets et le menu actif sortaient en turquoise,
    et le libellé du menu actif était illisible sur son fond ;
  - bouton « Connexion » lisible (son texte sortait gris foncé sur bleu) ;
  - les pages « Réglages des établissements » affichaient des icônes géantes et des grilles
    empilées : leurs classes Tailwind n'existaient pas dans la feuille de Filament. Elles sont
    compilées dans `public/css/klassci-admin-utilities.css` (`node scripts/utilitaires-admin/generer.mjs`) ;
  - fiche établissement : les cinq opérations passent dans un menu « Opérations », le nom de l'école
    tient sur une ligne ; le jeton API est masqué (révélable) et n'est plus recopié en clair ;
  - une base d'école inaccessible donne une phrase utile (identifiants refusés, où les corriger)
    au lieu du message SQL brut, et la page ne reste plus bloquée sur « Chargement » ;
  - titres en français correct (« Journal d'activité », « Plans d'abonnement », « Demandes de
    support », « Sauvegardes », « Anomalies relevées ») et libellés anglais traduits ;
  - les plans illimités affichent le nombre d'inscrits seul au lieu de « 2 / 999 999 » ;
  - santé du parc : cartes de synthèse sobres, et plus de commande `php artisan` à taper dans les messages.

### Ajouts
- Les demandes KLASSCI Care s'annoncent dans un canal Slack (`CARE_SLACK_WEBHOOK`) : nouvelle
  demande, changement de statut ou de sévérité, assignation, réponse du support, réponse ou pièce
  jointe de l'école. Chaque message porte la référence (lien vers la demande), l'école, ce qui vient
  de se passer et qui l'a fait. Ne sortent jamais : le titre, la description, les messages, les pièces
  jointes (ils peuvent nommer un élève), ni une demande restreinte pour raison de sécurité. L'envoi
  part après la réponse à l'école : un Slack lent ou en panne ne retarde ni ne fait échouer une demande.
- API du CLI de l'équipe (`/api/cli/*`, commandes `klassci admin:*`) : établissements, santé,
  déploiements (avec leurs étapes), sauvegardes, demandes Care, journal ; relancer la santé, les
  stats, une sauvegarde, le scan des dossiers ; déployer une école ; requête SQL en lecture seule.
  Un jeton par membre (`php artisan cli:jeton <email>`), aux capacités de son rôle : facturation
  consulte, support opère et déploie, super_admin seul lit la base en SQL. Le rôle est revérifié à
  chaque appel (un membre rétrogradé perd ses droits sans révoquer ses jetons) et chaque action est
  journalisée à son nom. Les jetons expirent (180 jours par défaut, `--jours`). La lecture SQL
  passe par un utilisateur MySQL dédié qui n'a que SELECT sur la base maître, sans les colonnes
  d'identifiants (`DB_LECTURE_*`, `CLI_SQL_CONNEXION=lecture`) : tant qu'il n'existe pas, elle
  reste fermée (503), car un alias ou un UNION déjouent tout filtre posé sur le texte d'une
  requête. Elle n'admet qu'une instruction de lecture, sans commentaire, dans une transaction en
  lecture seule.
- `care:fonctionnalites <code>` affiche, active ou désactive les fonctionnalités KLASSCI Care d'une
  école (`--activer=tout`, `--desactiver=support_screenshot`). Elles étaient désactivées par défaut
  et ne s'activaient qu'en écrivant dans `tenant_features` à la main : une école munie de son
  identifiant n'affichait donc toujours pas le bouton d'aide. Seul ce qui change réellement est
  journalisé ; l'école le voit sous 5 minutes (cache du bootstrap).

### Corrections
- La santé du parc déclarait des écoles en panne qui tournaient. Trois causes :
  - le dossier d'une école était deviné à partir de son code ; ISLG (code `islg`, dossier
    `islg-rostan`) et Imertel apparaissaient « Critique — Répertoire introuvable », et leurs
    sauvegardes échouaient. Le dossier réel s'enregistre désormais dans la fiche (« Dossier sur le
    serveur ») et « Actualiser les tenants » le rattache seul d'après l'adresse du site. La santé, la
    sauvegarde, le déploiement et la détection de branche le lisent ;
  - la sonde ne cherchait que `laravel.log`, alors que les écoles écrivent un journal par jour depuis
    la rotation de septembre : six écoles à jour étaient « Dégradées — aucun fichier de log » ;
  - la connexion de test à la base était gardée ouverte d'une école à l'autre : chaque école était
    déclarée saine avec la base de la première vérifiée.
- Les déploiements et sauvegardes mis en file (boutons du panneau, webhook GitHub) n'étaient jamais
  exécutés : ni table `jobs`, ni tâche pour vider la file. Le planificateur la vide désormais chaque
  minute (`queue:work --stop-when-empty --tries=1`), et le délai de reprise passe de 90 s à 35 min :
  avec 90 s, un déploiement encore en cours aurait été relancé par-dessus lui-même.
- Deux déploiements de la même école pouvaient s'enchaîner (un depuis le panneau, l'autre depuis le
  webhook). Tous les points d'entrée passent désormais par une même demande, sous verrou, qui
  refuse tant qu'un déploiement de l'école est en attente ou en cours. Les boutons « Déployer » de la
  fiche école et de l'onglet Déploiements le faisaient dans la page, coupée à 30 s par l'hébergeur
  en plein déploiement : ils le mettent en file. Le webhook répond 404 pour un code d'école inconnu.
- La sortie d'un déploiement consultée par le CLI masque le jeton qu'une URL de dépôt privé peut
  porter.
- `tenant:verifier-restauration` n'avait jamais abouti en production : sur cPanel, l'utilisateur MySQL
  d'une instance ne peut pas créer de base, et la commande commençait par `DROP/CREATE DATABASE`.
  La base d'essai est désormais créée une fois dans cPanel (`SAUVEGARDE_BASE_ESSAI`, partagée par toutes
  les instances) et vidée table par table avant et après chaque essai. Trois verrous avant d'y toucher :
  nom sûr, suffixe `_verif_restauration`, aucune instance déclarée sur cette base.
- La restauration d'essai retire les clauses `DEFINER` du dump : une vue créée par un autre compte
  faisait échouer la restauration faute de privilège SUPER.
- La raison d'un échec de vérification est tronquée à la taille de sa colonne et garde la ligne
  `ERROR` du client MySQL. Une raison trop longue faisait échouer l'enregistrement du verdict lui-même.
- `tenant:cleanup-backups` ne supprime plus le dossier de sauvegardes d'une instance. `backup_path`
  désigne ce dossier, et le nettoyage le supprimait en entier pour une seule archive expirée :
  celle de la nuit partait avec. Seuls les fichiers de la sauvegarde expirée (et leur sceau) sont retirés.

### Sécurité
- Injection de commande par le nom de branche fermée sur le déploiement des tenants.
  `tenant:deploy` n'exécute plus aucune commande dans un shell : git, composer, artisan et chmod
  reçoivent leurs arguments sous forme de liste (`Process::run([...])`).
- Nom de branche validé à chaque entrée (`App\Support\Git\NomDeBranche`, règle `NomDeBrancheGit`) :
  webhook `POST /api/deploy` (422 via `DeployWebhookRequest`), formulaires Filament de déploiement
  et de tenant, option `--branch` de `tenant:deploy` et `tenant:provision`, branche enregistrée en base.
- `tenant:deploy` vérifie que la branche existe sur origin (`git ls-remote`) avant la mise en maintenance.
- Workflow GitHub `deploy-tenant.yml` : les entrées du formulaire ne sont plus collées dans le script
  (passage par `env:`, charge utile construite par `jq`).
- `tenant:provision` ne relit plus aucune valeur dans un shell local : commandes en argv. En production,
  la commande transmise à ssh est citée mot par mot (`App\Support\Shell\CommandeDistante`).
- `tenant:provision` refuse avant toute écriture un code ou un sous-domaine hors étiquette DNS
  (code limité à 54 caractères pour tenir dans un nom de base MySQL), et un nom d'établissement contenant
  `"`, `\`, `$` ou un caractère de contrôle, qui aurait pu ajouter des clés au `.env` de l'école.
- Archives de sauvegarde authentifiées. Le chiffrement AES-256-CBC n'empêchait pas de modifier une
  archive sans la clé : elle se déchiffrait quand même. Chaque archive chiffrée reçoit désormais un
  sceau HMAC-SHA256 (fichier `.sceau`, copié hors site avec elle), calculé avec une clé dérivée de
  `SAUVEGARDE_CLE` et lié au nom du fichier (`App\Domain\Exploitation\Sauvegarde\SceauSauvegarde`).
- `tenant:verifier-restauration` vérifie le sceau avant de déchiffrer, et refuse une archive modifiée
  ou une sauvegarde scellée dont le sceau a disparu (colonne `tenant_backups.est_authentifie`).
  Les archives prises avant le scellement se relisent encore, avec un avertissement.

