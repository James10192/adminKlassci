# Contrat MailPulse — fondation du control-plane KLASSCI (L1)

Suivi central : https://github.com/James10192/adminKlassci/issues/131

## Source de vérité

Toute communication KLASSCI (université, collège, acquisition, portail administrateur)
passe par **MailPulse** (James10192/mailpulse), jamais par le fournisseur Resend directement.
Resend reste un transport interne possible à MailPulse.

Sources examinées le 29 octobre 2026 :
- MailPulse : src/app/api/v1/messages/route.ts exige l'en-tête Idempotency-Key (max 255)
- MailPulse : src/lib/mailpulse/schemas.ts définit le schéma des messages et contacts
- Collège : app/services/mailpulse/client.py existe déjà, avec dettes d'idempotence/consentement
- adminKLASSCI : services.php ne référençait pas MailPulse avant ce lot

## État de ce lot

Le client PHP App/Services/MailPulse/MailPulseClient.php est **inactif par défaut**.
Il ne change PAS les mails existants du portail groupe et n'active aucune campagne.
Aucun cron/job n'est attaché, aucune donnée utilisateur n'est synchronisée.
Il fournit seulement sendEmail et upsertContact pour la future outbox testée.

Variables :
- MAILPULSE_ENABLED=false
- MAILPULSE_BASE_URL=https://... (URL serveur de MailPulse, sans identifiants)
- MAILPULSE_API_KEY=... (clé API d'application propre à KLASSCI, jamais exposée au client)
- MAILPULSE_TIMEOUT=10

Sécurité : HTTPS obligatoire, redirections HTTP désactivées, clé Bearer uniquement
en serveur, fail-closed si absent ou désactivé, aucune fuite de réponse brute.
Les callbacks n'envoient pas automatiquement d'emails; seules les commandes
explicites avec idempotency key utilisent le réseau.

## Lot suivant obligatoire avant activation

1. Table événements/outbox, transaction commit avant dispatch, clé stable par
   événement + destinataire + canal, traitements asynchrones et reprise.
2. Consentement : le serveur MailPulse crée actuellement un nouveau contact avec subscribed=true si le champ est absent. Le client PHP refuse donc désormais l'upsert sans subscribed explicite : fournir false pour un nouveau contact transactionnel, ou l'état de consentement déjà enregistré afin de ne pas écraser un opt-in existant. Corriger aussi la valeur par défaut chez MailPulse avant toute synchronisation large.
3. Mapping événements métier -> templates, statuts, quotas et tenant/produit.
4. Webhook signé MailPulse pour délivrabilité + dead-letter/retry/backoff.
5. Tests intégration et isolation multi-tenant; pilote sur staging; rollback.
6. Garder le SMTP existant tant que la migration des notifications opérationnelles
   n'a pas été testée et validée sur les deux produits.

## DevOps (ne pas activer avant lot suivant)

Créer un worker indépendant de queue et un scheduler dédié.
Attention : cron toutes les minutes ET scheduler applicatif exécuté ailleurs
créent des doublons si les jobs ne sont pas idempotents.
Le VPS Contabo existant doit être inventorié (RAM/CPU/IO/SSL/backup) avant
de fixer les ressources et définir Dokploy.
