# KLASSCI CARE — Architecture Audit & Implementation Blueprint v1

> Status: **tranche 1 implemented** (Master `5186399`…`2d8df92`, KLASSCIv2 `c8ff5f8`…`937a58a`): instance
> credentials, ticket creation and reading, the support queue, the school's report
> dialog and its outbox. Tranche 2 (conversation, attachments, screenshot) is next.
> Sections below describe the design; where the code chose a different name, the code wins.
> Date: 2026-09-22. Branch: `claude/klassci-care-support-platform-1x9wwy` (both repos).
>
> Every statement is tagged:
> **[CONFIRMED]** read in the code, with its path ·
> **[ASSUMPTION]** likely, not verified ·
> **[UNKNOWN]** cannot be settled from the repositories ·
> **[NEEDS_DECISION]** a product, business or infrastructure choice someone must make.
>
> Canonical copy: `adminKlassci/docs/support/KLASSCI_CARE_BLUEPRINT.md` (the Master is the
> source of truth for support data). `KLASSCIv2/docs/support/KLASSCI_CARE.md` points here and
> holds the tenant-side notes.

---

## Deviations from this blueprint (tranches 1 and 2)

The sections below are the original design. The implementation departed from it in these places; where
the two disagree, **this list and the code are right**.

- **Idempotency (§12, §13.3).** No separate `support_idempotency_keys` table and no 7-day purge.
  The key lives on `support_tickets` itself (`idempotency_key`, `request_hash`), unique on
  `(tenant_id, idempotency_key)`, kept as long as the ticket. The hash covers category,
  description, title and reporter id only, **not the context**: a legitimate resend from another
  page must find its ticket. Same key and same hash replays with 200 and
  `Idempotent-Replayed: true`; same key and a different hash answers 422
  `idempotency_key_reused`, which the school turns into a fresh key.
- **Statuses (§6, §20).** `IN_REVIEW`, `FIX_READY`, `DEPLOYED` and `VERIFYING` are removed: they
  need the GitHub and deployment integration of later slices. The school-facing status
  `CORRECTION_DEPLOYEE` goes with them, so the school sees six statuses. A status enters
  `TicketStateMachine` only when a screen can serve it: `WAITING_CUSTOMER` becomes reachable
  with tranche 2 (the school can reply).
- **Transitions (§6).** Transitions are a flat table checked under a row lock. Rejected and
  Duplicate require a written reason (10 characters, `config/care.php`). Rejected, Duplicate and
  Closed reopen to `TRIAGED`, not to a separate `REOPENED` status.
- **Credential scopes (§4.2).** Only `support:create`, `support:read` and `support:update` are
  issued. `telemetry:send` and `health:read` arrive with the routes that read them.
- **Security restriction.** `is_security_restricted` is set by the `RestreindreTicket` action
  (reason required, journaled). Restricted tickets are hidden from the school API and from staff
  without `support.security.view`, the navigation badge included.
- **Page title.** Not collected: it can carry a student's name.
- **School reply (tranche 2).** `POST /tickets/{reference}/messages?reporter=<id>&scope=`,
  `Idempotency-Key` required, body `{body, author_name}`. Same scoping as reading, so a
  reference the school cannot read answers 404. Replying on `WAITING_CUSTOMER` moves the
  ticket to `WAITING_SUPPORT`; on `RESOLVED` it reopens to `TRIAGED`; on a closed, rejected or
  duplicate ticket it answers 409 `ticket_closed`. Staff set `WAITING_CUSTOMER` with the
  « J'attends une réponse de l'école » toggle when replying. `/bootstrap` also returns the
  credential's `portees`, so the school hides what its credential cannot do.
- **School attachments (tranche 2).** `POST /tickets/{reference}/attachments?reporter=<id>&scope=`,
  multipart field `fichier` (+ optional `author_name`), `Idempotency-Key` required, same
  scoping as reading. The type is read from the content, never the name: PNG, JPEG, WebP, PDF,
  5 MB, 10 per ticket, 20/min per instance (`config/care.php` `pieces_jointes`). Images are
  decoded and re-encoded: EXIF and GPS dropped after the EXIF orientation is applied to the
  pixels, side capped at 2400 px, and anything above `pixels_max` (24 Mpx) refused from its
  declared size **before** decoding (the system libgd allocates outside `memory_limit`). A PDF
  goes through `InspectionPdf`, which **fails closed**. `AnalyseurPdf` reads the file
  sequentially, as a viewer does, skipping stream data by its `/Length`; a `stream` keyword
  hidden in a string or a comment is refused, since that is the only way to show a viewer a
  stream we would not see. Each stream is judged on its own dictionary: an image XObject
  passes unread; an unfiltered stream is read raw; exactly one FlateDecode is inflated to
  its real end within `pdf_inflation_max_octets`, PNG predictor undone; any other filter,
  chain, indirect `/Filter` or `/DecodeParms`, or external `/F` is refused. Then the xref
  must agree with the reading: every offset lands on an object we read, every compressed
  object lives in a stream we decoded, and a file without xref is refused. Active names are
  refused (`/JavaScript`, `/JS`, `/Launch`, `/EmbeddedFile`, `/AA`, `/RichMedia`, `/XFA`,
  `/SubmitForm`, `/ImportData`, `/GoToE`, `/Rendition`, `#xx` escapes decoded); `/OpenAction`
  is judged on its value — a destination array (mPDF writes one on every file) passes, an
  action or a reference does not; `/Encrypt` is refused as protected. Checked against real
  Dompdf and mPDF output. A false refusal costs an
  email; a false accept, a workstation. PDFs
  always download, never render in the panel. Refusals answer 422 `attachment_rejected` with a
  showable message. A retry is recognised by the sha256 of the **bytes received**
  (`received_sha256`), before any decoding; HEIC and animated WebP are refused as unreadable. Files live on the private disk `CARE_PIECES_DISQUE` (default `local`) under
  `care/{tenant_id}/{ticket_id}/`; the file is written before its row and removed if the
  transaction throws (a failed removal is logged; a hard crash between write and commit can
  still leave an orphan, and nothing sweeps them yet). Attaching hands the ticket back to support like a reply. The detail
  projection lists `pieces_jointes` (`id, nom, type, taille, auteur, le`); the school reads one
  through `GET /tickets/{reference}/attachments/{id}` (support:read, relayed by its instance).
  Staff open them from the ticket through a signed link valid 10 minutes, access re-checked on
  each opening; images display, PDFs download. An expired session is sent to the login of the panel it came
  from, admin or group portal (`redirectGuestsTo`), and a row whose file is missing is logged before its 404.

## 0. Executive summary

1. **Nothing to reuse as a ticket system: both repos contain no support, ticket or feedback
   feature** [CONFIRMED, grep over app/, routes/, resources/ in both]. Several neighbouring
   pieces are reusable: Filament database notifications, `tenant_features`, `tenant_deployments`,
   the health-check log analyser, the group-portal alert fingerprinting, the tenant's
   `PorteDeRoute`, and the tenant's Claude-based chatbot.
2. **The existing tenant→Master auth is not fit to carry support data.** It needs a new, scoped
   credential before anything is built on top (§4). The flaws are:
   - the token is stored in plain text;
   - it is also accepted as `?token=`;
   - the tenant resolved from the token is never compared with `{code}` in the URL.
3. **The two apps run on very different stacks, and every tenant-side choice is shaped by it**
   (§1.3):
   - **Master:** Laravel 12, Filament 3.3, Pest 3, SQLite tests.
   - **Tenant:** Laravel 9.52, PHPUnit 9, MySQL tests, no JS build step, Alpine and Bootstrap
     from CDN.
4. **The tenant does not know its own version**, but the Master does: `tenants.git_commit_hash`,
   last deployment. The version on a ticket is stamped server-side by the Master (§17), which is
   also the only trustworthy source.
5. **The Master has no working queue in the migrations**: no `jobs` / `failed_jobs` tables, no
   known worker. That blocks everything asynchronous (AI, GitHub, notifications). Ticket 000b
   fixes it before any async slice.
6. **Recommended first slice** (§47): text report → tenant backend → Master API → persisted
   ticket → Filament queue → reference shown → "Mes demandes" status page. No AI, no
   screenshot, no GitHub.

---

## 1. Current repository architecture findings

### 1.1 adminKlassci (Master) [CONFIRMED]

