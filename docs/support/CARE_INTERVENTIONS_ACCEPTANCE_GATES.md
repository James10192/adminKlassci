# KLASSCI Care — contrat d'exécution et recette bloquante (après fondations)

Suivi : [Master #149](https://github.com/James10192/adminKlassci/issues/149) et [KLASSCIv2 #1715](https://github.com/James10192/KLASSCIv2/issues/1715).

> **Non implémenté par ce document.** Ne pas activer `CARE_INTERVENTIONS_ROUTING_PREVIEW_ENABLED`, les notifications ou les migrations en production sur la seule base de ce contrat. Les PR #148 et #1714 sont fusionnées, mais le workflow reste incomplet.

## Autorités et invariant

Le Master possède ticket, intervention, transitions et journal central. L'instance possède utilisateurs, état actif, permission effective, politiques métier et preuve d'exécution. L'identifiant de l'instance authentifiée détermine le tenant ; aucun `tenant_id`, `reporter_id`, `actor_id` ou rôle fourni par le navigateur ne constitue une autorisation. Chaque mutation contrôle ticket non restreint, instance, état ouvert, version attendue, idempotence et droits frais.

## Machine d'états proposée

`PENDING_ROUTING -> WAITING_ASSIGNEE -> IN_PROGRESS -> COMPLETED -> VERIFIED_BY_SUPPORT -> RESOLVED -> CONFIRMED`.

Variantes explicites : `UNASSIGNABLE` (0 candidat), `NEEDS_ASSIGNMENT` (>1), `NEEDS_REASSIGNMENT` (désactivation/perte permission), `CANCELLED`, `REOPENED`. Les erreurs de transport sont **des états de livraison**, jamais des états métier. Un ack technique ne vaut ni acceptation, ni exécution. Ne jamais avancer un ticket sur simple ouverture d'email ou délai SLA. Si plusieurs interventions sont ouvertes, aucune résolution automatique du ticket.

## Contrat réseau et transactions obligatoires

1. Versionner l'API et publier un schéma vérifiable ; scopes `support:read` distincts de `support:interventions`. Chaque requête de mutation possède une clé d'idempotence, empreinte du payload, version attendue et identité d'instance serveur. Retour `409` pour concurrence et payload différent à clé identique, `404` pour autre instance, `403` pour droit absent.
2. Livraison par **outbox transactionnelle** avec contrainte unique (tenant, intervention, version événement, destinataire, canal), écriture dans la même transaction que le changement métier et dispatch après commit. Worker à bail/lock, retries exponentiels bornés et file d'échecs inspectable ; un crash entre envoi et ack doit permettre la déduplication côté destinataire.
3. Séparer `PENDING/SUBMITTED/DELIVERED/FAILED/READ` par canal de `ASSIGNED/CLAIMED/COMPLETED`. `SENT` n'implique pas `DELIVERED` et `READ` n'implique pas `CLAIMED`. Aucun message ne doit prétendre que le responsable a été averti avant preuve transport.
4. L'instance ne renvoie un ack d'affectation que lorsque l'utilisateur actif et ses permissions sont revérifiés. Le Master enregistre une référence d'acteur *assermentée par l'instance* sans croire un identifiant transmis par le client web. Révoquer automatiquement les liens d'action quand l'affectation devient invalide.
5. Une complétion exige la preuve issue du service métier normal (type, ID opaque et résultat de transaction), plus date, acteur, instance, idempotence et version. Interdire la complétion d'une tâche de validation d'inscription ou de notes sur une simple déclaration textuelle.
6. Une action d'agent ne peut pas être adressée au rapporteur par défaut. Informer le rapporteur des changements d'état sans révéler les consignes, notes internes, autre personne, identifiants ou documents protégés. Ne demander une confirmation au rapporteur **qu'après** la vérification du support, avec option « problème persistant » qui réouvre sans dupliquer l'action exécutée.

## Matrice de recette bloquante

| Scénario | Résultat exigé |
| --- | --- |
| Aucun candidat habilité | UNASSIGNABLE, alerte au support, aucun envoi |
| Deux candidats habilités | NEEDS_ASSIGNMENT, sélection par opérateur habilité uniquement |
| Cible explicite non habilitée/inactive/supprimée | Refus, aucun repli ni diffusion |
| Droit retiré après notification ou après claim | Révocation immédiate, NEEDS_REASSIGNMENT, action 403 |
| Changement de propriété dossier métier | Contrôle métier échoue, aucune complétion |
| Route directe vers la tâche d'autrui | 404/403 sans fuite de métadonnées |
| Tentative inter-tenant, pièce jointe, référence manipulée | 404 et zéro donnée étrangère |
| Double clic, deux workers, ack retardé/rejoué | Une seule mutation, une seule intention d'envoi |
| Crash après commit, Master indisponible, mail en échec | Reprise bornée et traçable ; aucun faux succès |
| Clic sur email ou lecture cloche | READ uniquement, jamais CLAIMED/COMPLETED |
| Ticket clos/restreint ou intervention annulée | Aucun travail ou lien actif, événement de révocation |
| Une intervention complétée, autre en attente | Ticket reste ouvert |
| Support vérifie et résout, rapporteur conteste | Réouverture journalisée sans nouvelle exécution automatique |
| Profil multi-rôles, superadmin, permissions héritées | `User::can` réel et politique métier, jamais `hasRole` |
| Interface mobile, dark mode et réseau lent | Statuts exacts, boutons inactifs appropriés, pas de reload, feedback sûr |
| Schéma MariaDB/MySQL, migrations par instance | Transactions, contraintes uniques, rollback et compatibility prouvés |

## Critères de livraison

- PRs coordonnées Master + instance avec revue des diffs, tests PHP/CI/sécurité, tests de concurrence sur vraie DB MySQL/MariaDB et recette HTTP/UI.
- Actions et autorisations configurables, aucun hardcode de rôle, pas de classe God Object ; sur Master composants Filament natifs, côté instance les composants déjà adoptés et AJAX sans rechargement.
- Pilote sous deux flags indépendants (publication des intentions et consommation des tâches), activés uniquement après déploiement compatible des deux côtés, migrations contrôlées, workers/cron, capacité à couper sans perdre d'événement.
- Tableau opérationnel : tâches sans destinataire, attente d'acceptation, SLA dépassé, échecs de transport, retentatives, réassignations, preuves, vérification support, confirmations et réouvertures.
- Ne pas fermer les épics #149/#1715 avant la démonstration d'une action réelle de bout en bout et sa preuve.
