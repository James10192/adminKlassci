<?php

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Group;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantAggregationService
{
    protected TenantConnectionManager $connectionManager;

    // Why: financials volatility varies — paiement validé doit être visible sous 2min, inscriptions changent lentement.
    protected const CACHE_TTL_KPIS = 300;
    protected const CACHE_TTL_FINANCIALS = 120;
    protected const CACHE_TTL_ENROLLMENT = 600;
    protected const CACHE_TTL_HEALTH = 300;
    protected const CACHE_TTL_AGING = 180;

    protected const AGING_BUCKETS = ['0-30', '31-60', '61-90', '90+'];

    // Request-scoped memoization for cross-tenant heavy fetches.
    private array $billingContextCache = [];
    private array $tableExistsCache = [];

    public function __construct(TenantConnectionManager $connectionManager)
    {
        $this->connectionManager = $connectionManager;
    }

    /**
     * Exécute une opération avec une connexion tenant, fermeture garantie.
     */
    private function withTenantConnection(Tenant $tenant, callable $fn): mixed
    {
        $conn = $this->connectionManager->createConnection($tenant);
        try {
            return $fn($conn);
        } finally {
            $this->connectionManager->closeConnection($conn);
        }
    }

    /**
     * Itère sur les tenants actifs en logguant les erreurs individuelles sans bloquer.
     * Utilise Concurrency::run (process pool) quand >2 tenants — l'overhead process (~50-100ms)
     * est plus rentable seulement si assez de tenants à traiter en parallèle.
     * @return array<string, mixed> keyed by tenant code
     */
    private function aggregateAcrossTenants(Group $group, string $methodName, string $label): array
    {
        $tenants = $group->activeTenants;

        if ($tenants->count() <= 2 || config('concurrency.default') === 'sync') {
            return $this->aggregateAcrossTenantsSync($tenants, $methodName, $label);
        }

        $tasks = [];
        foreach ($tenants as $tenant) {
            $tasks[$tenant->code] = function () use ($tenant, $methodName, $label) {
                try {
                    return app(self::class)->{$methodName}($tenant);
                } catch (\Exception $e) {
                    Log::error("{$label} failed for {$tenant->code}: {$e->getMessage()}");
                    return null;
                }
            };
        }

        try {
            $results = Concurrency::run($tasks);
            return array_filter($results, fn ($r) => $r !== null);
        } catch (\Exception $e) {
            Log::warning("Concurrency::run failed for {$label}, falling back to sync: {$e->getMessage()}");
            return $this->aggregateAcrossTenantsSync($tenants, $methodName, $label);
        }
    }

    private function aggregateAcrossTenantsSync($tenants, string $methodName, string $label): array
    {
        $results = [];
        foreach ($tenants as $tenant) {
            try {
                $results[$tenant->code] = $this->{$methodName}($tenant);
            } catch (\Exception $e) {
                Log::error("{$label} failed for {$tenant->code}: {$e->getMessage()}");
            }
        }
        return $results;
    }

    /**
     * Pré-charge inscriptions + categories + subscriptions + configurations pour un tenant.
     * Mémoïsé par (tenant, année) sur le cycle de vie du request.
     */
    private function loadBillingContext(string $conn, int $tenantId, int $anneeId): array
    {
        $key = "{$tenantId}_{$anneeId}";
        if (isset($this->billingContextCache[$key])) {
            return $this->billingContextCache[$key];
        }

        $inscriptions = DB::connection($conn)
            ->table('esbtp_inscriptions')
            ->whereIn('status', ['active', 'en_attente', 'validée'])
            ->where('annee_universitaire_id', $anneeId)
            ->get(['id', 'filiere_id', 'niveau_id', 'affectation_status', 'status', 'workflow_step', 'etudiant_id', 'created_at']);

        $categories = DB::connection($conn)
            ->table('esbtp_frais_categories')
            ->where('is_active', true)
            ->get(['id', 'is_mandatory', 'default_amount']);

        $subscriptions = DB::connection($conn)
            ->table('esbtp_frais_subscriptions')
            ->where('is_active', true)
            ->whereIn('inscription_id', $inscriptions->pluck('id'))
            ->get(['inscription_id', 'frais_category_id', 'amount'])
            ->groupBy('inscription_id');

        $configurations = DB::connection($conn)
            ->table('esbtp_frais_configurations')
            ->where('is_active', true)
            ->whereIn('frais_category_id', $categories->pluck('id'))
            ->get(['frais_category_id', 'filiere_id', 'niveau_id', 'amount', 'amount_affecte', 'amount_reaffecte', 'amount_non_affecte'])
            ->groupBy(fn ($c) => $c->frais_category_id . '_' . $c->filiere_id . '_' . $c->niveau_id);

        return $this->billingContextCache[$key] = compact('inscriptions', 'categories', 'subscriptions', 'configurations');
    }

    /**
     * Résout le montant dû d'une catégorie pour une inscription (mandatory/optional + config filière/niveau + default).
     * Source de vérité : réplique ESBTPComptabiliteController::calculerTotalDu() côté tenant.
     */
    private function resolveCategoryAmount($inscription, $category, $inscSubs, $configurations): float
    {
        $sub = $inscSubs->firstWhere('frais_category_id', $category->id);

        if (! $category->is_mandatory) {
            return $sub ? (float) $sub->amount : 0.0;
        }

        if ($sub) {
            return (float) $sub->amount;
        }

        $key = $category->id . '_' . $inscription->filiere_id . '_' . $inscription->niveau_id;
        $config = $configurations->get($key, collect())->first();

        if (! $config) {
            return (float) ($category->default_amount ?? 0);
        }

        // Column name derived from affectation_status: 'affecté' → amount_affecte, 'non_affecté' → amount_non_affecte.
        $status = $inscription->affectation_status ?? 'affecté';
        $field = 'amount_' . str_replace('é', 'e', $status);

        return (float) ($config->{$field} ?? $config->amount ?? $category->default_amount ?? 0);
    }

    /**
     * Get consolidated KPIs for an entire group.
     */
    public function getGroupKpis(Group $group): array
    {
        return Cache::remember(
            "group_{$group->id}_kpis",
            self::CACHE_TTL_KPIS,
            fn () => $this->computeGroupKpis($group)
        );
    }

    /**
     * Get KPIs for a single tenant.
     */
    public function getTenantKpis(Tenant $tenant): array
    {
        return Cache::remember(
            "tenant_{$tenant->id}_kpis",
            self::CACHE_TTL_KPIS,
            fn () => $this->computeTenantKpis($tenant)
        );
    }

    /**
     * Get financial data for all tenants in a group.
     */
    public function getGroupFinancials(Group $group): array
    {
        return Cache::remember(
            "group_{$group->id}_financials",
            self::CACHE_TTL_FINANCIALS,
            fn () => $this->computeGroupFinancials($group)
        );
    }

    /**
     * Get enrollment data for all tenants in a group.
     */
    public function getGroupEnrollment(Group $group): array
    {
        return Cache::remember(
            "group_{$group->id}_enrollment",
            self::CACHE_TTL_ENROLLMENT,
            fn () => $this->computeGroupEnrollment($group)
        );
    }

    /**
     * Outstanding aging buckets (30/60/90+ days) cross-tenant.
     * Répond à "qui me doit de l'argent et depuis combien de temps ?"
     */
    public function getGroupOutstandingAging(Group $group): array
    {
        return Cache::remember(
            "group_{$group->id}_aging",
            self::CACHE_TTL_AGING,
            fn () => $this->computeGroupOutstandingAging($group)
        );
    }

    /**
     * Health metrics : quotas critiques, abonnements expirants, reliquats actifs, attrition.
     * Les 5 KPIs qui manquaient totalement au portail groupe.
     */
    public function getGroupHealthMetrics(Group $group): array
    {
        return Cache::remember(
            "group_{$group->id}_health",
            self::CACHE_TTL_HEALTH,
            fn () => $this->computeGroupHealthMetrics($group)
        );
    }

    /**
     * MoM/YoY trends : encaissements, inscriptions, présence.
     */
    public function getGroupTrends(Group $group): array
    {
        return Cache::remember(
            "group_{$group->id}_trends",
            self::CACHE_TTL_FINANCIALS,
            fn () => $this->computeGroupTrends($group)
        );
    }

    /**
     * Force refresh all cached data for a group.
     */
    public function refreshGroupCache(Group $group): void
    {
        Cache::forget("group_{$group->id}_kpis");
        Cache::forget("group_{$group->id}_financials");
        Cache::forget("group_{$group->id}_enrollment");
        Cache::forget("group_{$group->id}_aging");
        Cache::forget("group_{$group->id}_health");
        Cache::forget("group_{$group->id}_trends");

        foreach ($group->tenants as $tenant) {
            Cache::forget("tenant_{$tenant->id}_kpis");
        }
    }

    // ─── Private computation methods ────────────────────────────────

    private function computeGroupKpis(Group $group): array
    {
        $totals = [
            'total_students' => 0,
            'total_inscriptions' => 0,
            'total_revenue_expected' => 0,
            'total_revenue_collected' => 0,
            'total_staff' => 0,
            'establishments' => [],
        ];

        $perTenant = $this->aggregateAcrossTenants($group, 'computeTenantKpis', 'TenantKpis');

        foreach ($group->activeTenants as $tenant) {
            $kpis = $perTenant[$tenant->code] ?? $this->emptyKpis($tenant);
            $totals['total_students'] += $kpis['students'];
            $totals['total_inscriptions'] += $kpis['inscriptions'];
            $totals['total_revenue_expected'] += $kpis['revenue_expected'];
            $totals['total_revenue_collected'] += $kpis['revenue_collected'];
            $totals['total_staff'] += $kpis['staff'];
            $totals['establishments'][$tenant->code] = $kpis;
        }

        $totals['collection_rate'] = $totals['total_revenue_expected'] > 0
            ? min(100, round(($totals['total_revenue_collected'] / $totals['total_revenue_expected']) * 100, 1))
            : 0;

        $totals['has_surplus'] = $totals['total_revenue_collected'] > $totals['total_revenue_expected'];

        $totals['establishment_count'] = count($totals['establishments']);

        // Weighted by student count.
        $weightedAttendanceSum = 0;
        $studentsForAttendance = 0;
        foreach ($totals['establishments'] as $est) {
            if (!($est['error'] ?? false) && ($est['students'] ?? 0) > 0) {
                $weightedAttendanceSum += ($est['attendance_rate'] ?? 0) * $est['students'];
                $studentsForAttendance += $est['students'];
            }
        }
        $totals['avg_attendance_rate'] = $studentsForAttendance > 0
            ? round($weightedAttendanceSum / $studentsForAttendance, 1)
            : 0;

        return $totals;
    }

    private function computeTenantKpis(Tenant $tenant): array
    {
        $conn = $this->connectionManager->createConnection($tenant);

        try {
            $currentYear = DB::connection($conn)
                ->table('esbtp_annee_universitaires')
                ->where('is_current', 1)
                ->first();

            if (!$currentYear) {
                return $this->emptyKpis($tenant);
            }

            // Active inscriptions (workflow_step = etudiant_cree)
            $inscriptions = DB::connection($conn)
                ->table('esbtp_inscriptions')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'active')
                ->where('workflow_step', 'etudiant_cree')
                ->count();

            // Total unique students with active inscription this year
            $students = DB::connection($conn)
                ->table('esbtp_inscriptions')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'active')
                ->where('workflow_step', 'etudiant_cree')
                ->distinct()
                ->count('etudiant_id');

            // Revenue expected: same logic as ESBTPComptabiliteController::calculerTotalDu()
            // Iterates inscriptions × mandatory categories with subscription/config/default fallback
            $revenueExpected = $this->computeRevenueExpected($conn, $tenant->id, $currentYear->id);

            // Revenue: collected (all validated payments for this academic year)
            // Matches suivi-categories which counts ALL validated payments
            $revenueCollected = (float) DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'validé')
                ->sum('montant');

            // Staff count
            $staff = DB::connection($conn)
                ->table('users')
                ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->whereIn('roles.name', ['enseignant', 'coordinateur', 'secretaire', 'comptable'])
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->distinct()
                ->count('users.id');

            // Attendance rate (last 30 days)
            $attendanceRate = $this->computeAttendanceRate($conn);

            $result = [
                'tenant_id' => $tenant->id,
                'tenant_code' => $tenant->code,
                'tenant_name' => $tenant->name,
                'academic_year' => $currentYear->name ?: ($currentYear->libelle ?: 'N/A'),
                'students' => $students,
                'inscriptions' => $inscriptions,
                'revenue_expected' => $revenueExpected,
                'revenue_collected' => $revenueCollected,
                'collection_rate' => $revenueExpected > 0
                    ? min(100, round(($revenueCollected / $revenueExpected) * 100, 1))
                    : 0,
                'has_surplus' => $revenueCollected > $revenueExpected,
                'staff' => $staff,
                'attendance_rate' => $attendanceRate,
                'status' => $tenant->status,
                'plan' => $tenant->plan,
                'error' => false,
            ];

            return $result;

        } catch (\Exception $e) {
            Log::error("computeTenantKpis failed for {$tenant->code}: {$e->getMessage()}");
            return $this->emptyKpis($tenant);
        } finally {
            $this->connectionManager->closeConnection($conn);
        }
    }

    private function computeGroupFinancials(Group $group): array
    {
        $perTenant = $this->aggregateAcrossTenants($group, 'computeTenantFinancials', 'Financials');

        $financials = [];
        foreach ($group->activeTenants as $tenant) {
            $financials[$tenant->code] = $perTenant[$tenant->code] ?? $this->emptyFinancials($tenant);
        }

        return $financials;
    }

    private function computeTenantFinancials(Tenant $tenant): array
    {
        return $this->withTenantConnection($tenant, function (string $conn) use ($tenant) {
            $currentYear = DB::connection($conn)->table('esbtp_annee_universitaires')->where('is_current', 1)->first();
            if (! $currentYear) {
                return $this->emptyFinancials($tenant);
            }

            $monthlyRevenue = DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'validé')
                ->selectRaw('MONTH(date_paiement) as month, SUM(montant) as total')
                ->groupByRaw('MONTH(date_paiement)')
                ->pluck('total', 'month')
                ->toArray();

            $totalExpected = $this->computeRevenueExpected($conn, $tenant->id, $currentYear->id);

            $totalCollected = (float) DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'validé')
                ->sum('montant');

            $byType = DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'validé')
                ->selectRaw('type_paiement, SUM(montant) as total, COUNT(*) as count')
                ->groupBy('type_paiement')
                ->get()
                ->keyBy('type_paiement')
                ->toArray();

            return [
                'tenant_name' => $tenant->name,
                'revenue_expected' => $totalExpected,
                'revenue_collected' => $totalCollected,
                'outstanding' => max(0, $totalExpected - $totalCollected),
                'surplus' => max(0, $totalCollected - $totalExpected),
                'collection_rate' => $totalExpected > 0
                    ? min(100, round(($totalCollected / $totalExpected) * 100, 1))
                    : 0,
                'monthly_revenue' => $monthlyRevenue,
                'by_type' => $byType,
            ];
        });
    }

    private function computeGroupEnrollment(Group $group): array
    {
        $perTenant = $this->aggregateAcrossTenants($group, 'computeTenantEnrollment', 'Enrollment');

        $enrollment = [];
        foreach ($group->activeTenants as $tenant) {
            $enrollment[$tenant->code] = $perTenant[$tenant->code]
                ?? ['tenant_name' => $tenant->name, 'filieres' => [], 'classes' => []];
        }

        return $enrollment;
    }

    private function computeTenantEnrollment(Tenant $tenant): array
    {
        return $this->withTenantConnection($tenant, function (string $conn) use ($tenant) {
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
        });
    }

    private function computeAttendanceRate(string $conn): float
    {
        try {
            $stats = DB::connection($conn)
                ->table('esbtp_attendances')
                ->where('date', '>=', now()->subDays(30))
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present
                ")
                ->first();

            if (!$stats || $stats->total === 0) {
                return 0;
            }

            return round(($stats->present / $stats->total) * 100, 1);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Replicate ESBTPComptabiliteController::calculerTotalDu() logic in raw SQL.
     */
    private function computeRevenueExpected(string $conn, int $tenantId, int $anneeId): float
    {
        try {
            $ctx = $this->loadBillingContext($conn, $tenantId, $anneeId);

            if ($ctx['inscriptions']->isEmpty()) {
                return 0;
            }

            $totalDue = 0;
            foreach ($ctx['inscriptions'] as $inscription) {
                $inscSubs = $ctx['subscriptions']->get($inscription->id, collect());
                foreach ($ctx['categories'] as $category) {
                    $totalDue += $this->resolveCategoryAmount($inscription, $category, $inscSubs, $ctx['configurations']);
                }
            }

            return $totalDue;
        } catch (\Exception $e) {
            Log::warning("computeRevenueExpected failed: {$e->getMessage()}");
            return 0;
        }
    }

    // ─── PR1 portail groupe : aging, health, trends (20 avril 2026) ─────

    private function computeGroupOutstandingAging(Group $group): array
    {
        $aggregated = array_fill_keys(self::AGING_BUCKETS, ['count' => 0, 'amount' => 0]);
        $aggregated['by_tenant'] = [];

        $perTenant = $this->aggregateAcrossTenants($group, 'computeTenantOutstandingAging', 'Aging');

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
     * Per-tenant outstanding aging. Bucket by inscription date (simple, not deadline-based).
     * Why: la logique deadline-based (payment_deadline_days par catégorie) vit dans
     * ESBTPComptabiliteController::getImpayesAging() tenant-side. Ici on reste sur inscription_date
     * pour garder ça léger cross-tenant.
     */
    private function computeTenantOutstandingAging(Tenant $tenant): array
    {
        return $this->withTenantConnection($tenant, function (string $conn) use ($tenant) {
            $currentYear = DB::connection($conn)->table('esbtp_annee_universitaires')->where('is_current', 1)->first();
            if (! $currentYear) {
                return $this->emptyAging();
            }

            $ctx = $this->loadBillingContext($conn, $tenant->id, $currentYear->id);

            // Filter to actively-enrolled students only for aging — avoid bucketing pending inscriptions.
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
                    $totalDue += $this->resolveCategoryAmount($inscription, $category, $inscSubs, $ctx['configurations']);
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
        });
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
        $healthDetails = $this->aggregateAcrossTenants($group, 'computeTenantHealthDetails', 'HealthDetails');

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

    private function computeTenantHealthDetails(Tenant $tenant): array
    {
        $empty = ['active_reliquats' => 0, 'attrition_rate' => null, 'previous_year_inscriptions' => 0];

        try {
            return $this->withTenantConnection($tenant, function (string $conn) use ($tenant, $empty) {
                $currentYear = DB::connection($conn)->table('esbtp_annee_universitaires')->where('is_current', 1)->first();
                if (! $currentYear) {
                    return $empty;
                }

                $activeReliquats = $this->tenantHasTable($conn, $tenant->id, 'esbtp_reliquats_details')
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
            });
        } catch (\Exception $e) {
            Log::error("Tenant health details failed for {$tenant->code}: {$e->getMessage()}");
            return $empty;
        }
    }

    private function tenantHasTable(string $conn, int $tenantId, string $table): bool
    {
        $key = "{$tenantId}_{$table}";
        if (! isset($this->tableExistsCache[$key])) {
            $this->tableExistsCache[$key] = DB::connection($conn)->getSchemaBuilder()->hasTable($table);
        }
        return $this->tableExistsCache[$key];
    }

    private function computeGroupTrends(Group $group): array
    {
        $trends = [
            'revenue_mom' => ['current' => 0, 'previous' => 0, 'delta_pct' => 0],
            'revenue_yoy' => ['current' => 0, 'previous' => 0, 'delta_pct' => 0],
            'inscriptions_yoy' => ['current' => 0, 'previous' => 0, 'delta_pct' => 0],
            'by_tenant' => [],
        ];

        $perTenant = $this->aggregateAcrossTenants($group, 'computeTenantTrends', 'Trends');

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

    private function computeTenantTrends(Tenant $tenant): array
    {
        try {
            return $this->withTenantConnection($tenant, function (string $conn) {
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
            });
        } catch (\Exception $e) {
            Log::error("Tenant trends failed for {$tenant->code}: {$e->getMessage()}");
            return $this->emptyTrends();
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

    private function emptyKpis(Tenant $tenant): array
    {
        return [
            'tenant_id' => $tenant->id,
            'tenant_code' => $tenant->code,
            'tenant_name' => $tenant->name,
            'students' => 0,
            'inscriptions' => 0,
            'revenue_expected' => 0,
            'revenue_collected' => 0,
            'collection_rate' => 0,
            'staff' => 0,
            'attendance_rate' => 0,
            'academic_year' => 'N/A',
            'status' => $tenant->status,
            'plan' => $tenant->plan,
            'error' => true,
        ];
    }

    private function emptyFinancials(Tenant $tenant): array
    {
        return [
            'tenant_name' => $tenant->name,
            'revenue_expected' => 0,
            'revenue_collected' => 0,
            'outstanding' => 0,
            'surplus' => 0,
            'collection_rate' => 0,
            'monthly_revenue' => [],
            'by_type' => [],
        ];
    }
}