| Aspect | Finding | Where |
|---|---|---|
| Stack | PHP ^8.3, Laravel 12.69, Filament 3.3.55, Sanctum 4.3 (unused), Pest 3.8, dompdf, excel. No spatie/permission, no AI SDK, no GitHub client, no S3 driver | `composer.json`, `composer.lock` |
| DB | MySQL in prod, SQLite `:memory:` in tests | `config/database.php:7`, `phpunit.xml` |
| Layout | Old SaaS core in English (`Tenant*`); newer code in French (`Domain/Exploitation/Sauvegarde`, `Support/EtatMesure`); **no `app/Jobs`, `app/Policies`, `app/Events`, `app/Actions`** | `app/` |
| Models | Tenant, TenantDeployment, TenantHealthCheck, TenantBackup, VerificationRestauration, TenantActivityLog, TenantFeature, Invoice, SubscriptionPlan, SaasAdmin + User (same `saas_admins` table), Group*, GroupMember* | `app/Models` |
| Admin roles | `super_admin`, `support`, `billing` on `saas_admins.role`. Only `SaasAdminResource::canAccess()` checks a role. **Support and billing can deploy, generate tokens and edit tenant DBs** | `User.php:63-67`, `SaasAdminResource.php:30` |
| Filament | Panel `admin` (`/admin`) with `databaseNotifications()` polling 30 s; panel `group` (`/groupe`) | `Providers/Filament/*` |
| Queue | `QUEUE_CONNECTION=database`, **but no jobs/failed_jobs migration and no worker evidence**; `Artisan::queue('tenant:deploy')` used in 3 places | `.env.example:23` |
| Scheduler | `routes/console.php` + two entries in `bootstrap/app.php` (`tenant:update-stats` runs twice hourly) | `bootstrap/app.php:48-61` |
| Health checks | 6 types; `application_errors` tails 500 lines of the tenant `laravel.log`, scores levels, categorises; runs **hourly** (CLAUDE.md says 5 min) | `Console/Commands/TenantHealthCheck.php:299-462`, `routes/console.php:42` |
| Deployments | Local `Process` on the shared host, 10 steps, records `git_commit_hash`, branch, step log. **No release or version concept.** Status `success` written, model scopes look for `completed` | `TenantDeploy.php:87-290`, `TenantDeployment.php:61-77` |
| Alert fingerprinting | `AlertFingerprintGenerator`, `AlertSeverity`, `AlertType`, `AlertNotificationDispatcher` for the group portal | `app/Services/Group/*`, `app/Enums/*` |
| Mail | SMTP `mail.klassci.com`, `support@klassci.com`; Mailables with bounce tracking | `.env.example:26-33`, `Mail/Concerns/TracksBounces` |
| Tests | 84 files / ~594 cases; **zero API tests**; no model factories | `tests/` |
| CI | `tests.yml` (Pest, PHP 8.3/8.4), `securite.yml` (composer audit), `deploy-tenant.yml` | `.github/workflows` |
| Hooks | None in this repo. `.claude` is a symlink to `../KLASSCIv2/.claude` | — |

### 1.2 KLASSCIv2 (tenant) [CONFIRMED]

| Aspect | Finding | Where |
|---|---|---|
| Stack | Laravel **9.52.22**, Sanctum 3.3, spatie/permission 5.11, owen-it/auditing 13.7, PHPUnit 9.6 (**no Pest**) | `composer.lock` |
| Front-end | **No build step in practice**: JS in `public/js/*.js` or inline Blade; Bootstrap 5.3, jQuery 3.7, Alpine 3 from CDN | `layouts/app.blade.php:56,3444,3449,3474` |
| Layout | 4699 lines; chatbot included at :3431; `@stack('scripts')` :4503; `</body>` :4698 | `resources/views/layouts/app.blade.php` |
| Floating UI | Chatbot `fixed; bottom:24px; right:24px; z-index:1090`; `<x-fab-encaisser>`; mobile `.m-fab` | `public/css/chatbot-widget.css:41-50` |
| Toasts | No `<x-toast>`. `window.mToast` + `'toast'` CustomEvent | `public/js/mobile-shell.js:10,26` |
| AI | `ChatbotService` → `ClaudeAgentService` (Anthropic, default `claude-haiku-4-5`); Gemini/Groq legacy, unwired; **no provider interface** | `Services/Chatbot/*`, `config/anthropic.php` |
| Master calls | Paywall + ContractExpiry `GET /tenants/{code}/limits` (bearer, 10 s, success-only cache 300 s); GroupCacheInvalidator `POST .../cache/invalidate` (afterResponse, 2/3 s) | `PaywallMiddleware.php:204-257`, `ContractExpiryMiddleware.php:75-103`, `GroupCacheInvalidator.php:19-42` |
| Inbound auth patterns | Sanctum abilities (`cli:*`), HMAC timestamp+body (`ParentChatbotInboundSignature`), HMAC portal (`PortailPublicGuard`), group SSO HMAC | `routes/api.php`, `app/Services/ParentChatbot/*` |
| Correlation IDs | **None globally.** Only `MailPulseClient` sends `X-KLASSCI-Request-Id` | `MailPulseClient.php:99-106` |
| Exceptions | `Handler::register()` has an **empty** `reportable()`; no Sentry/Flare | `app/Exceptions/Handler.php:39-43` |
| Logging | `LogRequests` global middleware logs every request (inputs masked); daily `laravel.log` 14 d | `Middleware/LogRequests.php:25-35`, `config/logging.php` |
| Version at runtime | **None**: no VERSION file, no `app.version` | — |
| Permissions | `config/permissions.php` registry (2714 lines); `PermissionRegistry`; `PermissionSyncService` only grants new perms to existing roles via `newFeaturePermissions()` | `Services/PermissionRegistry.php`, `PermissionSyncService.php:175+` |
| Settings / flags | `SettingsHelper::get` (cached 3600 s); flags are boolean settings; the Master's `blocked_features` is only logged, never enforced | `Helpers/SettingsHelper.php`, `PaywallMiddleware.php:238` |
| Notifications | `custom_notifications` + `NotificationService::createNotification()` | `Services/NotificationService.php:444` |
| Scheduler | Exists (`app/Console/Kernel.php` schedules several commands) | `Kernel.php:30-61` |
| Tests | 468 files; MySQL `klassci_testing`; **CI runs only a hard-coded list of test dirs** | `.github/workflows/hygiene-commits.yml:255-270` |
| Hooks | `commit-msg` refuses tool signatures (`Co-Authored-By`, `Claude-Session`…), requires conventional subjects and CHANGELOG for user-facing `feat`/`fix`; `pre-commit` Blade-trap scanner | `.githooks/*` |

### 1.3 Consequences of the stack gap

- **Shared PHP code between the repos is not realistic** (L9 vs L12). The only contract between
  them is the HTTP API, versioned (§13).
- **The tenant widget is plain JS + Alpine served from `public/js/support/`, with no npm build.**
  This follows the chatbot pattern: an `@once` Blade component that pushes a config script and
  loads its JS lazily.
- **Tests differ by repo.** Master-side tests use Pest; tenant-side tests use PHPUnit and must
  be **added to the CI list** in `hygiene-commits.yml`, otherwise CI never runs them.

### 1.4 Documentation drift noted (not fixed here)

- **Master `CLAUDE.md`:**
  - health check every 5 min (actually hourly);
  - "encrypted" DB credentials (actually plain JSON);
  - 9 deploy steps (actually 10);
  - it contains a literal tenant API token (line ~1323) — **rotate it**.
- **Tenant `CLAUDE.md`:** says "Gemini 2.0 Flash"; the chatbot actually runs on Anthropic.
- **Rule `adminklassci-tenant-management.md`:** says the tenant runs Laravel 12 (it runs 9.52).

---

## 2. Existing reusable components

| Component | Repo | Reuse for |
|---|---|---|
| `Tenant` + relations (`deployments`, `healthChecks`, `features`) | Master | Ticket ↔ tenant, deployment correlation, feature flags |
| `TenantFeature` (`feature_key`, `is_enabled`, `config`) | Master | Per-tenant rollout of `support_*` flags (§43) |
| `TenantDeployment` (`git_commit_hash`, `git_branch`, `completed_at`, `deployment_log`) | Master | Version stamping, deploy correlation, fix linkage |
| `TenantHealthCheck` `application_errors` analyser | Master | Seed signal for incident detection until tenant telemetry exists |
| `AlertFingerprintGenerator`, `AlertSeverity`, dispatcher + log | Master | Pattern for error fingerprints and notification dedup |
| Filament `databaseNotifications()` | Master | Staff in-app notifications, no new infra |
| `TenantActivityLog::log()` | Master | Pattern only. Support gets its own immutable `support_ticket_events` |
| Mailables + `TracksBounces` | Master | Customer email notifications |
| `PorteDeRoute` (reads route middleware `permission:*`) | Tenant | Infer the KLASSCI module of a page (`module.*.access`) and the permissions relevant to it |
| `ClaudeAgentService` | Tenant | Proof that Anthropic is already approved for tenant data. The Master still needs its own client (L12, separate secrets) |
| `ParentChatbotInboundSignature` (HMAC, timestamp, 300 s TTL, `hash_equals`) | Tenant | Template for Master→tenant callbacks, if push is ever needed |
| `MailPulseClient` request-id handling | Tenant | Template for `X-Request-ID` propagation |
| `NotificationService::createNotification` | Tenant | Notify the reporter in-app when the Master status changes |
| `window.mToast` / `'toast'` event | Tenant | Widget feedback |
| `ESBTPAnneeUniversitaire::getCurrent()` | Tenant | Academic-year context |
| `errors/500.blade.php` ("contactez le support") | Tenant | Natural "Signaler cette erreur" entry point, pre-filled with the request ID |

---

## 3. Current tenant communication architecture [CONFIRMED]

