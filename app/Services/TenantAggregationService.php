<?php

namespace App\Services;

use App\Contracts\Group\GroupFinancialsProviderInterface;
use App\Contracts\Group\GroupKpiProviderInterface;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Group;
use App\Models\Tenant;
use App\Services\Group\TenantAggregator;
use App\Services\Group\TenantBillingContext;
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
    ) {
    }

    public function getGroupKpis(Group $group): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . "_{$group->id}_kpis",
            self::CACHE_TTL_KPIS,
            fn () => $this->kpiProvider->computeGroupKpis($group)
        );
    }

    public function getTenantKpis(Tenant $tenant): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . "_tenant_{$tenant->id}_kpis",
            self::CACHE_TTL_KPIS,
            fn () => $this->kpiProvider->computeTenantKpis($tenant)
        );
    }

    public function getGroupFinancials(Group $group): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . "_{$group->id}_financials",
            self::CACHE_TTL_FINANCIALS,
            fn () => $this->financialsProvider->computeGroupFinancials($group)
        );
    }

    public function getGroupEnrollment(Group $group): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . "_{$group->id}_enrollment",
            self::CACHE_TTL_ENROLLMENT,
            fn () => $this->computeGroupEnrollment($group)
        );
    }

    public function getGroupOutstandingAging(Group $group): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . "_{$group->id}_aging",
            self::CACHE_TTL_AGING,
            fn () => $this->computeGroupOutstandingAging($group)
        );
    }

    public function getGroupHealthMetrics(Group $group): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . "_{$group->id}_health",
            self::CACHE_TTL_HEALTH,
            fn () => $this->computeGroupHealthMetrics($group)
        );
    }

    public function getGroupTrends(Group $group): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . "_{$group->id}_trends",
            self::CACHE_TTL_FINANCIALS,
            fn () => $this->computeGroupTrends($group)
        );
    }

    public function refreshGroupCache(Group $group): void
    {
        foreach (['kpis', 'financials', 'enrollment', 'aging', 'health', 'trends'] as $suffix) {
            Cache::forget(self::CACHE_KEY_PREFIX . "_{$group->id}_{$suffix}");
        }

        foreach ($group->tenants as $tenant) {
            Cache::forget(self::CACHE_KEY_PREFIX . "_tenant_{$tenant->id}_kpis");
        }
    }

    // ─── Remaining computation methods (not yet split in PR4a) ──────────

    private function computeGroupEnrollment(Group $group): array
    {
        $perTenant = $this->aggregator->aggregate($group, self::class, 'computeTenantEnrollment', 'Enrollment');

        $enrollment = [];
        foreach ($group->activeTenants as $tenant) {
            $enrollment[$tenant->code] = $perTenant[$tenant->code]
                ?? ['tenant_name' => $tenant->name, 'filieres' => [], 'classes' => []];
        }

        return $enrollment;
    }

    public function computeTenantEnrollment(Tenant $tenant): array
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

    private function computeGroupOutstandingAging(Group $group): array
    {
        $aggregated = array_fill_keys(self::AGING_BUCKETS, ['count' => 0, 'amount' => 0]);
        $aggregated['by_tenant'] = [];

        $perTenant = $this->aggregator->aggregate($group, self::class, 'computeTenantOutstandingAging', 'Aging');

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

    public function computeTenantOutstandingAging(Tenant $tenant): array
    {
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
            $now = now();

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
                    ? (int) \Carbon\Carbon::parse($inscription->created_at)->diffInDays($now)
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
            'active_reliquats_total' => 0,
            'attrition_rate_avg' => 0,
            'alerts' => [],
        ];

        $attritionData = [];
        $healthDetails = $this->aggregator->aggregate($group, self::class, 'computeTenantHealthDetails', 'HealthDetails');

        foreach ($group->activeTenants as $tenant) {
            $this->collectQuotaAlerts($tenant, $health);
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

    private function collectQuotaAlerts(Tenant $tenant, array &$health): void
    {
        $quotaPct = $this->computeQuotaPercentages($tenant);

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
        if (! $tenant->subscription_end_date) {
            return;
        }

        $daysUntil = (int) now()->diffInDays($tenant->subscription_end_date, false);

        if ($daysUntil < 0) {
            $health['subscription_expired_count']++;
            $health['alerts'][] = $this->buildAlert(
                $tenant,
                AlertSeverity::Critical,
                AlertType::SubscriptionExpired,
                'Abonnement expiré depuis ' . abs($daysUntil) . ' jours'
            );
        } elseif ($daysUntil <= 30) {
            $health['subscription_expiring_count']++;
            $health['alerts'][] = $this->buildAlert(
                $tenant,
                AlertSeverity::Warning,
                AlertType::SubscriptionExpiring,
                "Abonnement expire dans {$daysUntil} jours"
            );
        }
    }

    private function computeQuotaPercentages(Tenant $tenant): array
    {
        $usagePct = [];

        if ($tenant->max_users > 0) {
            $usagePct['users'] = round(($tenant->current_users / $tenant->max_users) * 100, 1);
        }
        if ($tenant->max_staff > 0) {
            $usagePct['staff'] = round(($tenant->current_staff / $tenant->max_staff) * 100, 1);
        }
        if ($tenant->max_students > 0) {
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

    public function computeTenantHealthDetails(Tenant $tenant): array
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

    private function computeGroupTrends(Group $group): array
    {
        $trends = [
            'revenue_mom' => ['current' => 0, 'previous' => 0, 'delta_pct' => 0],
            'revenue_yoy' => ['current' => 0, 'previous' => 0, 'delta_pct' => 0],
            'inscriptions_yoy' => ['current' => 0, 'previous' => 0, 'delta_pct' => 0],
            'by_tenant' => [],
        ];

        $perTenant = $this->aggregator->aggregate($group, self::class, 'computeTenantTrends', 'Trends');

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

    public function computeTenantTrends(Tenant $tenant): array
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
