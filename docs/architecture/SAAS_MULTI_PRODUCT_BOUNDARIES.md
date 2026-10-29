# KLASSCI multi-produit — frontières et contrats (exploration du 29 octobre 2026)

Épic : https://github.com/James10192/adminKlassci/issues/131
Nanan Collège : https://github.com/African-DC/klassci-college-backend/issues/535

Ce document est un **état de découverte**, pas une attestation de déploiement.
Il doit être mis à jour à chaque étape : HEAD dépôt, chemins vérifiés, tests,
résultats, écart ouvert, décision de migration et rollback.

## 1. Frontières d'autorité

| Capacité | Autorité cible | Situation observée | Transition sûre |
| --- | --- | --- | --- |
| Client/organisation/établissement | adminKLASSCI | modèle Tenant lié à KLASSCIv2 | modèle additif multiproduit |
| Catalogue plans, droits et quotas | adminKLASSCI | plans et paywalls locaux partiellement divergents | contrat entitlements versionné, lecture locale résiliente |
| Contrat, facture, paiement, essai | adminKLASSCI | plan existant, cycle de facturation lacunaire | machine à états + webhook idempotent |
| Provisionnement | master orchestrateur | Laravel cPanel partiellement simulé / FastAPI DB+Alembic | deux adapters produits et queues |
| Notes/classes/encaissements | application métier concernée | V2 Laravel / Collège FastAPI | permissions effectives dans l'app |
| Santé application/worker/tenant | maître collecteur | métriques hétérogènes | /health versionné, pas de requête DB métier par le maître |
| Support/incident, Care | adminKLASSCI | KLASSCI Care déjà présent | incidents corrélés par produit + tenant |
| Emails/WhatsApp/SMS | MailPulse | Collège déjà connecté, maître SMTP hérité | adaptateur master, outbox, consentements |
| Nanan | couche IA produit + mesure au maître | Nanan V2, pas d'équivalent Collège identifié | adapters de capacités, accès tenant, coût/quotas |
| Superadmin métier Collège | Collège, puis fédération progressive | routers FastAPI protégés par permissions | inventaire + SSO/impersonation surveillé, jamais bypass |

## 2. Superadmin Collège — endpoints découverts

African-DC/klassci-college-backend/app/routers/super_admin/__init__.py
regroupe tenants, tenant_data, pats, diagnose, logs, db_query.

- Tenants : création, lecture et contrôle de slug via
  permission super-admin:tenants:create/read.
- Tenant_data : étudiants, enseignants, classes en lecture seule
  (super-admin:tenants:read).
- PAT : création/liste/révocation (super-admin:pats:manage).
- Diagnostic : lecture (super-admin:diagnose:read).
- Logs : lecture (super-admin:logs:read).
- Console SQL : écriture de requête via super-admin:db:execute.

**Risque P0 :** aucune interface master n'autorise l'exécution SQL arbitraire
sur les bases du Collège. Cataloguer précisément l'implémentation et ses
garde-fous avant toute fédération. Jamais utiliser un token omnipotent pour
rendre le bouton « Piloter » fonctionnel.

## 3. API de l'adaptateur produit (contrat de conception v1)

Les méthodes suivantes sont des **contrats proposés**, non des endpoints
déjà implémentés. Ne pas coder deux adaptateurs divergents sans tests de
contrat communs.

- capabilities() : version, features, classes de permissions, health endpoints
- provision(runId, tenantId, options) : résultat idempotent avec états persistés
- health(tenantId) : état HTTP, DB, queue, migrations, storage, dernier backup
- usage(tenantId, period) : compteurs élèves/personnel/stockage/notifications/IA
- applyEntitlements(tenantId, revision, signedSnapshot) : révision monotone
- suspend(tenantId, reason, mode) : lecture seule préservant exports et factures
- inviteAdmin(tenantId, email, invitationId) : lien à usage unique, aucun mdp
- deprovision(tenantId, retentionPolicy) : rétention et export validés

Identifiants globaux immuables et non réutilisés pour établissement, produit,
instance et organisation. « Produit » n'est jamais inféré d'un sous-domaine
ou de l'existence d'un login. Les URL d'instance sont des données enregistrées,
pas déduites de la chaîne '{slug}.klassci.com'.

## 4. Invariants multi-tenant et commerciaux

- Une organisation possède un ou plusieurs établissements.
- Un établissement peut acheter plusieurs produits ; chaque instance a un
  tenant métier distinct et des identifiants de credentials indépendants.
- Plan, contrat et tarification historiques ne sont jamais réécrits par
  import/discovery d'instances existantes. Mode import: READ_ONLY jusqu'à
  approbation des correspondances.
- Une suspension de paiement n'efface pas les pièces ou les données ; elle
  réduit les actions et préserve accès aux factures, exports et contact.
- Chaque action sur une école produit audit (acteur, scopes, produit,
  établissement, instance, avant/après, résultat et motif).
- Le tenant ignore/refuse un snapshot d'entitlements dont la signature ou la
  révision ne sont pas conformes. En panne master, appliquer la politique de
  grâce documentée (sans réactiver des droits révoqués par défaut).
- Ne pas utiliser de rôle texte codé en dur pour autoriser un tenant ou Nanan.
- Nanan ne reçoit jamais de jeton global; requête liée à utilisateur/tenant
  avec permissions effectives requises par chaque outil.

## 5. Admission B2B et onboarding automatisé

klassci.com/essai (futur) est indépendant du portail d'inscription scolaire.
Enregistrer brouillon lead et consentements, vérifier email, nom d'établissement
et domaine, contrôler anti-bot et quotas antifraude, choisir produit, puis
commander un provisionnement à la queue (jamais depuis le thread HTTP).
Statuts : pending_verification, verified, queued, provisioning, health_check,
trialing, active, read_only, suspended, cancelled, offboarded, failed.
État failed doit garder erreur structurée visible à l'opérateur et permettre
une reprise idempotente. Pas de création Git branch par prospect.

## 6. MailPulse et cron à préparer

L'adaptateur MailPulse de ce dépôt est désactivé tant qu'il n'y a pas d'outbox.
Le fait de recevoir « queued » signifie acceptation par MailPulse, pas livraison.
Les services métiers devront persister les événements avant émission.

Les schedules envisagés ci-dessous ne sont **pas encore exécutés** :
- toutes les minutes : workers outbox + jobs retry/dlq, sans double scheduler
- toutes les 5–15 minutes : réconciliation états provisionnement/santé
- quotidien : calcul usages/quotas, suivi essais expirants et paiements en échec
- quotidien : backups et copie hors site, avec vérification de présence
- périodique : restauration dans base temporaire, test complet documenté
- périodique : purge de données suivant délais contractuels, contrôle humain
- on-event : relance lifecycle via MailPulse, aucun scan de campagne à l'aveugle

Lors de la consolidation sur Contabo, obtenir d'abord CPU/RAM/IOPS/stockage,
charge actuelle, reverse proxy, conteneurs, secrets et cron existants. Garder
une ressource réservée et un mécanisme de défense contre le « noisy neighbor ».

## 7. Conditions de sortie de la découverte

- Un diagramme source de vérité et les migrations additives ont été revus.
- Les API de contrôle du Collège et de KLASSCIv2 passent des tests contractuels.
- Les tests isolation des produits et permissions sont exécutés.
- Les agents peuvent identifier précisément quelles actions appartiennent
  au master vs superadmin Collège vs utilisateurs de l'école.
- Les tickets de sécurité P0 sont résolus avant signup public.
- Le runbook migration Contabo contient snapshot/restore/rollback réels.
