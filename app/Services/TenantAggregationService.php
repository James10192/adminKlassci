<?php

namespace App\Services;

use App\Contracts\Group\GroupFinancialsProviderInterface;
use App\Contracts\Group\GroupKpiProviderInterface;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Group;
use App\Models\Tenant;
use App\Services\Group\EnrollmentTrendAnalyzer;
use App\Services\Group\HealthCheckAlertResolver;
use App\Services\Group\SubscriptionTierResolver;
use App\Services\Group\TenantAggregator;
use App\Services\Group\TenantBillingContext;
use App\Support\Period\PeriodFactory;
use App\Support\Period\PeriodInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delegator service — preserves the pre-PR4a public API used by Filament widgets.
 *
 * PR4a-2 split KPI + Financials into dedicated providers (App\Services\Group\*).
 * This class proxies those calls via the container + caches under a new `group_v2_` prefix
 * to avoid a thundering-herd on deploy (old keys expire naturally).
 *
 * Enrollment / Aging / Health / Trends remain inline — their dedicated split is deferred
 * to PR4b/c if concrete dette surfaces (YAGNI).
 *
 * Widget migration to the providers directly is planned for PR4c.
 */
class TenantAggregationService
{
    // Why: financials volatility varies — paiement validé doit être visible sous 2min, inscriptions changent lentement.
    protected const CACHE_TTL_KPIS = 300;
    protected const CACHE_TTL_FINANCIALS = 120;
    protected const CACHE_TTL_ENROLLMENT = 600;
    protected const CACHE_TTL_HEALTH = 300;
    protected const CACHE_TTL_AGING = 180;

    protected const AGING_BUCKETS = ['0-30', '31-60', '61-90', '90+'];

    // Cache key prefix bumped in PR4a-2 to avoid thundering-herd at deploy.
    // Keys: group_v2_{id}_{kpis|financials|enrollment|aging|health|trends} and group_v2_tenant_{id}_kpis.
    protected const CACHE_KEY_PREFIX = 'group_v2';

    public function __construct(
        protected TenantConnectionManager $connectionManager,
        protected TenantAggregator $aggregator,
        protected TenantBillingContext $billingContext,
        protected GroupKpiProviderInterface $kpiProvider,
        protected GroupFinancialsProviderInterface $financialsProvider,
        protected SubscriptionTierResolver $tierResolver,
        protected HealthCheckAlertResolver $healthResolver,
        protected EnrollmentTrendAnalyzer $enrollmentAnalyzer,
    ) {
    }

    /**
     * Cache key builder with mandatory period suffix (PR4d).
     * For methods that don't take a Period (enrollment, health), pass the default
     * to keep the suffix format uniform.
     */
    private function cacheKey(int $groupId, string $suffix, PeriodInterface $period): string
    {
        return self::CACHE_KEY_PREFIX . "_{$groupId}_{$suffix}_{$period->cacheKey()}";
    }

    public function hasFreshGroupKpis(Group $group, ?PeriodInterface $period = null): bool
    {
        $period ??= PeriodFactory::default();

        return Cache::has($this->cacheKey($group->id, 'kpis', $period));
    }

    public function getGroupKpis(Group $group, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();

        return Cache::remember(
            $this->cacheKey($group->id, 'kpis', $period),
            self::CACHE_TTL_KPIS,
            fn () => $this->kpiProvider->computeGroupKpis($group, $period)
        );
    }

    public function getTenantKpis(Tenant $tenant, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();

        return Cache::remember(
            self::CACHE_KEY_PREFIX . "_tenant_{$tenant->id}_kpis_{$period->cacheKey()}",
            self::CACHE_TTL_KPIS,
            fn () => $this->kpiProvider->computeTenantKpis($tenant, $period)
        );
    }

    public function getGroupFinancials(Group $group, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();

        return Cache::remember(
            $this->cacheKey($group->id, 'financials', $period),
            self::CACHE_TTL_FINANCIALS,
            fn () => $this->financialsProvider->computeGroupFinancials($group, $period)
        );
    }

    /**
     * Enrollment is NOT period-aware — inscriptions are academic-year-scoped,
     * not calendar-window-scoped. Period suffix in the cache key still applied
     * for uniformity (using the default period), so widgets that toggle Period
     * don't race against stale enrollment cache under the old key shape.
     */
    public function getGroupEnrollment(Group $group): array
    {
        $period = PeriodFactory::default();

        return Cache::remember(
            $this->cacheKey($group->id, 'enrollment', $period),
            self::CACHE_TTL_ENROLLMENT,
            fn () => $this->computeGroupEnrollment($group)
        );
    }