```
Tenant (L9)                                   Master (L12)
PaywallMiddleware ── GET /api/tenants/{code}/limits ──▶ tenant.api (VerifyTenantApiToken)
ContractExpiry    ── same, shared cache key ───────────▶
GroupCacheInvalidator ── POST /api/tenants/{code}/cache/invalidate ─▶
                ◀── Group SSO: signed URL (HMAC, GROUP_SSO_SHARED_SECRET) ── group portal
klassci-cli / Master ── /api/cli/* (Sanctum, cli:read|write|admin) ──▶ Tenant
```

Defects that affect KLASSCI Care:

- **Master side (`VerifyTenantApiToken.php`):**
  - accepts `?token=` (line 20);
  - looks the token up in plain text (line 30);
  - filters on a non-existent status `deleted`;
  - `TenantLimitsController` resolves `{code}` without comparing it with the token's tenant.
    **Any tenant token can read another tenant's limits and invalidate its cache.**
- **Tenant side:**
  - `GroupCacheInvalidator` reads `services.master.url` / `.token`, but the real config keys are
    `api_url` / `api_token`. Once `config:cache` is on, it is probably a **silent no-op**.
  - Paywall caches successes only, so when the Master is down every web request waits up to
    10 s.

KLASSCI Care **does not reuse** `tenant.api`. It gets its own middleware and credential (§4, §13).
Fixing the legacy endpoints is tracked separately (ticket 000a) so it can ship independently.

---

## 4. Auth and security constraints

### 4.1 Constraints from the prompt, all achievable [CONFIRMED feasible]
- **Server-to-server only.** The browser never sees a Master credential. It talks to its own
  tenant backend (session + CSRF), and that backend calls the Master.
- **Bearer header only.** No query tokens.
- **Scoped credentials, rotation, revocation.**
- **Tenant identity comes from the credential, never from the payload** (no confused deputy).

### 4.2 Proposed credential model — `tenant_api_credentials` (Master)

| Column | Notes |
|---|---|
| `id`, `tenant_id` | FK tenants |
| `key_id` | public, 12 chars, indexed. Token format `kc_<key_id>_<secret>` |
| `secret_hash` | `hash('sha256', $secret)`. High-entropy secrets need no bcrypt; `hash_equals` on lookup by `key_id` |
| `scopes` (json) | `support:create`, `support:read`, `support:update` (tranche 1; `telemetry:send`, `health:read` later) |
| `last_used_at`, `last_used_ip`, `expires_at`, `revoked_at` | |

- **Middleware** `AuthentifierInstance`:
  - parses the Bearer token;
  - looks up by `key_id`, compares the hash, checks revocation, expiry and tenant status;
  - attaches `tenant` and the scopes to the request.
