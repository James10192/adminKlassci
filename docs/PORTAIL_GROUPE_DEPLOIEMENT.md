# Portail Groupe — Déploiement Production

## Variables d'environnement requises

### Master (adminKlassci)

```env
# SSO cross-app — MÊME valeur que dans chaque tenant du groupe
GROUP_SSO_SHARED_SECRET=<64 hex chars>

# Concurrency driver pour TenantAggregationService
# - process : spawn PHP subprocess (~50ms overhead/task, fonctionne Windows+Linux)
# - fork    : pcntl_fork (Linux only, ~1ms overhead/task) — requires `spatie/fork`
# - sync    : séquentiel (dev/debug)
CONCURRENCY_DRIVER=fork
```

Installer `spatie/fork` sur le serveur prod Linux :

```bash
composer require spatie/fork
```

### Tenant (KLASSCIv2 par tenant)

```env
# IDENTIQUE au master — sinon SSO rejeté (signature mismatch)
GROUP_SSO_SHARED_SECRET=<même valeur qu'adminKlassci>

# Déjà existant (API paywall)
MASTER_API_URL=https://admin.klassci.com/api
MASTER_API_TOKEN=<tenant-specific token depuis master>
TENANT_CODE=<code tenant>
```

## Génération du secret

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Mettre la sortie dans `.env` **master** ET dans chaque tenant du groupe.

## Rotation du secret

1. Générer nouveau secret
2. Déploiement coordonné : master + tous les tenants d'un groupe en même temps
3. Vérifier logs master : pas de warning `[SSO] GROUP_SSO_SHARED_SECRET is too short`
4. Tester 1 SSO depuis le portail groupe → tenant
5. Si échec, rollback rapide en restaurant ancien secret

**TODO futur** : implémenter `kid` (key ID) claim pour supporter 2 secrets simultanés pendant la rotation (zero-downtime).

## Provisioning des users côté tenant

Pour que le SSO fonctionne, le `GroupMember.email` (master) doit correspondre à un `User.email` existant côté tenant. Si absent :

- Portail groupe : bouton "Ouvrir" génère token OK
- Tenant : `/auth/sso-from-group?token=...` répond `403 Accès refusé` + log `error_reason=user_not_found`

Solution : script de provisioning qui crée des users "observer" read-only côté tenant à partir des GroupMember du groupe propriétaire.

## Commandes diagnostic

```bash
# Vérifier santé portail groupe (dev/local uniquement)
php artisan group:benchmark-concurrency --group=rostan

# Lister SSO logs master
SELECT * FROM group_portal_sso_logs ORDER BY created_at DESC LIMIT 20;

# Côté tenant : succès/échecs SSO
SELECT success, error_reason, COUNT(*) FROM group_portal_sso_logs
WHERE created_at >= NOW() - INTERVAL 1 DAY
GROUP BY success, error_reason;
```

## Invalidation cache événementielle

Après chaque paiement validé tenant-side, `GroupCacheInvalidator::invalidate()` envoie POST (fire-and-forget via `dispatch()->afterResponse()`) vers :

```
POST https://admin.klassci.com/api/tenants/{code}/cache/invalidate
Authorization: Bearer <tenant api_token>
Content-Type: application/json

{"trigger": "paiement_validated"}
```

Master invalide alors les 6 caches du groupe (kpis, financials, enrollment, aging, health, trends) → portail rafraîchit immédiatement.

Si `MASTER_API_URL` / `MASTER_API_TOKEN` absents côté tenant, l'appel est skip silencieusement (pas d'erreur user).

## Monitoring recommandé

- Alerter sur `group_portal_sso_logs.success=0` rate > 10/min (potentielle brute-force)
- Alerter sur `[SSO] GROUP_SSO_SHARED_SECRET is too short` dans logs
- Dashboard : taux d'ouverture SSO par fondateur / tenant

---

## PR4b — Period selector topbar (activation progressive)

Livré dans #10. Le sélecteur de période apparaît dans la topbar du portail groupe (button + listbox ARIA + custom range). **Désactivé par défaut** pour ne pas impacter la production au merge. Wiring aux widgets = PR4c.