    public function getGroupOutstandingAging(Group $group, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();

        return Cache::remember(
            $this->cacheKey($group->id, 'aging', $period),
            self::CACHE_TTL_AGING,
            fn () => $this->computeGroupOutstandingAging($group, $period)
        );
    }

    /**
     * Health metrics (quota/subscription/reliquats/attrition) are snapshot-style —
     * Period deliberately not accepted. Uniform key suffix via default period.
     */
    public function getGroupHealthMetrics(Group $group): array
    {
        $period = PeriodFactory::default();

        return Cache::remember(
            $this->cacheKey($group->id, 'health', $period),
            self::CACHE_TTL_HEALTH,
            fn () => $this->computeGroupHealthMetrics($group)
        );
    }

    public function getGroupTrends(Group $group, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();

        return Cache::remember(
            $this->cacheKey($group->id, 'trends', $period),
            self::CACHE_TTL_FINANCIALS,
            fn () => $this->computeGroupTrends($group, $period)
        );
    }

    /**
     * Forgets the default-period keys. Non-default period caches expire naturally
     * via TTL (acceptable for user-triggered refresh — the default dashboard is
     * what the "Actualiser" button is about).
     */
    public function refreshGroupCache(Group $group): void
    {
        $period = PeriodFactory::default();
        foreach (['kpis', 'financials', 'enrollment', 'aging', 'health', 'trends'] as $suffix) {
            Cache::forget($this->cacheKey($group->id, $suffix, $period));
        }

        foreach ($group->tenants as $tenant) {
            Cache::forget(self::CACHE_KEY_PREFIX . "_tenant_{$tenant->id}_kpis_{$period->cacheKey()}");
        }
    }

    // ─── Remaining computation methods (not yet split in PR4a) ──────────

    private function computeGroupEnrollment(Group $group): array
    {
        // Enrollment is academic-year-scoped — no Period forwarded to tenants.
        $perTenant = $this->aggregator->aggregate($group, self::class, 'computeTenantEnrollment', 'Enrollment');

        $enrollment = [];
        foreach ($group->activeTenants as $tenant) {
            $enrollment[$tenant->code] = $perTenant[$tenant->code]
                ?? ['tenant_name' => $tenant->name, 'filieres' => [], 'classes' => []];
        }

        return $enrollment;
    }

    /**
     * @param  ?PeriodInterface  $period  Accepted to satisfy TenantAggregator's
     *                                    uniform call signature — IGNORED here:
     *                                    inscription counts are academic-year-scoped,
     *                                    not date-window-scoped.
     */
    public function computeTenantEnrollment(Tenant $tenant, ?PeriodInterface $period = null): array
    {
        $conn = $this->connectionManager->createConnection($tenant);

        try {
            $currentYear = DB::connection($conn)->table('esbtp_annee_universitaires')->where('is_current', 1)->first();
            if (! $currentYear) {
                return ['tenant_name' => $tenant->name, 'filieres' => [], 'classes' => []];
            }

            $byFiliere = DB::connection($conn)
                ->table('esbtp_inscriptions as i')
                ->join('esbtp_filieres as f', 'i.filiere_id', '=', 'f.id')
                ->where('i.annee_universitaire_id', $currentYear->id)
                ->where('i.status', 'active')
                ->where('i.workflow_step', 'etudiant_cree')
                ->selectRaw('f.name as filiere_name, COUNT(*) as count')
                ->groupBy('f.name')
                ->orderByDesc('count')
                ->get()
                ->toArray();

            $classOccupancy = DB::connection($conn)
                ->table('esbtp_classes as c')
                ->leftJoin('esbtp_inscriptions as i', function ($join) use ($currentYear) {
                    $join->on('c.id', '=', 'i.classe_id')
                        ->where('i.annee_universitaire_id', '=', $currentYear->id)
                        ->where('i.status', '=', 'active')
                        ->where('i.workflow_step', '=', 'etudiant_cree');
                })
                ->selectRaw('c.name as class_name, c.capacity, COUNT(i.id) as enrolled')
                ->groupBy('c.id', 'c.name', 'c.capacity')
                ->having('enrolled', '>', 0)
                ->orderByDesc('enrolled')
                ->limit(20)
                ->get()
                ->toArray();

            return [
                'tenant_name' => $tenant->name,
                'filieres' => $byFiliere,
                'classes' => $classOccupancy,
            ];
        } catch (\Exception $e) {
            Log::error("[group-refactor] computeTenantEnrollment failed for {$tenant->code}: {$e->getMessage()}");
            return ['tenant_name' => $tenant->name, 'filieres' => [], 'classes' => []];
        } finally {
            $this->connectionManager->closeConnection($conn);
        }
    }