- **Scope checks** go through a `scope:support:create` route middleware, not through manual
  controller checks (a weakness of the tenant's `cli:*` routes).
- **Rotation:** two credentials can be active for one tenant. You create the new one, deploy it
  to the tenant `.env` as `MASTER_SUPPORT_TOKEN`, then revoke the old one.
- **Provisioning:** `php artisan care:identifiant {tenant} --portees=… [--expire=jours]` prints the token once,
  logs a `TenantActivityLog`, and never stores the token in clear.

**[NEEDS_DECISION]** Should Sanctum `personal_access_tokens` (the table already exists, but
Sanctum is unused) replace this custom table? Sanctum tokens belong to a *tokenable model*. The
Tenant model could become tokenable, which would give abilities for free. The cost is coupling
the design to Sanctum's model semantics. **Recommendation: a dedicated table.** It is explicit,
testable, and carries `key_id` for fast lookup. An ADR is required (ADR-01).

### 4.3 Replay and integrity
- **Replay:** the `Idempotency-Key` header is mandatory on mutating calls (§13.3).
- **Request signing:** an optional HMAC body signature (MailPulse pattern) is kept as a later
  hardening option. It is not required for slice 1, since the channel is already TLS and server
  to server.

### 4.4 Master staff authorization
- **Neither a permission package nor policies exist today** [CONFIRMED]. The prompt's
  capabilities (`support.tickets.view`, …) are defined as **Gates backed by a config registry**
  `config/care.php` → `capacites_par_role`. This mirrors the tenant's registry idea and hard-codes
  no role in logic: roles map to capabilities in config.
- Each Filament resource or page implements `canViewAny` / `canAccess` through `Gate::allows()`.
- **[NEEDS_DECISION]** Should the Master adopt `spatie/laravel-permission` (or Filament Shield)
  to make roles editable in the UI? Not needed for slice 1. ADR-02.

---

## 5. Proposed support domain model

Namespaces follow the Master's newer French-first domain folders, with English model names where
the concept is industry-standard. **[NEEDS_DECISION]** Should everything be named in French to
match `Domain/Exploitation`? This blueprint uses English entity names; they are easy to rename
before the first migration.

```
app/Domain/Care/
├── Tickets/        SupportTicket, TicketMessage, TicketAttachment, TicketContext, TicketEvent
├── Incidents/      Incident, IncidentEvent
├── Problems/       Problem, KnownIssue
├── Produit/        ProductRequest, DecisionRecord
├── Engineering/    EngineeringIssue, GithubLink, Release, FixVerification
├── Connaissance/   KnowledgeArticle (+ versions)
├── Telemetrie/     ErrorFingerprint, ErrorOccurrenceBucket
├── Sla/            SlaPolicy, SlaClock
├── IA/             AiRun, AiArtifact, providers, agents
└── Acces/          TenantApiCredential, capability registry
```

Each folder holds `Models/ Actions/ DTO/ Enums/ Events/ Listeners/ Services/`.

Relationships (all explicit, all many-to-many where the prompt requires it):

```
SupportTicket *──1 Tenant
SupportTicket *──0..1 Problem ──* KnownIssue (0..1 active)
SupportTicket *──* Incident            (incident_tickets)
SupportTicket *──0..1 ProductRequest   (product_request_tickets, many-to-many to keep history)
Problem *──* Incident                   (problem_incidents)
Problem 1──* EngineeringIssue ──0..1 GithubLink(issue) ──* GithubLink(pr)
EngineeringIssue *──* Release ──* TenantDeployment (existing table)
SupportTicket 1──* FixVerification *──1 TenantDeployment
ErrorFingerprint *──* SupportTicket / Incident / Problem
```

**"Bug" is not its own table.** A confirmed defect is a `Problem` with `nature = SOFTWARE_DEFECT`
and a linked `EngineeringIssue`. This avoids a fifth parallel object. Problem natures:
`SOFTWARE_DEFECT`, `INVALID_CONFIGURATION`, `INCONSISTENT_DATA`, `MISSING_DATA`,
`EXPECTED_STATE`, `INFRASTRUCTURE`, `UNKNOWN` (matches §34 of the brief).

---

## 6. Ticket lifecycle

Internal statuses are the brief's list (§20). Transitions go through a **state machine class**
(`TicketStateMachine`) with a table of allowed transitions and required inputs:

| From | To | Guard / required input |
|---|---|---|
| NEW | TRIAGE_PENDING | automatic on persist |
| TRIAGE_PENDING | TRIAGED | classification set (human or AI-accepted) |
| TRIAGED | WAITING_CUSTOMER / WAITING_SUPPORT | message sent |
| TRIAGED | LINKED_TO_KNOWN_ISSUE | `known_issue_id` |
| TRIAGED | DUPLICATE | parent `problem_id`, confirmed |
| TRIAGED | ESCALATED_PRODUCT | `product_request_id` |
| TRIAGED/CONFIRMED | ESCALATED_ENGINEERING | `engineering_issue_id` |
| ESCALATED_ENGINEERING | IN_PROGRESS → IN_REVIEW → FIX_READY | GitHub events (§27) |
| FIX_READY | DEPLOYED | deployment to *this* ticket's tenant completed |
| DEPLOYED | VERIFYING → RESOLVED | FixVerification passed |
| VERIFYING | IN_PROGRESS | verification failed (reopens engineering) |
| RESOLVED | CLOSED | after customer confirmation or N days [NEEDS_DECISION: N] |
| RESOLVED/CLOSED | REOPENED→TRIAGED | customer, within the reopen window [NEEDS_DECISION] |
| any | REJECTED | reason required |

- **Events:** every transition writes one `support_ticket_events` row: immutable, no
  `updated_at`, and the model throws on update or delete. It also fires a Laravel event that
  listeners use (SLA, notifications).
- **Customer-facing mapping (§21):** a pure function `StatutClient::depuis(StatutInterne)`,
  unit-tested exhaustively. Mapping:
  - `REÇU` ← NEW, TRIAGE_PENDING
  - `EN_ANALYSE` ← TRIAGED, WAITING_SUPPORT, CONFIRMED, LINKED…, ESCALATED_*
  - `ACTION_REQUISE` ← WAITING_CUSTOMER
  - `EN_RESOLUTION` ← IN_PROGRESS…FIX_READY
  - `CORRECTION_DEPLOYEE` ← DEPLOYED, VERIFYING
  - `RESOLU`, `FERME` ← CLOSED, REJECTED, DUPLICATE. A duplicate shows the parent's
    customer status instead.

## 7. Incident lifecycle
- **Statuses** as in the brief: DETECTED → … → CLOSED. `IncidentStateMachine` + `incident_events`
  make up the immutable timeline.
- **Candidates:** a scheduler job evaluates configurable rules (§42) and creates an
  `IncidentCandidate`, which is an incident with status `DETECTED` and `declared_at = null`.
- **Declaration:** a human declares it. An auto-declaration rule is allowed only for SEV3/SEV4,
  and only when it is configured.
- **Tenant-facing notice:** a restricted projection of the affected tenants, never other tenant
  names.

## 8. Problem lifecycle
`OPEN → INVESTIGATING → ROOT_CAUSE_IDENTIFIED → FIX_IN_PROGRESS → FIX_DEPLOYED → VERIFIED → CLOSED`,
plus `WONT_FIX`.
- A Problem can publish a KnownIssue (customer/support visibility) with a workaround.
- Closing a Problem requires every linked ticket to be RESOLVED/CLOSED, or an explicit override
  with a reason.

## 9. Product Request lifecycle
- **Statuses** as in the brief (§51). Merges keep history: `merged_into_id` plus an event, and
  tickets are re-pointed rather than deleted.
- **Customer projection:** Reçu / En étude / Planifié / En développement / Disponible /
  Non retenu. No dates unless `date_publiee_approuvee` is set by a human.
- **Interest (§53):** `product_request_supporters` stores (tenant_id, external_user_id). It is not
  a vote counter shown to customers.

## 10. Engineering lifecycle
`DRAFT → READY → GITHUB_CREATED → IN_PROGRESS → IN_REVIEW → MERGED → RELEASED → DEPLOYED → VERIFIED → DONE`

- An `EngineeringIssue` is internal; GitHub is a mirror.
- READY requires the dossier fields (§61 of the brief). Missing fields block the transition and
  are listed back to the engineer.

## 11. Master / tenant responsibility split

| Concern | Tenant (KLASSCIv2) | Master (adminKlassci) |
|---|---|---|
| Entry point, widget, screenshot, annotation, redaction-before-upload | ✔ | |
| Context collection (route, module, entity ids, academic year, browser) | ✔ (allowlist) | validates, normalises |
| Version / commit / deployment stamping | | ✔ (authoritative) |
| Local permission & configuration diagnosis (read-only, domain-specific) | ✔ exposes a signed diagnostic endpoint | ✔ asks for it, stores the result |
| Local outbox for retries | ✔ (small table) | |
| Tickets, events, SLA, incidents, problems, product, engineering, KB, AI, GitHub | | ✔ source of truth |
| Customer projection ("Mes demandes") | ✔ renders | ✔ serves the customer-safe projection |
| Error capture & fingerprinting | ✔ fingerprints and batches | ✔ aggregates |

- **Tenant DB footprint** (keep it minimal):
  - `support_outbox` (pending submissions);
  - `support_tickets_local`, a read-through cache of references per user, optional. Slice 1
    can drop it and query the Master.

## 12. Database model (Master, phased)

Created **only when their slice lands**, never all upfront.

| Slice | Tables |
|---|---|
| 1 | `tenant_api_credentials`, `support_tickets`, `support_ticket_contexts`, `support_ticket_messages`, `support_ticket_events`, `support_idempotency_keys`, `jobs`, `failed_jobs` (000b) |
| 2 | `support_ticket_attachments`, `support_tags`, `support_ticket_tags`, `support_assignments` |
| 3 | `error_fingerprints`, `error_occurrence_buckets` (hourly aggregates, not raw rows), `support_ticket_error_fingerprints` |
| 4 | `known_issues`, `known_issue_tenants`, `problems`, `problem_tickets` |
| 5 | `sla_policies`, `sla_clocks` |
| 6 | `incidents`, `incident_tenants`, `incident_tickets`, `incident_events`, `problem_incidents` |
| 7 | `product_requests`, `product_request_tenants`, `product_request_tickets`, `product_request_supporters`, `decision_records` |
| 8 | `engineering_issues`, `engineering_issue_tickets`, `github_links`, `github_webhook_events`, `releases`, `release_engineering_issues`, `release_deployments`, `fix_verifications` |
| 9 | `knowledge_articles`, `knowledge_article_versions` |
| 10 | `ai_runs`, `ai_artifacts`, `ai_usage_records` |
| — | `care_outbox` (transactional outbox, ADR-05) |

`support_tickets` (slice 1):

```
id, reference (unique, KC-2026-000123), tenant_id FK,
reporter_external_id (tenant users.id), reporter_name_snapshot, reporter_email_snapshot,
reporter_roles_snapshot json, channel enum(IN_APP,MANUAL,EMAIL,API,AUTOMATED_MONITORING),
customer_category enum(PROBLEME,QUESTION,SUGGESTION,DEMANDE,BLOQUE,AUTRE),
internal_category nullable enum(BUG,INCIDENT,CONFIGURATION,PERMISSION,DATA,HOW_TO,TRAINING,
  PRODUCT_REQUEST,BILLING,SECURITY,PERFORMANCE,INTEGRATION,UNKNOWN),
title, description text, status, severity nullable (SEV0-4), priority nullable (P0-4),
product_area nullable, assigned_admin_id nullable FK saas_admins, assigned_team nullable,
visibility_scope enum(REPORTER,SCHOOL_ADMINS) default REPORTER,
is_security_restricted bool,
known_issue_id/problem_id/product_request_id nullable (added in their slices),
first_response_at, triaged_at, resolved_at, closed_at, timestamps, soft deletes
index(tenant_id,status), index(status,severity), index(product_area), index(created_at)
```

`support_ticket_contexts` keeps searchable columns normalized and the rest in `extras` JSON:

```
ticket_id, route_name, url_path (no query string), module,
entity_type, entity_id, academic_year_id, class_id,
app_commit_sha, git_branch, deployment_id FK tenant_deployments (Master-stamped),
browser_family, browser_version, os_family, device_type, viewport, locale, timezone,
request_ids json (last ≤10), extras json (allowlisted keys only), captured_at
index(module), index(route_name), index(app_commit_sha), index(entity_type,entity_id)
```

`support_ticket_messages`: `ticket_id, author_type(CUSTOMER,STAFF,SYSTEM), author_ref,
visibility(PUBLIC_TO_CUSTOMER,INTERNAL_SUPPORT,ENGINEERING_ONLY,SECURITY_RESTRICTED), body,
structured json nullable, created_at`. **Public is never the default for staff messages**: the
UI forces an explicit choice.

**Reference format** [NEEDS_DECISION, proposed]: `KC-YYYY-NNNNNN`. The counter lives in a
`support_sequences` row per year and is incremented under `lockForUpdate`, the same pattern as
the jury PV numbering.

## 13. API contracts (Master, `routes/api.php`, prefix `/api/v1/support`)

### 13.1 Endpoints (slice numbers in brackets)

| Method | Path | Scope | Slice |
|---|---|---|---|
| POST | `/tickets` | support:create | 1 |
| GET | `/tickets?reporter=<ext_id>&scope=mine\|school&page=` | support:read | 1 |
| GET | `/tickets/{reference}?reporter=<ext_id>` | support:read | 1 |
| POST | `/tickets/{reference}/messages` | support:update | 2 |
| POST | `/tickets/{reference}/attachments` (multipart) | support:update | 2 |
| GET | `/tickets/{reference}/attachments/{id}?reporter=<ext_id>` | support:read | 2 |
| POST | `/tickets/{reference}/verification` (`corrige`\|`persiste`) | support:update | 8 |
| POST | `/telemetry/errors` (batch ≤100) | telemetry:send | 3 |
| GET | `/known-issues?module=` | support:read | 4 |
| GET | `/bootstrap` (enabled support features, limits, API version) | support:read | 1 |

- Every read is scoped **server-side** to the credential's tenant. On top of that, the tenant
  passes the acting reporter id and the scope. The Master trusts the tenant backend to state
  who the acting user is. That trust is inherent to server-to-server auth, and the tenant
  enforces its own permission (`support.tickets.view_school`) before asking for `scope=school`.
- **Responses are customer-safe projections** (`TicketProjectionClient` resource): the customer
  status and public messages only.

### 13.2 POST /tickets — request

```jsonc
{
  "report": { "category": "PROBLEME", "description": "…", "title": null },
  "reporter": { "external_id": 42, "name": "…", "email": "…", "roles": ["secretaire"] },
  "context": {
    "route_name": "esbtp.notes.index", "url_path": "/esbtp/notes", "module": "notes_evaluations",
    "entity": { "type": "evaluation", "id": 622 },
    "academic_year_id": 4, "class_id": 17,
    "browser": { "family": "Chrome", "version": "128" }, "os": "Android",
    "device": "mobile", "viewport": "390x844", "locale": "fr", "timezone": "Africa/Abidjan",
    "request_ids": ["01J…"], "extras": { }
  },
  "clarifications": [ { "question_key": "…", "answer": "…" } ],
  "recent_errors": [ { "status": 500, "endpoint_category": "notes.update", "request_id": "01J…", "fingerprint": "…", "at": "…" } ]
}
```

Headers:
- `Authorization: Bearer kc_…`
- `Idempotency-Key: <uuid v7 generated by the tenant backend at draft time>`
- `X-Request-ID`

### 13.3 Idempotency
- **Storage:** `support_idempotency_keys(tenant_id, key, request_hash, response_json, created_at)`,
  unique on `(tenant_id, key)`.
  - Same key and same hash: replay the stored response with status 200 and
    `Idempotent-Replayed: true`.
  - Same key with a different hash: 422.
- **Retention:** purged after 7 days by the scheduler.

### 13.4 Response 201

```json
{ "reference": "KC-2026-000123", "status_client": "RECU", "created_at": "…",
  "self_service": null }
```

`self_service` is filled from slice 4 onward.

### 13.5 Validation and limits
- **Payload:** description 10–5000 chars; `extras` ≤ 4 KB, allowlisted keys; `request_ids` ≤ 10;
  `recent_errors` ≤ 20; unknown keys are dropped.
- **Rate limits** (named limiters, per tenant):
  - tickets: 30/min;
  - attachments: 20/min;
  - telemetry: 120/min, degrading to dropping samples, **never blocking tickets**.

### 13.6 Versioning
- **Path:** `/v1`. Changes are additive, and missing fields get server defaults.
- **Breaking changes** require `/v2`, with `/v1` kept for as long as any tenant branch lacks the
  update. The tenant's `bootstrap` call reports its `client_version`, so the Master knows which
  tenants lag.

## 14. Tenant support widget architecture

- **Blade component** `<x-support.lanceur>` is included once in `layouts/app.blade.php`, after
  the chatbot include (:3431). It holds only a small button plus a config `<script>` (endpoints,
  CSRF, flags) pushed on `@stack('scripts')`. It is an `@once` component, like the chatbot.
- **Lazy load:** the first click loads `public/js/support/reporter.js` (≈15 KB target) with
  dynamic `import()` or an injected script tag. `screenshot.js` and `annotation.js` load only
  when the user picks "Capturer".
- **Placement:** avoid collisions with the chatbot (bottom-right, 24 px, z 1090) and the FAB.
  - **Desktop:** entry in the top navbar user menu ("Aide / Signaler") plus the 500 page. No
    third floating bubble.
  - **Mobile:** an item in the mobile shell's "more" sheet.

  **[NEEDS_DECISION]** Should it be a floating button anyway? Recommendation: none, since the
  chatbot already occupies that corner and the navbar entry is persistent.
- **UI:**
  - an Alpine component registered with `Alpine.data('supportReporter', …)` (AJAX-safe pattern,
    no `@push` inside partials);
  - a Bootstrap-free modal (the premium modal already used by the mobile shell);
  - `x-au-select` for any choices;
  - AJAX only, no reload.
- **Failure isolation:** everything is wrapped in a try/catch boundary, and failures log to
  `console.warn`. If the loader fails, the navbar item falls back to a `mailto:support@klassci.com`
  link with the request ID pre-filled.
- **Local draft:** `localStorage["klassci.support.draft"]` (try/catch), cleared on a 201.
- **Tenant backend routes** (`routes/web.php`, `auth`, `throttle:20,1`, prefix `support`):
  - `POST support/demandes`
  - `GET support/demandes` (page "Mes demandes")
  - `GET support/demandes/{reference}`
  - later `POST …/messages`, `…/pieces`, `…/verification`

  **Controller:** `SupportDemandeController`, kept thin. The logic lives in
  `app/Domain/Support/{Actions,DTO,Services}` plus `app/Services/Care/ClientMasterSupport.php`.
- **Master client:**
  - connect timeout 2 s, timeout 5 s;
  - bearer `config('services.master.support_token')`; the base URL is the existing
    `services.master.api_url`;
  - **failures cached 60 s** (circuit breaker), unlike Paywall.
  - On failure, the submission goes into the `support_outbox` table and the user sees
    "Votre demande est enregistrée, elle sera transmise dès que possible". A scheduler command,
    `support:vider-outbox` (every minute), retries it with the same Idempotency-Key.

## 15. Context collection architecture

- **Server-side (tenant backend, authoritative):**
  - user id, roles, relevant permissions;
  - `TENANT_CODE`;
  - academic year (`ESBTPAnneeUniversitaire::getCurrent()`);
  - the module, from the matched route's `permission:module.*.access` middleware (via the
    `PorteDeRoute` mechanism), with a route-name-prefix fallback map.
