# KLASSCI Care — Interventions métier liées aux tickets

## État et principe de sécurité

Ce document décrit la cible **non encore activée** du workflow inter-rôles. Un ticket a un **rapporteur**, mais une intervention peut viser une **autre personne habilitée**. Ne pas confondre « ACTION_REQUISE » (réponse du rapporteur) et intervention à l'école. En phase initiale, l'état \`PENDING_ROUTING\` garantit qu'aucune alerte mensongère ne part vers un agent.

Références : OWASP Authorization Cheat Sheet (deny by default, permissions à chaque requête), Laravel 12 Queues/afterCommit, Spatie Permission (permissions effectives, multi-rôles). Liens : https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html ; https://laravel.com/docs/12.x/queues ; https://spatie.be/docs/laravel-permission/v6/basic-usage/direct-permissions

## Autorité de chaque système

| Donnée / responsabilité | AdminKlassci Master | Instance KLASSCIv2 |
| --- | --- | --- |
| Ticket, identifiant externe, journal technique, intervention, statut | Autorité centrale | Projection strictement bornée à son instance |
| Exigence de permission, titre d'intervention, consigne | Crée et versionne | Ne l'utilise que pour sélectionner le candidat |
| Utilisateur réel, actif, droits effectifs, propriété d'un dossier métier | Ne peut jamais les confirmer | Seule autorité : \`User::can\` et politiques métier |
| Affectation et exécution | N'accepte qu'un accusé autorisé de l'instance | Contrôle à chaque lecture/écriture et juste avant l'action |
| Notification métier | Déclenche une intention versionnée | Envoi dans l'app, e-mail vérifié en option ; preuves indépendantes |
| Validation d'inscription, paiement, note | Aucun droit direct | Appelle le service métier normal avec ses propres contrôles |

Le Master ne doit **jamais** considérer \`target_external_user_id\`, un nom de rôle ou une permission textuelle comme preuve d'habilitation. Les ID utilisateurs ne sont uniques que dans l'instance/tenant. L'instance ne doit **jamais** accepter \`tenant_id\` ou \`reporter_id\` comme donnée autoritative du navigateur.

## Modèle d'intervention

- ID, ticket, instance (dérivée du ticket), idempotency_key unique par ticket et empreinte du contenu ;
- \`title\` (étiquette courte), \`instructions\` (sans secret ni pièce de dossier), \`required_permission\` (clé explicite), \`target_external_user_id\` optionnel ;
- transitions prévues : \`PENDING_ROUTING\` -> \`WAITING_ASSIGNEE\` -> \`IN_PROGRESS\` -> \`COMPLETED\` ; \`CANCELLED\` possible depuis un état ouvert ; \`NEEDS_REASSIGNMENT\` en cas de retrait de permission ou compte désactivé ; l'échec technique ne doit jamais être traité comme réussite ;
- le ticket n'est **pas résolu automatiquement** à la complétion d'une intervention : les autres interventions peuvent rester ouvertes ; le support confirme la résolution.
- toute mutation écrit une preuve append-only avec auteur, tenant, action, transition, motif, date et référence de preuve métier non sensible ;
- les données d'intervention ne sont pas des messages publics ni des notes internes : elles ont leur propre politique d'accès.

## Contrat API proposé (v2, à implémenter avant activation)

1. \`GET /v1/support/interventions?cursor=\` uniquement avec identité d'instance \`support:read\`, retour paginé/borne, version d'événement, tombstones pour annulation, sans secrets. Les interventions restreintes restent interdites.
2. \`POST /v1/support/interventions/{id}/ack\` uniquement avec la portée dédiée \`support:interventions\`, ID d'opérateur authentifié *côté instance*, permission revalidée, preuve signée de l'instance, Idempotency-Key. Si 0 candidat -> \`UNASSIGNABLE\`, si >1 -> \`NEEDS_ASSIGNMENT\` sans diffusion de masse. Un \`target_external_user_id\` explicite et non habilité est **refusé**, sans repli automatique.
3. \`POST /v1/support/interventions/{id}/complete\` avec version attendue / contrôle d'optimistic concurrency + Idempotency-Key ; l'instance revalide la permission, l'état et la propriété de l'élément métier. Interdiction de terminer une action à partir de la seule lecture d'une notification.
4. \`POST /v1/support/interventions/{id}/cancel\` contrôlé par acteur autorisé ; révocation de notifications et de liens ; \`409\` sur modification concurrente, \`404\` sur autre tenant et \`403\` si permission insuffisante.
5. Aucune API Master ne fournit les détails complets d'un ticket à une personne qui a uniquement la permission d'exécuter une intervention. L'instance expose une page **intervention limitée**, non le ticket entier.

## Résolution des destinataires côté instance

- Résoudre sur une seule base d'établissement, utilisateur \`active\` et non supprimé, droit effectif \`can(required_permission)\` ; réévaluer à la consultation et à l'action.
- Un seul candidat => affectation possible ; plusieurs => sélection explicite, pas de notification collective par défaut ; aucun => en attente avec alerte support.
- Le demandeur reçoit un message d'information **sans demande d'action personnelle** lorsque la scolarité doit agir. Le responsable identifié reçoit une tâche séparée, un lien court authentifié vers l'action autorisée ; aucune donnée privée ne sort dans l'email.
- Il est interdit de réattribuer à une personne non habilitée ou d'utiliser la permission \`support.tickets.view_school\` comme substitut à \`inscriptions.validate\`, \`notes.edit\`, etc.

## Livraison fiable / observabilité

- Outbox **transactionnelle** par (instance, intervention, événement, version, destinataire, canal) avec index unique, retries exponentiels bornés, état \`PENDING/SENT/FAILED/READ\` distinct de \`ASSIGNED/COMPLETED\`.
- Les jobs doivent être dispatchés **après commit** ; un plantage après l'écriture d'un curseur ne doit pas faire disparaître une alerte. Lire n'implique pas exécuter.
- Email seulement à l'adresse vérifiée ; pas de confirmation « envoyé » tant que le canal n'a pas acquitté. WhatsApp/SMS opt-in, paramétrables, sans données sensibles dans le message. On conserve l'historique de l'échec, la reprise et l'expiration.
- Rappels configurables (SLA, escalade) et idempotents ; aucun automatisme qui clôture un ticket parce qu'un délai est écoulé.
- Traçabilité : demandée, routée, notifiée, lue, acceptée, exécutée, contrôlée, résolue, confirmée/rejetée.

## UX — écrans à implémenter

Dans **adminKlassci**, action Filament distincte \`Demander une intervention à l'établissement\` (et non un toggle dans « Répondre ») : module/permission du catalogue **remonté par l'instance**, destinataire filtré par permission, consigne, échéance, aperçu du destinataire réel, confirmation, état d'acheminement. \`shadcn/ui\` uniquement si le frontend concerné l'utilise ; ici, privilégier les composants Filament natifs existants.

Dans **KLASSCIv2** : centre de tâches \`Mes interventions\` avec carte compacte, bouton \`Ouvrir l'élément\`, actions \`Prendre en charge\`, \`Marquer réalisée\`, \`Impossible / transférer\`, détail minimal autorisé. Le rapporteur garde une timeline informative : \`En attente de scolarité\` -> \`Action effectuée, vérification en cours\` -> \`Résolu, confirmer / problème persistant\`. Le vocabulaire de statut distingue **attente du rapporteur** et **attente d'un tiers**.

## Ordre et conditions de fusion

- Étape A (présente PR Master) : migration additive + service de préparation **non connecté aux UI ni à l'API**, tests de permissions, idempotence, sécurité et journaux.
- Étape B (présente PR instance) : résolution des destinataires (permission effective, inactifs/ambiguïtés/refus), correction de la priorité du message \`ACTION_REQUISE\`. Aucune livraison d'intervention avant contrat v2.
- Étape C : protocole API authentifié, pagination, minimisation, permissions renouvelées, gestion des statuts, tests inter-tenant.
- Étape D : outbox durable, notifications et reprise ; tests de panne après commit, révoqué, destinataire supprimé, deux workers concurrents.
- Étape E : UI Filament et KLASSCIv2, confirmations, historique et tests E2E mobile/dark mode.
- Étape F : pilote un établissement, instrumentation et vérification d'une action réelle ; activation derrière feature flags après migrations de chaque instance.

**Interdiction de fusionner A seule en pensant qu'elle envoie déjà une tâche. Interdiction d'activer les notifications inter-rôles avant C–F et les tests de sécurité.**