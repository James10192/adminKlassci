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