- **Client-side (JS, allowlisted):**
  - page title, viewport, device, locale, timezone, UA-derived browser/OS;
  - the last N request IDs;
  - the recent error buffer;
  - `data-support-context` values collected from the page.
- **Entity awareness** (brief §9): pages opt in with
  `<div data-support-context='@json($_ctx)'>`, where `$_ctx` is built in `@php` (Blade pitfall
  #4). Keys are validated on both sides against `config/support.php → contexte.cles_autorisees`
  (entity_type ∈ {etudiant, inscription, paiement, evaluation, note, classe, matiere,
  seance, bulletin, jury, enseignant}, plus a numeric id and optional `semestre` / `etat_affiche`).
- **Route model binding** gives a second, automatic source: the controller-level route
  parameters (`{etudiant}`, `{paiement}`…). They are mapped to entity types by the same
  allowlist, ids only.
- **Never collected:** HTML, input values, query strings, cookies, headers.

## 16. Screenshot architecture

- **[DECIDED] ADR-06, capture engine:** (b) DOM rasterisation with `html2canvas` 1.4.1
  (MIT), vendored in the instance's `public/vendor` — never a CDN — and loaded on the first
  click only, with (c) « Choisir une image » always offered, including when rasterisation
  fails. (a) `getDisplayMedia` was set aside: desktop only, and it captures whatever the user
  shares, not the page we can mask. Gated by the `support_screenshot` tenant feature (closed
  by default), the customer portal, and the `support:update` scope — the image travels as an
  attachment after creation, so without those it could not be joined.
- **Privacy:** before the capture, elements marked `data-support-masque` and every `input`,
  `textarea` and `select` value are replaced with a blurred placeholder. Opt-out is explicit per
  field. The user sees the preview and must press "Joindre".
- **Annotation:** a canvas overlay with rectangle, arrow, blur and text, then export to WebP at
  quality 0.8, capped at 1600 px. EXIF is not relevant for canvas output; for uploaded photos,
  metadata is stripped server-side.
- **Upload path:** browser → tenant backend (multipart, 5 MB limit, MIME sniffing) → Master
  `/attachments`. The Master stores the file on a private disk
  (`storage/app/care/{tenant}/{ticket}`, not the public disk). Downloads go through signed
  temporary routes checked against the tenant scope. Malware scanning is **[UNKNOWN]** (ClamAV
  on LWS shared hosting is unlikely), so the fallback is a strict MIME allowlist:
  png/webp/jpeg/pdf.
- **Session replay (§12):** out of scope. The architecture leaves room for an attachment type
  `REPLAY_MASQUE` behind the `support_replay` flag.

## 17. Telemetry architecture

- **Frontend buffer** (`telemetry.js`, loaded with the layout, <2 KB):
  - wraps `fetch` and jQuery `ajaxComplete`;
  - keeps the last 20 entries of {status ≥ 400, endpoint category = route name when exposed via
    the `X-Route-Name` response header added in debug-safe form, request id, timestamp};
  - also records `window.onerror` / `unhandledrejection` fingerprints (message normalised, top
    frame);
  - **no bodies, no headers except the request id.**
- **Backend capture:** `Handler::register()->reportable()` (currently empty) calls
  `CapteurErreurs::capturer($e)`, which:
  - computes the fingerprint (§18);
  - increments a per-hour counter in cache;
  - keeps one sample (sanitised message, top 5 app frames, route, request id).
- **Shipping:** a scheduler command, `support:envoyer-telemetrie` (every minute on the tenant),
  POSTs the batch to `/telemetry/errors`. Telemetry never runs inline in a request.
  **[ASSUMPTION]** the tenant cron runs `schedule:run` in production; the Kernel schedules
  commands, but the crontab is not in the repo.
- **Version stamping (brief §141):** the Master resolves the tenant's current `git_commit_hash`
  and last completed `tenant_deployments.id` **at receipt time**. This is correct as long as
  deploys go through `tenant:deploy`. A manual `git pull` on the server is not recorded
  **[CONFIRMED risk]**. A later, optional improvement: `tenant:deploy` writes
  `storage/app/release.json` into the tenant, and the tenant sends it as a hint. When the two
  disagree, a `version_mismatch` flag is raised.

## 18. Correlation ID architecture

- **Tenant:** new middleware `AttribuerIdentifiantRequete`, first in the global stack before
  `LogRequests`:
  - accepts an incoming `X-Request-ID` only if it matches the ULID/UUID format; otherwise
    generates a ULID;
  - calls `Log::withContext(['request_id' => …])` (available in Laravel 9);
  - sets the response header `X-Request-ID`.

  The widget reads it from response headers. The 500 page prints it ("Code de suivi : …").
- **Master:** the same middleware. The tenant→Master client forwards its own request id.
- **Error fingerprint:**
  `sha1(exception_class | normalised_message | top 3 app frames (file:function) | route_name)`.
  - Normalisation strips digits, UUIDs, ULIDs, quoted strings, hex and paths under
    `storage/`.
  - The app version is **not** part of the hash. It is stored as a dimension, so one
    fingerprint shows its version distribution.
- **Reuse:** the fingerprint function is ported from `AlertFingerprintGenerator`'s approach.

## 19. AI agent architecture

- **Current state:**
  - **Master:** no AI code. **Tenant:** a concrete `ClaudeAgentService` (Anthropic).
  - Anthropic is already the provider handling tenant data, so **reusing Anthropic on the
    Master adds no new data processor** [CONFIRMED provider; the legal basis is NEEDS_DECISION,
    see §36].
- **Provider abstraction (Master):**
  - `Contracts\FournisseurIA::structure(TacheIA $tache, array $messages, JsonSchema $schema): ResultatIA`;
  - implementations `AnthropicFournisseur` (HTTP client, no SDK needed) and `FauxFournisseur`
    (tests);
  - configuration in `config/care.php → ia.taches.{LIGHT,STANDARD,DEEP,CRITICAL} → {provider,
    model}`. Defaults: LIGHT/STANDARD `claude-haiku-4-5`, DEEP `claude-sonnet-5`,
    CRITICAL `claude-opus-5-5`, all configurable.
- **Agents** are PHP classes implementing
  `Agent::executer(ContexteAgent): Artefact`, each with a versioned prompt file under
  `resources/prompts/care/{agent}.v{n}.md` and a JSON schema.
- **Orchestration:** a `PipelineTriage` job chain on the queue:
  - Intake → Context → Classification → (parallel) Duplicate, KnownIssue, Permission,
    Configuration → Severity → Synthesis;
  - DEEP/CRITICAL add Reproduction, CodeArchaeologist, RegressionTest, SkepticalReviewer,
    EvidenceChecker (§28 adversarial flow).
- **Tools:** agents only call **read-only typed tools** (`OutilsLecture`). Each tool enforces
  tenant scope and visibility in PHP, outside the model. Mutations are only *proposals*
  (`AiArtifact` rows of type `PROPOSITION_*`), applied by a human through normal Actions (§156).
- **Code archaeology:**
  - **[UNKNOWN]** whether the Master host can read a KLASSCIv2 checkout. It does:
    `PRODUCTION_PATH/{code}` holds each tenant's checkout on the same LWS host.
  - The CodeArchaeologist uses a read-only search over the `presentation` checkout (grep + file
    excerpts, path allowlist `app/ routes/ resources/views/ database/migrations/ tests/`) and
    the GitHub search API once GitHub is connected.
  - It is never run on customer-triggered paths without a DEEP budget.
- **Degradation:** an AI failure leaves the ticket in `TRIAGE_PENDING`; the pipeline is never on
  the ticket-creation path (§119).
- **Chain of thought:** not requested and not stored. Only the structured artifacts of §27 are
  kept.

## 20. AI schemas

- The schemas are the brief's §94–98, adopted **as-is** as JSON Schema files under
  `resources/schemas/care/*.json`.
- Each is validated with a small internal validator (types, enums, required fields). No package
  is added until ADR-07.
- Every artifact row stores `schema_version`, `prompt_version`, `visibility` and `confidence`
  (LOW/MEDIUM/HIGH, with reasons).
- `CustomerResponse.internalReason` is stripped by the projection layer, with a test asserting
  it never reaches a tenant response.

## 21. Duplicate strategy

- **Hybrid, in order of cost:**
  1. Structured candidates: same tenant or any tenant, same `route_name` / `module`, same error
     fingerprint, same entity type, same app commit, a ±14-day window.
  2. Lexical: MySQL `FULLTEXT(title, description)` in natural language mode. **[CONFIRMED
     constraint]** SQLite tests cannot run FULLTEXT, so the query sits behind a repository
     interface with a LIKE-based fallback in tests.
  3. Semantic: an LLM (STANDARD) re-ranks the top 20 candidates and returns
     `similarity: LOW/MEDIUM/HIGH` plus `matchingFactors`. **No vector store in v1** (brief §150);
     ADR-08 revisits once the volume justifies it.
- **States:** POSSIBLE / LIKELY / CONFIRMED. A human confirms. A confirmed ticket keeps its own
  row and customer history, and gets `problem_id` pointing to the shared Problem.

## 22. Known Issue strategy

- **Fields:** title, customer description, workaround, affected modules, affected tenants (or
  "all"), affected commit range, status (ACTIVE/RESOLVED), visibility (CUSTOMER/SUPPORT).
- **Matching:** at ticket creation, a synchronous deterministic match on module + route +
  fingerprint (cheap) produces `self_service` in the response. The AI match runs async.
- **Tenant visibility:** only KnownIssues with `known_issue_tenants` containing this tenant, or
  flagged global. No other tenant names are ever included.

## 23. Permission & configuration diagnosis

- **Where the data lives:** the tenant's DB, which the Master can already reach through
  `TenantConnectionManager`. **Do not use direct DB access for diagnosis.** It bypasses tenant
  business logic, and the manager logs credentials (§35).
- **Proposed approach:** a signed tenant endpoint,
  `GET /api/v1/support/diagnostic/{type}?user=&entity=` (HMAC, the ParentChatbot inbound
  pattern, keyed per tenant). It returns **structured, minimal** facts:
  - `permission`: `{ module, permissions_requises: [...], utilisateur_a: {perm: bool} }`, limited
    to the permissions gating the reported route (computed with `PorteDeRoute`);
  - `configuration` for the first domains (evaluations/notes, matières of a class, frais of an
    inscription), built on the existing canonical services (`MatiereTreeBuilder`,
    `ESBTPFraisConfiguration`, `CoherenceSystemeAcademique`).

  Each check is a class implementing `VerificationDiagnostic` with `NOT_CHECKED / OK / ANOMALIE
  / NOT_APPLICABLE`.
- **Read-only by construction:** the endpoint runs inside a DB transaction that is rolled back,
  plus tests.

## 24. Severity model
- **Scale:** SEV0–SEV4 as in the brief.
- **Engine:** `MoteurSeverite::suggerer(Signaux): Suggestion{niveau, facteurs[], inconnues[],
  confiance}`, driven by configurable weighted rules (`config/care.php → severite.regles`).
  Signals: tenants affected, users affected, workflow criticality (module map), academic
  period (exam/bulletin window from the tenant's calendar, [UNKNOWN] whether it is reliably
  available), workaround, data-integrity flag, security flag.
- AI can refine the suggestion; a human sets the final value. Overrides require a reason and are
  logged as an event.

## 25. Priority model
- **Scale:** P0–P4, separate from severity. The same suggestion shape as severity, with business
  factors (§45 of the brief).
- **Display:** "Suggested: HIGH — reasons…". A number is never shown without its reasons.

## 26. SLA model
- **Policies:** `sla_policies(severity?, category?, plan?, first_response_min, triage_min,
  resolution_min, update_every_min, business_hours_id?)`.
- **[NEEDS_DECISION]** Commercial values per plan. SubscriptionPlan already has an SLA *label*
  [CONFIRMED], but no numbers. Seed policies are "internal targets" until business confirms.
- **Clocks:** `sla_clocks(ticket_id, kind, started_at, paused_at, paused_total_s, due_at,
  breached_at)`. Paused states are configurable (default: WAITING_CUSTOMER pauses resolution
  only).
- **Business hours:** use Africa/Abidjan by default, per-tenant timezone from the provisioning
  `APP_TIMEZONE` (ucao-benin is UTC+1). **[NEEDS_DECISION]** Holidays calendar.
- **Checks:** a scheduler job `care:verifier-sla` every 5 min emits `SlaEnRisque` / `SlaDepasse`
  events. Test-heavy (§159).

## 27. GitHub integration
- **[NEEDS_DECISION] ADR-09, the auth model:**
  - a GitHub App on `James10192/KLASSCIv2` (fine-grained, webhook secret, installation token),
    **recommended**;
  - or a fine-grained PAT.
- **Issue creation:** human-confirmed only. It uses the §63 template, rendered from the
  EngineeringIssue dossier through a `DossierVersGithub` renderer that runs the **redaction
  pipeline** first (§36). Attachments are never uploaded; a line reads
  "pièces jointes disponibles dans KLASSCI Care (KC-…)".
- **Label/Issue conventions:** reuse the repo's existing labels. **[UNKNOWN]** The label
  taxonomy was not audited: ticket 038 starts by listing the labels through the API.
- **Webhooks:** `POST /api/v1/github/webhook`
  - verifies `X-Hub-Signature-256`;
  - stores the raw event in `github_webhook_events`, unique on `X-GitHub-Delivery` for
    idempotency;
  - processes it in a queued job;
  - handles issues opened/closed/reopened, pull_request opened/closed(merged)/review.
  - It links PRs to issues via "Fixes #N" / "Refs #N" and the branch name.
- **Rule (brief §70):** a closed GitHub issue never resolves a ticket.

## 28. Deployment linkage
- **Existing:** `tenant_deployments` rows with the commit [CONFIRMED].
- **Prerequisite fix (000c):** normalise the status (`success` vs `completed`); record the
  previous commit (`git_commit_hash_avant`) at deploy start, so the range
  "what changed in this deploy" is computable.
- **Release:** a `releases` row groups merged PRs by commit range. It is created automatically
  when a PR is merged into `presentation`, or manually.
- **Deploy coverage:** a deployment to tenant T at commit C *contains* release R if R's merge
  commit is an ancestor of C. Ancestry is checked with `git merge-base --is-ancestor` on the
  presentation checkout, or via the GitHub compare API, and cached per (commit, release).
- **Correlation (§42):** when an error fingerprint's first-seen timestamp falls within X hours
  after a deployment on the same tenant, a "potential correlation" is shown. It is never
  asserted as a cause.
- **Rollback info:** previous commit, migrations run (from `deployment_log`), tenants on the
  same commit. **Read-only.**

## 29. Fix verification
- **Creation:** a `fix_verifications(ticket_id, deployment_id, type AUTOMATED|SUPPORT|CUSTOMER|
  HYBRID, technical_status, customer_status, evidence json)` row is created automatically when a
  Release linked to the ticket reaches its tenant.
- **Automated evidence:** fingerprint occurrences in the N hours before vs after (§74), plus an
  optional smoke check.
- **Customer:** "C'est corrigé / Le problème existe toujours" on the tenant. "Existe toujours"
  → verification FAILED → ticket IN_PROGRESS, EngineeringIssue reopened, event recorded.

## 30. Knowledge Base
- **Structure:** articles with a type, visibility, status (DRAFT/PUBLISHED/NEEDS_REVIEW/ARCHIVED),
  `created_version` / `updated_version` (commit), `last_reviewed_at`, `review_owner_id`.
- **Versions:** immutable rows.
- **Drafts:** the AI writes drafts from resolved clusters; publishing is human-only.
- **Search:** FULLTEXT on the Master.
- **Customer articles:** served to tenants through `/known-issues` and later `/articles`. Staff
  runbooks never leave the Master.
- **Link with product docs:** klassci.com hosts the product docs [CONFIRMED, tenant
  `routes/web.php:118-122`]. **[NEEDS_DECISION]** Should customer KB articles live in KLASSCI Care
  or be published to klassci-landing? Recommendation: KB in Care, with a "voir aussi" link to
  the public docs.

## 31. Customer portal (tenant)
- **Page:** `GET /support/demandes`, a premium hero (namespace `sp-*`) with KPIs (open, action
  required, resolved this month).
- **List:** the Master projection, paginated.
- **Detail:** the thread of public messages, reply, attachments, fix confirmation.
- **Visibility:**
  - reporter-only by default;
  - users with `support.tickets.view_school` see their school's tickets (school admins);
  - `support.product_requests.support` can mark interest.
- **Freshness:** polling (60 s) on the detail page only. No realtime infra (§180).
- **Reporter notification:** when the Master projection changes status, the tenant creates a
  `custom_notifications` row for the reporter. Detected on page load via `updated_at`
  comparison, and in slice 2 by a Master→tenant push (HMAC) or tenant polling in the scheduler.
  **[NEEDS_DECISION] ADR-10**: pull vs push. Recommended: pull every 5 min for open tickets only.

## 32. Support dashboard (Master)
- **Resources:**
  - `SupportTicketResource` (list with saved tabs: Mine, Non triés, Attente client,
    Attente support, Correspondances connues, Doublons potentiels, SLA proche, Rouverts);
  - a custom **View page** "Dossier": customer text, context card, request IDs, attachments,
    errors, AI panel, candidates, thread with a visibility selector, timeline.
- **Performance:** server-side filters and indexes; eager-load tenant + context; pagination 25.

## 33. Head of Development dashboard
- **Custom Filament page** `BoiteDecisions`, with only five blocks:
  - À décider (DecisionRecords `OPEN`, ProductRequests `UNDER_REVIEW` with ≥2 tenants,
    SEV1/P0 confirmed);
  - Incidents actifs (SEV0–2);
  - Engineering ready;
  - Risques SLA (only breach-imminent with no assignee);
  - Demandes émergentes (clusters of ≥3 tenants in 30 days).
- **Decision card** (brief §173): question, current behaviour, tenants, ticket count, options,
  risks, recommendation, and decide/defer buttons that write a `DecisionRecord`.
- **Digest:** a daily mail at 07:30 Africa/Abidjan through `care:digest-quotidien`. No more than
  one mail per day plus SEV0/SEV1 alerts.

## 34. Product intelligence
- **Widgets:** support drivers (category share), tickets per module, per tenant, per active user
  (active users from `tenants.current_users`), HOW_TO hot spots (potential UX friction),
  reopen rate, self-service rate, the ticket→bug funnel (§174).
- **Aggregates:** computed nightly into a `care_statistiques_journalieres` table, so dashboards
  never scan tickets.

## 35. Security
- **Pre-existing issues found during the audit.** Each is tracked separately and fixed before or
  alongside slice 1, because they share the trust boundary:
  1. **Cross-tenant access on the legacy API:** `{code}` is not bound to the token (000a).
  2. **`?token=` query tokens and plain-text `api_token`** (000a; migrate to hashed + header only,
     with a deprecation window for the paywall client).
  3. **Shell injection:** `tenant:deploy --branch` is interpolated into a shell string
     (`TenantDeploy.php:155-156`) and reachable from `POST /api/deploy` and Filament. Separate
     security fix, not KLASSCI Care, and **urgent**.
  4. **Password logged:** `TenantConnectionManager` logs the full DB credentials including the
     password at debug level.
  5. **No `$hidden` on Tenant:** `api_token` and `database_credentials` serialise.
  6. **No Master staff authorization beyond `super_admin`.**
- **KLASSCI Care controls:**
  - scoped hashed credentials;
  - tenant scoping in every query (a global scope on tenant-owned Care models *plus* explicit
    checks in projections);
  - visibility enums on every message and artifact;
  - the `SECURITY_RESTRICTED` visibility and `is_security_restricted` tickets, visible only with
    `support.security.view`;
  - SecuritySentinel runs deterministic keyword rules first, then AI, and **never** feeds
    GitHub automatically;
  - prompt-injection defence: customer text is wrapped as data in prompts, and tools enforce
    authorization in PHP;
  - an audit event on every sensitive change.

## 36. Privacy / redaction
- **`SupportRedactionService` (Master), applied before AI and before GitHub.** It redacts:
  - secrets (bearer tokens, `kc_…`, API-key shapes, passwords in `key=value`);
  - emails;
  - phone numbers (via the tenant's `PhoneNormalizer` shapes: `+225`, `+229`, local 10-digit);
  - national ID shapes;
  - student names *when a name dictionary is supplied by the tenant context* (the reporter's
    name and the entity's display name are known and replaced by `[ETUDIANT#id]`).
- **Tested with fixtures** from both countries.
- **Tenant side:** the screenshot masking and input exclusion of §16.
- **Retention (proposed, [NEEDS_DECISION] business/legal):**
  - raw telemetry samples: 30 days;
  - hourly aggregates: 13 months;
  - screenshots: 180 days after closure;
  - tickets: 5 years (aligned with the PV retention culture);
  - security records: per incident policy;
  - AI run inputs: references only, never copies of attachments.
- **[NEEDS_DECISION] Legal basis for sending ticket content to an AI provider.** Controllers
  under ARTCI (Côte d'Ivoire, law 2013-450) and APDP (Bénin). The chatbot already sends data to
  Anthropic, but a support ticket is a new purpose. It needs a contract clause or school
  consent. **This blocks the AI slices, not slice 1.**

## 37. Multi-tenant isolation
- **Master:** a trait `AppartientAUneInstance` adds a global scope when a tenant context is bound
  (API requests). Filament staff views run without it, but with capability checks.
- **Required tests (§160):** read, list, message, attachment download, search, known-issue
  metadata, idempotency-key collision across tenants (the key is unique per tenant, so the same
  key from two tenants must create two tickets).
- **Tenant:** every support route checks `auth()` and passes only `auth()->id()` as the
  reporter. `scope=school` requires the permission. A test proves a user cannot fetch another
  user's reference by guessing it (the Master checks reporter + scope).

## 38. Queue strategy
- **000b prerequisite:**
  - migrations `jobs`, `job_batches`, `failed_jobs`;
  - a worker compatible with LWS shared hosting.

  **[NEEDS_DECISION / UNKNOWN]** No supervisor on cPanel [ASSUMPTION]. Proposed: a cron every
  minute running `php artisan queue:work --stop-when-empty --max-time=50 --queue=care-critique,care,default`.
  It is stateless and safe with `withoutOverlapping`. The existing `queue_workers` health check
  becomes real by comparing the `jobs` backlog and the age of the oldest job.
- **Queues:**
  - `care-critique`: notifications for SEV0/1, GitHub webhooks;
  - `care`: triage, clustering, attachments;
  - `care-ia`: AI, rate-limited, budget-checked.
- **Ticket creation is synchronous and never waits on a job.**

## 39. Scheduler strategy (Master, `routes/console.php` only)
- **SLA:** `care:verifier-sla` every 5 min.
- **Incident candidates:** `care:detecter-incidents` every 5 min.
- **Product request clustering:** `care:regrouper-demandes` daily 01:30.
- **KB reviews:** `care:rappels-revue-kb` weekly.
- **Daily digest:** `care:digest-quotidien` daily 07:30.
- **Weekly review:** `care:revue-hebdo` Monday 07:00.
- **AI cost:** `care:cout-ia` daily.
- **Purge:** `care:purger` daily 04:30 (idempotency keys, expired telemetry, retention).
- **Duplicate scheduling:** the duplicate `tenant:update-stats` schedule in `bootstrap/app.php`
  is removed in 000b, and scheduling is consolidated in one file.

**Tenant:** `support:vider-outbox` every minute; `support:envoyer-telemetrie` every minute;
`support:rafraichir-statuts` every 5 min (if pull, ADR-10).

## 40. Search / indexing strategy
- **Structured filters first.** MySQL FULLTEXT on tickets, KB and product requests.
- **Global Filament search:** reference, tenant code, user name snapshot, route, fingerprint,
  GitHub number.
- **No vector infrastructure in v1** (ADR-08).

## 41. Observability
- **Metrics:** written to a `care_metriques` table (1-minute buckets) and read by a Filament
  widget. No Prometheus on shared hosting [ASSUMPTION]. Tracked:
  - API success rate and p95 latency;
  - queue age;
  - AI failures and cost;
  - GitHub webhook failures;
  - attachment failures;
  - telemetry ingest rate;
  - `schedule:run` heartbeat.
- **Health check:** a new check type `care_pipeline` added to `tenant:health-check`, or a
  Master-level check.

## 42. Testing
- **Master (Pest, SQLite):**
  - API tests are **new ground**: none exist [CONFIRMED]. They cover credential auth (invalid,
    revoked, expired, wrong scope, query token refused), idempotency, validation limits,
    cross-tenant isolation, projection hides internal notes, state machine (every allowed and
    forbidden transition), customer status mapping, SLA clocks (pause/resume/business hours),
    redaction fixtures, AI schema validation with `FauxFournisseur`, GitHub signature and
    idempotency.
  - Factories are needed; none exist (`database/factories` is absent).
- **Tenant (PHPUnit, MySQL):**
  - `ClientMasterSupport` with `Http::fake` (pattern: `MailPulseClientTest`), including the
    outbox on timeout and the circuit breaker;
  - context allowlist;
  - request-id middleware;
  - the routes require auth, and the school scope requires permission.
  - **New test directories must be added to `hygiene-commits.yml`.**
- **E2E:** Playwright against `presentation` + local Master (skill `klassci-test-e2e`).

## 43. Feature flags
- **Master authoritative:** `tenant_features` rows (`support_widget`, `support_screenshot`,
  `support_ai_triage`, `support_auto_known_issue`, `support_customer_portal`,
  `support_incident_detection`, `support_product_requests`, `support_replay`). They are served
  by `GET /support/bootstrap`, cached on the tenant for 5 min, **failure-cached** 60 s.
- **Tenant kill switch:** setting `support.widget.enabled` (default ON only after rollout
  phase 3). If either the Master or the local switch is off, the widget is hidden and the
  navbar item becomes a mailto.

## 44. Rollout strategy
Rollout follows the brief §144. Tenant branches are synced by `git push origin
presentation:<tenant>` (rule `tenant-branches.md`), so code ships everywhere while flags gate
behaviour:

1. `presentation`, with Master flags on for it only.
2. Internal users.
3. One cooperative school. **[NEEDS_DECISION]** which one: hetec or rostan (test phase) look
   ideal; the Élite tenants come last.
4. Several tenants.
5. General.

Each phase has exit metrics: submission success ≥ 99 %, zero cross-tenant findings, median
submission time < 60 s.

Tenant `.env` changes per rollout: `MASTER_SUPPORT_TOKEN`. `MASTER_API_URL` already exists.

## 45. Migration risks
- **Tenant migrations are applied per tenant by `tenant:deploy`.** `support_outbox` is additive
  and tiny, so it is safe.
- **SQLite:** Master migrations must stay SQLite-compatible for tests. There are already 4
  driver guards. FULLTEXT indexes are added behind a driver check.
- **Legacy token migration:** the paywall client reads `api_token`. Hashing it breaks existing
  tenants unless the rotation keeps a plain-text transition column. KLASSCI Care avoids this by
  using a new table and new env var, and 000a deprecates the old path gradually.
- **Deployment status normalisation (000c)** touches historic rows: a data migration maps
  `success→completed`, or the reverse. Choose one, with widgets and scopes aligned.

## 46. ADRs required

| # | Decision |
|---|---|
| ADR-01 | Dedicated hashed credential table vs Sanctum tokenable Tenant |
| ADR-02 | Master authorization: config capability registry vs spatie/permission / Filament Shield |
| ADR-03 | Entity naming language (English vs French) for Care models |
| ADR-04 | Reference format and sequence strategy |
| ADR-05 | Transactional outbox (Master) for GitHub/notifications |
| ADR-06 | Screenshot engine — decided: vendored html2canvas + file fallback |
| ADR-07 | JSON schema validation approach (internal vs package) |
| ADR-08 | Semantic search / vectors: deferred until measured need |
| ADR-09 | GitHub App vs PAT |
| ADR-10 | Tenant status refresh: pull vs HMAC push |
| ADR-11 | Queue worker on shared hosting |
| ADR-12 | Attachment storage: local private disk vs object storage (R2 would need `league/flysystem-aws-s3-v3`) |
| ADR-13 | AI data processing legal basis and retention |

## 47. Ticket dependency graph

```
000a legacy API hardening ─┐  (security, independent of Care but same boundary)
000b queue + scheduler consolidation ───────────────┐
000c deployment status normalisation ─────────┐     │
001 audit (this doc) → 002 domain blueprint (this doc)
      │
003 Master DB foundation ─┬─ 004 capabilities/gates
                          └─ 005 scoped credentials ── 006 POST/GET tickets API (idempotent)
                                                              │
007 tenant ClientMasterSupport + outbox ─── 008 widget (text) ─── 009 context collector
      │                                                               │
010 "Mes demandes" ────────────────────────────── 011 Filament SupportTicketResource
      ╰────────────── SLICE 1 DONE (production quality, flags, tests) ──────────────╯
012 messages ─ 013 attachments ─ 014 screenshot ─ 015 annotation
016 request ids ─ 017 FE error buffer ─ 018 fingerprints (needs 000b)
019 classification (deterministic) ─ 020 KnownIssue ─ 027 self-service
021 permission diag ─ 022 config diag (tenant signed endpoint)
023 AI abstraction (needs ADR-13) ─ 024 intake ─ 025 clarification ─ 026 duplicates ─ 051 adversarial
028 severity ─ 029 SLA
030 incidents ─ 031 clustering (needs 018) ─ 032 deploy correlation (needs 000c)
033 product requests ─ 034 similarity ─ 045 decision inbox ─ 049 digest ─ 050 weekly
035 engineering issue ─ 036 code archaeologist ─ 037 regression agent
038 GitHub creator (ADR-09) ─ 039 webhooks ─ 040 releases ─ 041 deploy linkage ─ 042 fix verification ─ 055 CSAT/reopen
043 KB ─ 044 KB drafts
046/047/048 dashboards   052 security sentinel   053 redaction (before 023 ships)
054 zone selection   056 flags/rollout (from slice 1)   057–060 hardening & audits
```

**Slice 1 = 003, 004, 005, 006, 007, 008, 009, 010, 011** (+ 056 minimal flags, + 016 request id,
which is cheap and makes the 500 page useful immediately).

## 48. Blocking questions ONLY

These questions block **slice 1**:

1. **Approval of this blueprint and slice 1 scope.** The repo rule `feature-delivery-methodology`
   requires an explicit OK before coding.
2. **ADR-01:** a dedicated hashed credential table (recommended) or a Sanctum tokenable Tenant?
3. **Entry-point placement:** a navbar menu item plus the 500 page (recommended), or a third
   floating button next to the chatbot?
4. **Who in a school may see the school's tickets.** A new permission
   `support.tickets.view_school`: which default roles get it (proposed: `superAdmin` only;
   schools grant it through custom roles)?

These questions block later slices, and are listed so they can be answered in parallel:

5. **ADR-13 (legal):** may redacted ticket content be sent to Anthropic for triage? This blocks
   023+.
6. **ADR-11:** a queue worker via cron on LWS. Acceptable? This blocks every async slice (000b).
7. **ADR-09:** a GitHub App or a PAT for `James10192/KLASSCIv2`? This blocks 038.
8. **Commercial SLA values per plan.** Until answered, only internal targets are enforced.