    private function computeGroupOutstandingAging(Group $group, PeriodInterface $period): array
    {
        $aggregated = array_fill_keys(self::AGING_BUCKETS, ['count' => 0, 'amount' => 0]);
        $aggregated['by_tenant'] = [];

        $perTenant = $this->aggregator->aggregate($group, self::class, 'computeTenantOutstandingAging', 'Aging', $period);

        foreach ($perTenant as $tenantCode => $aging) {
            foreach (self::AGING_BUCKETS as $bucket) {
                $aggregated[$bucket]['count'] += $aging[$bucket]['count'];
                $aggregated[$bucket]['amount'] += $aging[$bucket]['amount'];
            }
            $tenant = $group->activeTenants->firstWhere('code', $tenantCode);
            $aggregated['by_tenant'][$tenantCode] = array_merge(
                ['tenant_name' => $tenant?->name],
                $aging
            );
        }

        $aggregated['total_count'] = array_sum(array_column(array_intersect_key($aggregated, array_flip(self::AGING_BUCKETS)), 'count'));
        $aggregated['total_amount'] = array_sum(array_column(array_intersect_key($aggregated, array_flip(self::AGING_BUCKETS)), 'amount'));

        return $aggregated;
    }

    /**
     * Aging buckets are computed relative to `$period->endDate()` (PR4d) instead of
     * `now()` — so the dashboard can answer "what were my outstanding dues as of X?"
     * when the user shifts the Period.
     *
     * When called with $period === null (default path), falls back to the default
     * period (CurrentYear) whose endDate is Dec 31 of the current year — this keeps
     * the numbers stable for year-end reviews and bridges pre-PR4d behaviour.
     */
    public function computeTenantOutstandingAging(Tenant $tenant, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();
        $conn = $this->connectionManager->createConnection($tenant);

        try {
            $currentYear = DB::connection($conn)->table('esbtp_annee_universitaires')->where('is_current', 1)->first();
            if (! $currentYear) {
                return $this->emptyAging();
            }

            $ctx = $this->billingContext->load($conn, $tenant->id, $currentYear->id);

            $activeInscriptions = $ctx['inscriptions']->where('status', 'active')->where('workflow_step', 'etudiant_cree');

            if ($activeInscriptions->isEmpty()) {
                return $this->emptyAging();
            }

            $paiementsByInsc = DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('status', 'validé')
                ->whereNull('deleted_at')
                ->whereIn('inscription_id', $activeInscriptions->pluck('id'))
                ->selectRaw('inscription_id, SUM(montant) as total')
                ->groupBy('inscription_id')
                ->pluck('total', 'inscription_id');

            $buckets = $this->emptyAging();
            // Reference date for aging bucketing — min(now, Period::endDate()).
            // Aging in the future doesn't make sense (days-since-creation is clamped
            // at today), so we cap at now(). For default CurrentYearPeriod whose
            // endDate is Dec 31, this preserves exact pre-PR4d behaviour.
            $referenceDate = $period->endDate()->isFuture() ? now() : $period->endDate();

            foreach ($activeInscriptions as $inscription) {
                $inscSubs = $ctx['subscriptions']->get($inscription->id, collect());
                $totalDue = 0;
                foreach ($ctx['categories'] as $category) {
                    $totalDue += $this->billingContext->resolveCategoryAmount($inscription, $category, $inscSubs, $ctx['configurations']);
                }

                $outstanding = max(0, $totalDue - (float) ($paiementsByInsc[$inscription->id] ?? 0));
                if ($outstanding <= 0) {
                    continue;
                }

                $daysOld = $inscription->created_at
                    ? (int) \Carbon\Carbon::parse($inscription->created_at)->diffInDays($referenceDate)
                    : 0;
                $bucket = match (true) {
                    $daysOld <= 30 => '0-30',
                    $daysOld <= 60 => '31-60',
                    $daysOld <= 90 => '61-90',
                    default => '90+',
                };

                $buckets[$bucket]['count']++;
                $buckets[$bucket]['amount'] += $outstanding;
            }

            return $buckets;
        } catch (\Exception $e) {
            Log::error("[group-refactor] computeTenantOutstandingAging failed for {$tenant->code}: {$e->getMessage()}");
            return $this->emptyAging();
        } finally {
            $this->connectionManager->closeConnection($conn);
        }
    }