### Activer en prod

Ajouter dans `.env` :

```env
GROUP_PORTAL_PERIOD_SELECTOR=true
# Optionnel — défaut 300ms
GROUP_PORTAL_PERIOD_DEBOUNCE_MS=300
```

Puis `php artisan config:clear` côté serveur (Filament + Laravel lisent le flag depuis `config('group_portal.period_selector_enabled')` qui peut être cached via `config:cache`).

### Désactiver en urgence

```env
GROUP_PORTAL_PERIOD_SELECTOR=false
```

+ `php artisan config:clear`. Le partial Blade retourne une string vide — zéro render, zéro JS, zéro CSS supplémentaire chargé.

### a11y — Keyboard map du sélecteur

| Touche | Action |
|---|---|
| `Tab` | Focus sur le bouton |
| `Enter` / `Espace` / `↓` | Ouvrir la listbox |
| `↑` / `↓` | Naviguer entre les options |
| `Enter` / `Espace` | Sélectionner l'option focalisée |
| `Escape` | Fermer la listbox / le panneau custom |
| `Tab` (dans panneau custom) | Focus piégé entre les 2 date pickers + bouton Fermer |

### Sanitization XSS

Toutes les valeurs lues depuis la query string (`?period=`, `?start=`, `?end=`) passent par `PeriodType::tryFromSafe()` (whitelist d'enum) ou `CarbonImmutable::parse()` en try/catch. Les payloads malicieux retombent silencieusement sur le défaut `current-year` sans logger ni throw.

### Non-wiring aux widgets

En PR4b, la période sélectionnée est **persistée en URL seulement** — les 7 widgets du dashboard (`KpiOverviewWidget`, `GroupAlertsWidget`, `GroupAgingWidget`, `EstablishmentCardsWidget`, etc.) n'en tiennent pas encore compte. Ils continuent d'afficher "année universitaire en cours" comme avant. Le wiring (avec contrat d'event figé) est livré dans PR4c.

### Badge alertes sidebar

Le navigation item "Établissements" affiche un badge dynamique :
- Pas de badge : aucune alerte
- Badge `warning` (orange) : ≥ 1 alerte non-critique
- Badge `danger` (rouge) : ≥ 1 alerte `critical`

Compte calculé via `TenantAggregationService::getGroupHealthMetrics()['alerts']` — cache TTL 5 min, donc l'impact perf est négligeable.

---

## PR4c — Event contract (pour subscribers futurs)

Livré dans #12. Le sélecteur de période émet un event frozen lors de chaque changement validé (flag ON + résolution valide). **Aucun widget ne consomme en PR4c** — l'infra est prête pour la migration groupée des widgets time-windowed en PR4d.

### Event

    klassci:group-portal:period-change

Payload : `{type, start (ISO8601), end (ISO8601), label}`. Pas de `cacheKey` (fuite impl backend).

Doc complète + exemples subscribers : [GROUP_PORTAL_EVENT_CONTRACT.md](GROUP_PORTAL_EVENT_CONTRACT.md).

### Quand dispatch

- `period_selector_enabled=true` ET
- Period résolue non-null (custom-range avec dates manquantes/malformées → skip)

### Non-wiring

Les 7 widgets (`KpiOverviewWidget`, `GroupAlertsWidget`, `GroupAgingWidget`, `EstablishmentCardsWidget`, `GroupWelcomeWidget`, `RevenueComparisonWidget`, `EnrollmentWidget`) ne réagissent pas encore à l'event en PR4c. Si un observateur externe souscrit avant PR4d, il fonctionnera immédiatement (contract stable).

### Migration widgets (PR4d prévu)

Groupe cohérent à migrer ensemble : `KpiOverviewWidget` + `RevenueComparisonWidget` + `GroupAgingWidget` (tous time-windowed MoM/YoY/aging). Les 4 autres widgets (Alerts, EstablishmentCards, Welcome, Enrollment) ne sont PAS période-aware par nature — ne pas migrer.
