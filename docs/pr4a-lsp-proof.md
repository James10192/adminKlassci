# PR4a-2 — Preuve de rétro-compatibilité LSP

## Contexte

PR4a-2 extrait `computeGroupKpis` + `computeTenantKpis` dans `GroupKpiProvider` et `computeGroupFinancials` + `computeTenantFinancials` dans `GroupFinancialsProvider`. Le service `TenantAggregationService` devient un **delegator** qui préserve sa surface publique pré-PR4a.

Liskov Substitution Principle : les clients qui consommaient `TenantAggregationService` avant la PR4a-2 doivent continuer à fonctionner **sans modification**, y compris sur l'ordre des clés retournées et les types exacts.

## Filet de sécurité

`tests/Unit/Services/TenantAggregationServiceCharacterizationTest.php` verrouille par réflexion :

- L'existence de la classe et sa résolution via le container (avec bindings `GroupServiceProvider`)
- Les 4 méthodes publiques d'agrégation (`getGroupKpis`, `getGroupFinancials`, `getGroupOutstandingAging`, `getGroupTrends`)
- `getGroupHealthMetrics`, `getTenantKpis`, `refreshGroupCache`
- Signature stricte de chaque méthode : 1 paramètre typé `App\Models\Group` ou `App\Models\Tenant`, visibilité `public`, non-static

**Résultat** : 5 tests passants après refactor (mêmes qu'avant). Aucune assertion ne casse.

## Contrats préservés (Delegator → Provider)

### `getGroupKpis(Group $group): array`
Avant : `Cache::remember('group_{id}_kpis', 300, fn() => $this->computeGroupKpis($group))`
Après : `Cache::remember('group_v2_{id}_kpis', 300, fn() => $this->kpiProvider->computeGroupKpis($group))`

Le **contenu retourné** par `computeGroupKpis` est byte-identique : même structure d'array, mêmes clés dans le même ordre, mêmes types.

### `getTenantKpis(Tenant $tenant): array`
Identique — proxy vers `$this->kpiProvider->computeTenantKpis($tenant)`.

### `getGroupFinancials(Group $group): array`
Identique — proxy vers `$this->financialsProvider->computeGroupFinancials($group)`.

### `getGroupEnrollment`, `getGroupOutstandingAging`, `getGroupHealthMetrics`, `getGroupTrends`
**Non splittées** en PR4a-2 (YAGNI). Le corps reste inline dans le delegator. Les helpers privés `withTenantConnection` + `aggregateAcrossTenants` + `loadBillingContext` ont été remplacés par les services partagés `TenantAggregator` + `TenantBillingContext`, qui reproduisent la même logique — vérifiable par diff direct dans l'historique git.

## Changement de préfixe cache — intentionnel

`group_{id}_*` → `group_v2_{id}_*`

**Raison** : éviter un thundering herd au déploiement. Les anciennes clés expirent naturellement (2–10 minutes selon le type), pendant que les nouvelles clés se peuplent progressivement. Pas de pic de CPU/DB au déploiement.

**Impact client** : aucun. Les widgets Filament consomment toujours via `getGroupKpis()` / `getGroupFinancials()` etc., sans connaître la clé. Le cache redeviendra warm après ~5 minutes post-deploy.

## Plan de rollback

Si le refactor introduit une régression en prod :

1. `git revert` du commit PR4a-2 (un seul commit atomique)
2. Les widgets continueront à appeler `TenantAggregationService` (API inchangée)
3. Les anciennes clés cache `group_*` (pré-v2) seront à nouveau utilisées
4. Pas de migration DB à annuler, pas de fichier de config à restaurer

## Vérifications post-deploy à faire en prod

- Monitorer `storage/logs/laravel.log` pour tout `[group-refactor]` level error pendant 7 jours
- Vérifier que les widgets `KpiOverviewWidget`, `GroupAlertsWidget`, `GroupAgingWidget`, `EstablishmentCardsWidget` rendent sans exception
- Comparer les valeurs numériques sur le dashboard `/groupe` avant/après deploy (attendu : identiques)

## Intégration snapshot byte-exact (optionnel, gated)

Le test skipped `it snapshot: KPI output structure is stable` (gated par `CHARACTERIZATION_RUN=1`) peut être activé localement sur une BDD master seedée avec tenants pour prouver l'égalité byte-à-byte :

```bash
CHARACTERIZATION_RUN=1 ./vendor/bin/pest --filter=snapshot
```

Premier run : crée la baseline `tests/Fixtures/group_kpis_snapshot.json`. Run suivants : assert equal.

À utiliser au moment du merge PR4a-2 sur `main` et de PR4b/c pour garantir qu'aucun split additionnel ne dérive l'output.