    private function computeGroupHealthMetrics(Group $group): array
    {
        $health = [
            'quota_critical_count' => 0,
            'quota_exceeded_count' => 0,
            'subscription_expiring_count' => 0,
            'subscription_expired_count' => 0,
            'subscription_urgent_count' => 0,
            'subscription_warning_count' => 0,
            'subscription_info_count' => 0,
            'subscription_expiring_total_count' => 0,
            'subscription_worst_tier' => null,
            'subscription_worst_tenant_name' => null,
            'subscription_worst_tenant_code' => null,
            'subscription_worst_days_remaining' => null,
            'active_reliquats_total' => 0,
            'attrition_rate_avg' => 0,
            'plan_overage_count' => 0,
            'stale_tenant_count' => 0,
            'unhealthy_tenant_count' => 0,
            'ssl_expiring_count' => 0,
            'ssl_critical_count' => 0,
            'enrollment_decline_count' => 0,
            'alerts' => [],
        ];

        $attritionData = [];
        // Health metrics are snapshot-style — no Period forwarded to tenants.
        $healthDetails = $this->aggregator->aggregate($group, self::class, 'computeTenantHealthDetails', 'HealthDetails');

        $healthAlertsEnabled = (bool) config('group_portal.health_alerts_enabled', true);
        $monthlyEnrollments = [];

        if ($healthAlertsEnabled) {
            // Eager-load the two latestOfMany relations once per group call to
            // avoid one extra SELECT per tenant in the alert loop below.
            $group->activeTenants->load(['latestHealthCheck', 'latestSslHealthCheck']);

            // Fan out to tenant databases in parallel — we need 3 months of
            // new inscriptions per tenant for the decline analyzer, and the
            // aggregator already handles connection pooling + error isolation.
            $monthlyEnrollments = $this->aggregator->aggregate(
                $group,
                self::class,
                'computeTenantMonthlyEnrollments',
                'MonthlyEnrollments'
            );
        }

        foreach ($group->activeTenants as $tenant) {
            $planMismatchFired = false;

            if ($healthAlertsEnabled) {
                $planMismatchFired = $this->collectPlanMismatchAlerts($tenant, $health);
                $this->collectStaleTenantAlerts($tenant, $health);
                $this->collectSslExpiryAlerts($tenant, $health);
                $this->collectEnrollmentDeclineAlerts(
                    $tenant,
                    $health,
                    $monthlyEnrollments[$tenant->code] ?? null
                );
            }

            $this->collectQuotaAlerts($tenant, $health, $planMismatchFired);
            $this->collectSubscriptionAlerts($tenant, $health);

            $details = $healthDetails[$tenant->code] ?? null;
            if ($details !== null) {
                $health['active_reliquats_total'] += $details['active_reliquats'];

                if ($details['attrition_rate'] !== null && $details['previous_year_inscriptions'] > 0) {
                    $attritionData[] = ['rate' => $details['attrition_rate'], 'weight' => $details['previous_year_inscriptions']];

                    if ($details['attrition_rate'] > 15) {
                        $health['alerts'][] = $this->buildAlert(
                            $tenant,
                            AlertSeverity::Warning,
                            AlertType::HighAttrition,
                            "Attrition élevée : {$details['attrition_rate']}%"
                        );
                    }
                }

                if ($details['active_reliquats'] > 0) {
                    $health['alerts'][] = $this->buildAlert(
                        $tenant,
                        AlertSeverity::Info,
                        AlertType::ActiveReliquats,
                        number_format($details['active_reliquats'], 0, ',', ' ') . ' FCFA de reliquats actifs'
                    );
                }
            }
        }

        if (! empty($attritionData)) {
            $totalWeight = array_sum(array_column($attritionData, 'weight'));
            if ($totalWeight > 0) {
                $weightedSum = array_sum(array_map(fn ($d) => $d['rate'] * $d['weight'], $attritionData));
                $health['attrition_rate_avg'] = round($weightedSum / $totalWeight, 1);
            }
        }

        usort(
            $health['alerts'],
            fn ($a, $b) => AlertSeverity::from($a['severity'])->sortOrder() <=> AlertSeverity::from($b['severity'])->sortOrder()
        );

        return $health;
    }

    private function buildAlert(Tenant $tenant, AlertSeverity $severity, AlertType $type, string $message): array
    {
        return [
            'severity' => $severity->value,
            'tenant_code' => $tenant->code,
            'tenant_name' => $tenant->name,
            'type' => $type->value,
            'message' => $message,
        ];
    }

    /**
     * When `$planMismatchFired` is true, PlanMismatch has already surfaced a
     * richer students-quota alert (with the plan name + upgrade hint) — we
     * skip the generic QuotaExceeded/QuotaCritical here so the widget doesn't
     * show two bullets for the same underlying condition. Other quotas
     * (users, staff, inscriptions, storage) always flow through normally.
     */
    private function collectQuotaAlerts(Tenant $tenant, array &$health, bool $planMismatchFired = false): void
    {
        $quotaPct = $this->computeQuotaPercentages($tenant, skipStudents: $planMismatchFired);

        if ($quotaPct['max'] >= 100) {
            $health['quota_exceeded_count']++;
            $health['alerts'][] = $this->buildAlert(
                $tenant,
                AlertSeverity::Critical,
                AlertType::QuotaExceeded,
                "Quota {$quotaPct['max_type']} dépassé ({$quotaPct['max']}%)"
            );
        } elseif ($quotaPct['max'] >= 90) {
            $health['quota_critical_count']++;
            $health['alerts'][] = $this->buildAlert(
                $tenant,
                AlertSeverity::Warning,
                AlertType::QuotaCritical,
                "Quota {$quotaPct['max_type']} à {$quotaPct['max']}%"
            );
        }
    }

