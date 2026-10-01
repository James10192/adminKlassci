# Retours Nanan, annonces Slack et suivi par l'école

Octobre 2026. Ce qui fait remonter au Master les 👍 / 👎 donnés dans les écoles
sur les réponses de Nanan (l'assistant IA), ce qui part sur Slack, et ce que
l'école interroge pour prévenir ses utilisateurs quand le support répond.

## Pourquoi rien n'apparaissait

L'école enregistrait les retours dans sa propre table (`assistant_retours`,
`App\Domain\Assistant\Retours\EnregistrerRetour` côté KLASSCIv2) et s'en servait
pour router la conversation. Rien n'était transmis au Master : il n'existait ni
point d'entrée, ni table, ni écran. Seul « Signaler à KLASSCI Care » depuis une
réponse créait une demande (visible dans *Demandes*).

## 1. POST /api/v1/support/retours-assistant

- Authentification : identifiant d'instance (`Authorization: Bearer <key_id>.<secret>`),
  portée `support:create`, limite `care-ecriture`.
- En-tête **`Idempotency-Key` obligatoire** (8 à 64 caractères `[A-Za-z0-9._:-]`), sinon 400.

Corps (contrat partagé avec l'émetteur de l'école, ajouts facultatifs seulement) :

```json
{
  "avis": "utile | pas_utile",
  "raison": "string|null (≤ 60)",
  "commentaire": "string|null (≤ 1000)",
  "question": "string (≤ 2000)",
  "reponse": "string (≤ 4000)",
  "modele": "string|null",
  "page": "string|null (≤ 255)",
  "utilisateur": { "id": 42, "nom": "Awa Koné", "role": "secretaire|null" },
  "conversation_ref": "string|int|null",
  "message_ref": "string|int",
  "donne_le": "2026-10-01T09:30:00+00:00"
}
```

Réponses : `201 {"id": 12}` (créé ou mis à jour), `200 {"id": 12}` avec
`Idempotent-Replayed: true` (même clé déjà reçue), `422 {"error":"validation_failed", ...}`.

Idempotence double : par (instance, clé) — rien n'est réécrit ; et par
(instance, `message_ref`, `utilisateur.id`) — l'école envoie `care_uuid` pour la
première version puis `care_uuid:N` quand l'avis change : la nouvelle version
**met à jour** la ligne. La plus récente selon `donne_le` l'emporte ; une version
plus ancienne arrivée en retard n'écrit rien et répond `200` + `Idempotent-Replayed: true`.
Un avis qui change rouvre le retour.

## 2. Panneau : Support → Retours Nanan

Lecture seule, capacités `support.tickets.view` (lire) et `support.tickets.manage`
(agir). Badge : 👎 non traités des `care.retours_assistant.badge_jours` derniers jours.
Onglets « 👎 à lire », « Avec commentaire », « Tous » ; taux de satisfaction sur
`care.retours_assistant.satisfaction_jours`. La fiche montre la question et la
réponse complètes, avec deux gestes :

- **Marquer traité** (note interne facultative) ;
- **Créer une demande** : passe par `CreerTicket`, au nom de la personne qui a
  donné l'avis. Elle la voit dans « Mes demandes » et reçoit la réponse du support.

## 3. Slack

Variable : `CARE_SLACK_WEBHOOK` (URL d'un *Incoming Webhook* lié au canal).
Sans elle, rien ne part. Annoncés :

- demandes : création, statut (dont résolue / close), sévérité, assignation,
  réponse du support, réponse ou pièce jointe de l'école ;
- retours Nanan : chaque 👎, et chaque 👍 qui porte un commentaire
  (`CARE_SLACK_RETOURS_PAS_UTILE`, `CARE_SLACK_RETOURS_UTILE_COMMENTES`, vrais par défaut).

Chaque annonce porte un bouton **« Ouvrir dans adminKlassci »**. Ni titre, ni
description, ni question, ni réponse, ni commentaire ne sortent : ils peuvent
nommer un élève. L'envoi part après commit et après la réponse à l'école, et ne
bloque jamais.

## 4. Suivi par l'école (notifications in-app / mail)

L'école interroge toutes les 5 minutes :

```
GET /api/v1/support/tickets?scope=school&mis_a_jour_depuis=<ISO8601>&page=N
```

- `reporter` est facultatif en `scope=school` (requis en `scope=mine` et pour toute écriture).
- Statuts terminaux côté école : `statut.code` = `RESOLU` (résolue) ou `FERME`
  (close, rejetée ou doublon).

- `mis_a_jour_depuis` : demandes dont `updated_at` est **strictement** postérieur ;
  ne peut remonter à plus de `care.limites.mis_a_jour_depuis_jours_max` (30) jours (422 sinon).
- Une réponse **publique** du support et tout changement de statut touchent
  `updated_at` ; une note interne ne touche rien.
- Chaque résumé porte désormais `derniere_reponse_support_le` (ISO8601 ou `null`) :
  l'école le compare à sa dernière lecture pour décider de notifier.

## 5. Suite : mode « app Slack » (non livré)

Répondre ou résoudre **depuis Slack** demande une app Slack plutôt qu'un webhook :

- `CARE_SLACK_BOT_TOKEN` (scopes `chat:write`, `reactions:read`), `CARE_SLACK_SIGNING_SECRET` ;
- poster par `chat.postMessage`, garder le `ts` du message sur la demande (colonne
  `slack_thread_ts`) pour y fil-er les annonces suivantes ;
- route `POST /api/slack/care/evenements` : vérifier la signature
  (`X-Slack-Signature`, `X-Slack-Request-Timestamp` à ±5 min), répondre au
  `url_verification` ;
- `config('care.slack.membres')` : `slack_user_id → saas_admins.id`. Seul un membre
  listé agit ; un message dans le fil devient une réponse **publique** via
  `RepondreTicket` (attention : il part à l'école tel quel), la réaction
  `:white_check_mark:` franchit `Resolved` par `TicketStateMachine`.

Non livré ici : une réponse publique écrite dans Slack part à l'école sans relecture
dans le panneau, et la règle « rien de sensible ne sort » devrait être revue pour
un canal où l'équipe écrit elle-même. À trancher avant de l'activer.