    private function collectSubscriptionAlerts(Tenant $tenant, array &$health): void
    {
        $tier = $this->tierResolver->resolveTier($tenant);

        if ($tier === null) {
            return;
        }

        $daysUntil = $tenant->daysRemaining();
        $severity = $this->tierResolver->severityForTier($tier);

        [$alertType, $message] = match ($tier) {
            SubscriptionTierResolver::TIER_EXPIRED => [
                AlertType::SubscriptionExpired,
                'Abonnement expiré depuis ' . abs($daysUntil) . ' jours',
            ],
            SubscriptionTierResolver::TIER_URGENT => [
                AlertType::SubscriptionExpiring,
                "Abonnement expire dans {$daysUntil} jours (urgent)",
            ],
            SubscriptionTierResolver::TIER_WARNING, SubscriptionTierResolver::TIER_INFO => [
                AlertType::SubscriptionExpiring,
                "Abonnement expire dans {$daysUntil} jours",
            ],
        };

        $health['alerts'][] = $this->buildAlert($tenant, $severity, $alertType, $message);

        match ($tier) {
            SubscriptionTierResolver::TIER_EXPIRED => $health['subscription_expired_count']++,
            SubscriptionTierResolver::TIER_URGENT => $health['subscription_urgent_count']++,
            SubscriptionTierResolver::TIER_WARNING => $health['subscription_warning_count']++,
            SubscriptionTierResolver::TIER_INFO => $health['subscription_info_count']++,
        };

        // Legacy counters preserved for widgets already reading them.
        if ($tier === SubscriptionTierResolver::TIER_URGENT
            || $tier === SubscriptionTierResolver::TIER_WARNING
            || $tier === SubscriptionTierResolver::TIER_INFO) {
            $health['subscription_expiring_count']++;
        }

        $health['subscription_expiring_total_count']++;

        $currentWorst = $health['subscription_worst_tier'];
        $newWorst = $this->tierResolver->worstTier([$currentWorst, $tier]);

        // Promote when: (a) tier rank is strictly worse, or (b) same tier but
        // fewer days remaining, or (c) same tier and same days but earlier
        // name — deterministic ordering so UI never flickers between ties.
        $shouldPromote = $newWorst !== $currentWorst
            || ($newWorst === $tier
                && $currentWorst === $tier
                && ($health['subscription_worst_days_remaining'] === null
                    || $daysUntil < $health['subscription_worst_days_remaining']
                    || ($daysUntil === $health['subscription_worst_days_remaining']
                        && strcasecmp($tenant->name, (string) $health['subscription_worst_tenant_name']) < 0)));

        if ($shouldPromote) {
            $health['subscription_worst_tier'] = $newWorst;
            $health['subscription_worst_tenant_name'] = $tenant->name;
            $health['subscription_worst_tenant_code'] = $tenant->code;
            $health['subscription_worst_days_remaining'] = $daysUntil;
        }
    }

    /**
     * Returns true when a PlanMismatch alert was emitted (so the caller can
     * suppress the generic QuotaExceeded for the students quota). Warning
     * fires at >=warning_pct, Critical fires at >=critical_pct (default
     * 100% and 110% — the gap lets a tenant sit briefly "at limit" without
     * looking red immediately after a spike).
     */
    private function collectPlanMismatchAlerts(Tenant $tenant, array &$health): bool
    {
        if ($tenant->max_students <= 0) {
            return false;
        }

        $pct = ($tenant->current_students / $tenant->max_students) * 100;
        $warningPct = (float) config('group_portal.plan_overage_warning_pct', 100);
        $criticalPct = (float) config('group_portal.plan_overage_critical_pct', 110);

        if ($pct < $warningPct) {
            return false;
        }

        $severity = $pct >= $criticalPct ? AlertSeverity::Critical : AlertSeverity::Warning;
        $planLabel = $tenant->plan ? ucfirst($tenant->plan) : 'Actuel';
        $message = "Plan {$planLabel} dépassé — {$tenant->current_students}/{$tenant->max_students} étudiants. Passer au palier supérieur.";

        $health['alerts'][] = $this->buildAlert($tenant, $severity, AlertType::PlanMismatch, $message);
        $health['plan_overage_count']++;

        return true;
    }

    /**
     * Two mutually-exclusive outcomes per tenant: `unhealthy` (latest overall
     * check failed → Critical) takes precedence over `stale` (last_deployed_at
     * older than threshold → Warning). Resolver returns null when neither
     * applies, keeping the noise out of the alerts list.
     */
    private function collectStaleTenantAlerts(Tenant $tenant, array &$health): void
    {
        $tier = $this->healthResolver->resolveStaleTier($tenant, $tenant->latestHealthCheck);

        if ($tier === null) {
            return;
        }

        $severity = $this->healthResolver->severityForStaleTier($tier);

        if ($tier === HealthCheckAlertResolver::STALE_TIER_UNHEALTHY) {
            $health['unhealthy_tenant_count']++;
            $detail = $tenant->latestHealthCheck?->details ?: 'aucun détail';
            $message = "Health check échec : {$detail}";
        } else {
            $health['stale_tenant_count']++;
            $days = $tenant->last_deployed_at
                ? (int) $tenant->last_deployed_at->diffInDays(now())
                : 0;
            $message = "Tenant inactif depuis {$days} jours (dernier déploiement).";
        }

        $health['alerts'][] = $this->buildAlert($tenant, $severity, AlertType::StaleTenant, $message);
    }

    /**
     * Reads `metadata.days_remaining` from the latest ssl_certificate check
     * (already computed by the health-check command — don't re-parse
     * `expires_at` here). Thresholds default to 15/7 days, more conservative
     * than the per-tenant health status flip (30/7).
     */
    private function collectSslExpiryAlerts(Tenant $tenant, array &$health): void
    {
        $tier = $this->healthResolver->resolveSslTier($tenant->latestSslHealthCheck);

        if ($tier === null) {
            return;
        }

        $severity = $this->healthResolver->severityForSslTier($tier);
        $days = (int) ($tenant->latestSslHealthCheck->metadata['days_remaining'] ?? 0);

        if ($tier === HealthCheckAlertResolver::SSL_TIER_CRITICAL) {
            $health['ssl_critical_count']++;
        } else {
            $health['ssl_expiring_count']++;
        }

        $message = "Certificat SSL expire dans {$days} jour" . ($days > 1 ? 's' : '') . '.';
        $health['alerts'][] = $this->buildAlert($tenant, $severity, AlertType::SslExpiring, $message);
    }

    /**
     * $monthlyData shape: ['current' => int, 'previous' => int, 'two_months_ago' => int]
     * Returned by computeTenantMonthlyEnrollments — when the tenant query
     * fails, the value is null and we skip the alert silently (logging was
     * already done inside computeTenantMonthlyEnrollments).
     */
    private function collectEnrollmentDeclineAlerts(Tenant $tenant, array &$health, ?array $monthlyData): void
    {
        if ($monthlyData === null) {
            return;
        }

        $result = $this->enrollmentAnalyzer->detectDecline(
            (int) ($monthlyData['current'] ?? 0),
            (int) ($monthlyData['previous'] ?? 0),
            (int) ($monthlyData['two_months_ago'] ?? 0)
        );

        if ($result === null) {
            return;
        }

        $health['enrollment_decline_count']++;
        $message = "Inscriptions en baisse : -{$result['drop_pct_current']}% vs mois dernier, -{$result['drop_pct_previous']}% le mois précédent.";

        $health['alerts'][] = $this->buildAlert(
            $tenant,
            AlertSeverity::Warning,
            AlertType::EnrollmentDecline,
            $message
        );
    }

    private function computeQuotaPercentages(Tenant $tenant, bool $skipStudents = false): array
    {
        $usagePct = [];

        if ($tenant->max_users > 0) {
            $usagePct['users'] = round(($tenant->current_users / $tenant->max_users) * 100, 1);
        }
        if ($tenant->max_staff > 0) {
            $usagePct['staff'] = round(($tenant->current_staff / $tenant->max_staff) * 100, 1);
        }
        if (! $skipStudents && $tenant->max_students > 0) {
            $usagePct['students'] = round(($tenant->current_students / $tenant->max_students) * 100, 1);
        }
        if ($tenant->max_inscriptions_per_year > 0) {
            $usagePct['inscriptions'] = round(($tenant->current_inscriptions_per_year / $tenant->max_inscriptions_per_year) * 100, 1);
        }
        if ($tenant->max_storage_mb > 0) {
            $usagePct['storage'] = round(($tenant->current_storage_mb / $tenant->max_storage_mb) * 100, 1);
        }

        $max = 0;
        $maxType = null;
        foreach ($usagePct as $type => $pct) {
            if ($pct > $max) {
                $max = $pct;
                $maxType = $type;
            }
        }

        return ['usage' => $usagePct, 'max' => $max, 'max_type' => $maxType];
    }

    /**
     * @param  ?PeriodInterface  $period  Accepted for uniform aggregator call shape — IGNORED.
     *                                    Health metrics (quotas, subscriptions, attrition) are
     *                                    inherently snapshot-at-now, not period-windowed.
     */
    public function computeTenantHealthDetails(Tenant $tenant, ?PeriodInterface $period = null): array
    {
        $empty = ['active_reliquats' => 0, 'attrition_rate' => null, 'previous_year_inscriptions' => 0];

        $conn = null;
        try {
            $conn = $this->connectionManager->createConnection($tenant);

            $currentYear = DB::connection($conn)->table('esbtp_annee_universitaires')->where('is_current', 1)->first();
            if (! $currentYear) {
                return $empty;
            }

            $activeReliquats = $this->billingContext->hasTable($conn, $tenant->id, 'esbtp_reliquats_details')
                ? (float) DB::connection($conn)
                    ->table('esbtp_reliquats_details')
                    ->join('esbtp_inscriptions', 'esbtp_reliquats_details.inscription_destination_id', '=', 'esbtp_inscriptions.id')
                    ->where('esbtp_inscriptions.annee_universitaire_id', $currentYear->id)
                    ->whereIn('esbtp_reliquats_details.statut', ['actif', 'partiellement_regle'])
                    ->sum('esbtp_reliquats_details.solde_restant')
                : 0.0;

            $previousYear = DB::connection($conn)
                ->table('esbtp_annee_universitaires')
                ->where('id', '<', $currentYear->id)
                ->orderByDesc('id')
                ->first();

            $attritionRate = null;
            $previousYearInscriptions = 0;

            if ($previousYear) {
                $previousYearStudents = DB::connection($conn)
                    ->table('esbtp_inscriptions')
                    ->where('annee_universitaire_id', $previousYear->id)
                    ->where('status', 'active')
                    ->where('workflow_step', 'etudiant_cree')
                    ->pluck('etudiant_id')
                    ->unique();

                $previousYearInscriptions = $previousYearStudents->count();

                if ($previousYearInscriptions > 0) {
                    $retainedCount = DB::connection($conn)
                        ->table('esbtp_inscriptions')
                        ->where('annee_universitaire_id', $currentYear->id)
                        ->where('status', 'active')
                        ->where('workflow_step', 'etudiant_cree')
                        ->whereIn('etudiant_id', $previousYearStudents)
                        ->distinct()
                        ->count('etudiant_id');

                    $attritionRate = round((($previousYearInscriptions - $retainedCount) / $previousYearInscriptions) * 100, 1);
                }
            }

            return [
                'active_reliquats' => $activeReliquats,
                'attrition_rate' => $attritionRate,
                'previous_year_inscriptions' => $previousYearInscriptions,
            ];
        } catch (\Exception $e) {
            Log::error("[group-refactor] computeTenantHealthDetails failed for {$tenant->code}: {$e->getMessage()}");
            return $empty;
        } finally {
            if ($conn !== null) {
                $this->connectionManager->closeConnection($conn);
            }
        }
    }

    private function computeGroupTrends(Group $group, PeriodInterface $period): array
    {
        $trends = [
            'revenue_mom' => ['current' => 0, 'previous' => 0, 'delta_pct' => 0],
            'revenue_yoy' => ['current' => 0, 'previous' => 0, 'delta_pct' => 0],
            'inscriptions_yoy' => ['current' => 0, 'previous' => 0, 'delta_pct' => 0],
            'by_tenant' => [],
        ];

        $perTenant = $this->aggregator->aggregate($group, self::class, 'computeTenantTrends', 'Trends', $period);

        foreach ($perTenant as $tenantCode => $tenantTrends) {
            foreach (['revenue_mom', 'revenue_yoy', 'inscriptions_yoy'] as $key) {
                $trends[$key]['current'] += $tenantTrends[$key]['current'];
                $trends[$key]['previous'] += $tenantTrends[$key]['previous'];
            }
            $tenant = $group->activeTenants->firstWhere('code', $tenantCode);
            $trends['by_tenant'][$tenantCode] = array_merge(['tenant_name' => $tenant?->name], $tenantTrends);
        }

        foreach (['revenue_mom', 'revenue_yoy', 'inscriptions_yoy'] as $key) {
            $prev = $trends[$key]['previous'];
            $curr = $trends[$key]['current'];
            $trends[$key]['delta_pct'] = $prev > 0
                ? round((($curr - $prev) / $prev) * 100, 1)
                : ($curr > 0 ? 100 : 0);
        }

        return $trends;
    }

    /**
     * @param  ?PeriodInterface  $period  Accepted for uniform aggregator call shape.
     *                                    NOT USED in PR4d: MoM/YoY windows remain calendar-relative
     *                                    (now()-based) to preserve byte-identical output with
     *                                    pre-PR4d callers. Period-relative trend shifting is
     *                                    deferred — requires defining "previous period" semantics
     *                                    for arbitrary CustomRange (a PR of its own).
     */
    public function computeTenantTrends(Tenant $tenant, ?PeriodInterface $period = null): array
    {
        $conn = null;
        try {
            $conn = $this->connectionManager->createConnection($tenant);

            $currentYear = DB::connection($conn)->table('esbtp_annee_universitaires')->where('is_current', 1)->first();
            if (! $currentYear) {
                return $this->emptyTrends();
            }

            $previousYear = DB::connection($conn)
                ->table('esbtp_annee_universitaires')
                ->where('id', '<', $currentYear->id)
                ->orderByDesc('id')
                ->first();

            $inscriptionsCount = fn ($anneeId) => DB::connection($conn)
                ->table('esbtp_inscriptions')
                ->where('annee_universitaire_id', $anneeId)
                ->where('status', 'active')
                ->where('workflow_step', 'etudiant_cree')
                ->distinct()
                ->count('etudiant_id');

            $revenue = function ($anneeId, $startDate = null, $endDate = null) use ($conn) {
                $q = DB::connection($conn)
                    ->table('esbtp_paiements')
                    ->where('annee_universitaire_id', $anneeId)
                    ->where('status', 'validé')
                    ->whereNull('deleted_at');
                if ($startDate && $endDate) {
                    $q->whereBetween('date_paiement', [$startDate, $endDate]);
                }
                return (float) $q->sum('montant');
            };

            return [
                'revenue_mom' => [
                    'current' => $revenue($currentYear->id, now()->startOfMonth(), now()->endOfMonth()),
                    'previous' => $revenue($currentYear->id, now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()),
                ],
                'revenue_yoy' => [
                    'current' => $revenue($currentYear->id),
                    'previous' => $previousYear ? $revenue($previousYear->id) : 0,
                ],
                'inscriptions_yoy' => [
                    'current' => $inscriptionsCount($currentYear->id),
                    'previous' => $previousYear ? $inscriptionsCount($previousYear->id) : 0,
                ],
            ];
        } catch (\Exception $e) {
            Log::error("[group-refactor] computeTenantTrends failed for {$tenant->code}: {$e->getMessage()}");
            return $this->emptyTrends();
        } finally {
            if ($conn !== null) {
                $this->connectionManager->closeConnection($conn);
            }
        }
    }

    /**
     * Counts new inscriptions in the current, previous, and month-before
     * windows — feeds `EnrollmentTrendAnalyzer` for PR7b decline detection.
     *
     * @param  ?PeriodInterface  $period  Accepted for uniform aggregator call shape — IGNORED.
     *                                    Windows are calendar-month-relative (Carbon now()),
     *                                    not period-relative, because the decline check is
     *                                    a rolling-window signal that shouldn't shift when
     *                                    the user scrolls the Period selector.
     *
     * @return array{current: int, previous: int, two_months_ago: int}
     */
    public function computeTenantMonthlyEnrollments(Tenant $tenant, ?PeriodInterface $period = null): array
    {
        $empty = ['current' => 0, 'previous' => 0, 'two_months_ago' => 0];

        $conn = null;
        try {
            $conn = $this->connectionManager->createConnection($tenant);

            $currentStart = now()->startOfMonth();
            $previousStart = now()->subMonth()->startOfMonth();
            $twoMonthsAgoStart = now()->subMonths(2)->startOfMonth();

            $count = fn ($from, $to) => DB::connection($conn)
                ->table('esbtp_inscriptions')
                ->whereBetween('created_at', [$from, $to])
                ->where('status', 'active')
                ->where('workflow_step', 'etudiant_cree')
                ->count();

            return [
                'current' => $count($currentStart, now()),
                'previous' => $count($previousStart, $currentStart->copy()->subSecond()),
                'two_months_ago' => $count($twoMonthsAgoStart, $previousStart->copy()->subSecond()),
            ];
        } catch (\Exception $e) {
            Log::error("[group-refactor] computeTenantMonthlyEnrollments failed for {$tenant->code}: {$e->getMessage()}");
            return $empty;
        } finally {
            if ($conn !== null) {
                $this->connectionManager->closeConnection($conn);
            }
        }
    }

    private function emptyAging(): array
    {
        return array_fill_keys(self::AGING_BUCKETS, ['count' => 0, 'amount' => 0]);
    }

    private function emptyTrends(): array
    {
        return [
            'revenue_mom' => ['current' => 0, 'previous' => 0],
            'revenue_yoy' => ['current' => 0, 'previous' => 0],
            'inscriptions_yoy' => ['current' => 0, 'previous' => 0],
        ];
    }
}
