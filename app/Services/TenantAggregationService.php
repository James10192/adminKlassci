<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantAggregationService
{
    protected TenantConnectionManager $connectionManager;

    // Cache TTL différencié par volatilité des données (décision PR1 portail groupe)
    // Why: critic review 2026-04-20 — 15min trop long pour financier (paiement validé invisible 15min)
    protected const CACHE_TTL_KPIS = 300;        // 5 min — KPIs généraux
    protected const CACHE_TTL_FINANCIALS = 120;  // 2 min — argent: toujours frais
    protected const CACHE_TTL_ENROLLMENT = 600;  // 10 min — inscriptions changent lentement
    protected const CACHE_TTL_HEALTH = 300;      // 5 min — alertes quotas/abonnements
    protected const CACHE_TTL_AGING = 180;       // 3 min — aging impayés

    public function __construct(TenantConnectionManager $connectionManager)
    {
        $this->connectionManager = $connectionManager;
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
        $tenants = $group->activeTenants;
        $totals = [
            'total_students' => 0,
            'total_inscriptions' => 0,
            'total_revenue_expected' => 0,
            'total_revenue_collected' => 0,
            'total_staff' => 0,
            'establishments' => [],
        ];

        foreach ($tenants as $tenant) {
            try {
                $kpis = $this->computeTenantKpis($tenant);
                $totals['total_students'] += $kpis['students'];
                $totals['total_inscriptions'] += $kpis['inscriptions'];
                $totals['total_revenue_expected'] += $kpis['revenue_expected'];
                $totals['total_revenue_collected'] += $kpis['revenue_collected'];
                $totals['total_staff'] += $kpis['staff'];
                $totals['establishments'][$tenant->code] = $kpis;
            } catch (\Exception $e) {
                Log::error("Failed to get KPIs for tenant {$tenant->code}", [
                    'error' => $e->getMessage(),
                ]);
                $totals['establishments'][$tenant->code] = $this->emptyKpis($tenant);
            }
        }

        $totals['collection_rate'] = $totals['total_revenue_expected'] > 0
            ? min(100, round(($totals['total_revenue_collected'] / $totals['total_revenue_expected']) * 100, 1))
            : 0;

        $totals['has_surplus'] = $totals['total_revenue_collected'] > $totals['total_revenue_expected'];

        $totals['establishment_count'] = $tenants->count();

        // Taux de présence moyen pondéré par nb étudiants (PR1 portail groupe)
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
            $revenueExpected = $this->computeRevenueExpected($conn, $currentYear->id);

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
        $tenants = $group->activeTenants;
        $financials = [];

        foreach ($tenants as $tenant) {
            try {
                $conn = $this->connectionManager->createConnection($tenant);
                $currentYear = DB::connection($conn)
                    ->table('esbtp_annee_universitaires')
                    ->where('is_current', 1)
                    ->first();

                if (!$currentYear) {
                    $financials[$tenant->code] = $this->emptyFinancials($tenant);
                    continue;
                }

                // Monthly revenue for current year
                $monthlyRevenue = DB::connection($conn)
                    ->table('esbtp_paiements')
                    ->where('annee_universitaire_id', $currentYear->id)
                    ->where('status', 'validé')
                    ->selectRaw('MONTH(date_paiement) as month, SUM(montant) as total')
                    ->groupByRaw('MONTH(date_paiement)')
                    ->pluck('total', 'month')
                    ->toArray();

                // Total expected: same logic as suivi-categories
                $totalExpected = $this->computeRevenueExpected($conn, $currentYear->id);

                $totalCollected = (float) DB::connection($conn)
                    ->table('esbtp_paiements')
                    ->where('annee_universitaire_id', $currentYear->id)
                    ->where('status', 'validé')
                    ->sum('montant');

                // Payment type breakdown
                $byType = DB::connection($conn)
                    ->table('esbtp_paiements')
                    ->where('annee_universitaire_id', $currentYear->id)
                    ->where('status', 'validé')
                    ->selectRaw('type_paiement, SUM(montant) as total, COUNT(*) as count')
                    ->groupBy('type_paiement')
                    ->get()
                    ->keyBy('type_paiement')
                    ->toArray();

                $financials[$tenant->code] = [
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

                $this->connectionManager->closeConnection($conn);

            } catch (\Exception $e) {
                Log::error("Financial data failed for {$tenant->code}: {$e->getMessage()}");
                $financials[$tenant->code] = $this->emptyFinancials($tenant);
            }
        }

        return $financials;
    }

    private function computeGroupEnrollment(Group $group): array
    {
        $tenants = $group->activeTenants;
        $enrollment = [];

        foreach ($tenants as $tenant) {
            try {
                $conn = $this->connectionManager->createConnection($tenant);
                $currentYear = DB::connection($conn)
                    ->table('esbtp_annee_universitaires')
                    ->where('is_current', 1)
                    ->first();

                if (!$currentYear) {
                    $enrollment[$tenant->code] = ['tenant_name' => $tenant->name, 'filieres' => []];
                    continue;
                }

                // Inscriptions by filiere
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

                // Class occupancy
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

                $enrollment[$tenant->code] = [
                    'tenant_name' => $tenant->name,
                    'filieres' => $byFiliere,
                    'classes' => $classOccupancy,
                ];

                $this->connectionManager->closeConnection($conn);

            } catch (\Exception $e) {
                Log::error("Enrollment data failed for {$tenant->code}: {$e->getMessage()}");
                $enrollment[$tenant->code] = ['tenant_name' => $tenant->name, 'filieres' => [], 'classes' => []];
            }
        }

        return $enrollment;
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
     * Iterates inscriptions × mandatory categories with subscription/config/default fallback.
     */
    private function computeRevenueExpected(string $conn, int $anneeId): float
    {
        try {
            $inscriptions = DB::connection($conn)
                ->table('esbtp_inscriptions')
                ->whereIn('status', ['active', 'en_attente', 'validée'])
                ->where('annee_universitaire_id', $anneeId)
                ->get(['id', 'filiere_id', 'niveau_id', 'affectation_status']);

            if ($inscriptions->isEmpty()) {
                return 0;
            }

            $categories = DB::connection($conn)
                ->table('esbtp_frais_categories')
                ->where('is_active', true)
                ->get();

            $subscriptions = DB::connection($conn)
                ->table('esbtp_frais_subscriptions')
                ->where('is_active', true)
                ->whereIn('inscription_id', $inscriptions->pluck('id'))
                ->get()
                ->groupBy('inscription_id');

            $configurations = DB::connection($conn)
                ->table('esbtp_frais_configurations')
                ->where('is_active', true)
                ->whereIn('frais_category_id', $categories->pluck('id'))
                ->get()
                ->groupBy(fn ($c) => $c->frais_category_id . '_' . $c->filiere_id . '_' . $c->niveau_id);

            $totalDue = 0;

            foreach ($inscriptions as $inscription) {
                $inscSubs = $subscriptions->get($inscription->id, collect());

                foreach ($categories as $category) {
                    $sub = $inscSubs->where('frais_category_id', $category->id)->first();

                    if ($category->is_mandatory) {
                        if ($sub) {
                            $montant = $sub->amount;
                        } else {
                            $key = $category->id . '_' . $inscription->filiere_id . '_' . $inscription->niveau_id;
                            $config = $configurations->get($key, collect())->first();

                            if ($config) {
                                $status = $inscription->affectation_status ?? 'affecté';
                                $field = 'amount_' . str_replace('é', 'e', $status);
                                $montant = $config->{$field} ?? $config->amount ?? $category->default_amount;
                            } else {
                                $montant = $category->default_amount;
                            }
                        }
                    } else {
                        $montant = $sub ? $sub->amount : 0;
                    }

                    $totalDue += $montant;
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
        $aggregated = [
            '0-30' => ['count' => 0, 'amount' => 0],
            '31-60' => ['count' => 0, 'amount' => 0],
            '61-90' => ['count' => 0, 'amount' => 0],
            '90+' => ['count' => 0, 'amount' => 0],
            'by_tenant' => [],
            'total_count' => 0,
            'total_amount' => 0,
        ];

        foreach ($group->activeTenants as $tenant) {
            try {
                $aging = $this->computeTenantOutstandingAging($tenant);
                foreach (['0-30', '31-60', '61-90', '90+'] as $bucket) {
                    $aggregated[$bucket]['count'] += $aging[$bucket]['count'];
                    $aggregated[$bucket]['amount'] += $aging[$bucket]['amount'];
                }
                $aggregated['by_tenant'][$tenant->code] = array_merge(
                    ['tenant_name' => $tenant->name],
                    $aging
                );
            } catch (\Exception $e) {
                Log::error("Aging failed for {$tenant->code}: {$e->getMessage()}");
            }
        }

        $aggregated['total_count'] = array_sum(array_column(array_intersect_key($aggregated, array_flip(['0-30','31-60','61-90','90+'])), 'count'));
        $aggregated['total_amount'] = array_sum(array_column(array_intersect_key($aggregated, array_flip(['0-30','31-60','61-90','90+'])), 'amount'));

        return $aggregated;
    }

    /**
     * Per-tenant outstanding aging. Bucket by inscription date (simple, not deadline-based).
     * Limitation: le calcul "deadline-based" (suivant les payment_deadline_days par catégorie)
     * vit dans ESBTPComptabiliteController::getImpayesAging() tenant-side. Ici on reste sur
     * inscription_date pour garder ça léger cross-tenant.
     */
    private function computeTenantOutstandingAging(Tenant $tenant): array
    {
        $conn = $this->connectionManager->createConnection($tenant);

        try {
            $currentYear = DB::connection($conn)->table('esbtp_annee_universitaires')->where('is_current', 1)->first();
            if (!$currentYear) {
                return $this->emptyAging();
            }

            $inscriptions = DB::connection($conn)
                ->table('esbtp_inscriptions')
                ->where('status', 'active')
                ->where('workflow_step', 'etudiant_cree')
                ->where('annee_universitaire_id', $currentYear->id)
                ->get(['id', 'filiere_id', 'niveau_id', 'affectation_status', 'created_at']);

            if ($inscriptions->isEmpty()) {
                return $this->emptyAging();
            }

            $paiementsByInsc = DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('status', 'validé')
                ->whereNull('deleted_at')
                ->whereIn('inscription_id', $inscriptions->pluck('id'))
                ->selectRaw('inscription_id, SUM(montant) as total')
                ->groupBy('inscription_id')
                ->pluck('total', 'inscription_id');

            $categories = DB::connection($conn)->table('esbtp_frais_categories')->where('is_active', true)->get();
            $subscriptions = DB::connection($conn)
                ->table('esbtp_frais_subscriptions')
                ->where('is_active', true)
                ->whereIn('inscription_id', $inscriptions->pluck('id'))
                ->get()->groupBy('inscription_id');
            $configurations = DB::connection($conn)
                ->table('esbtp_frais_configurations')
                ->where('is_active', true)
                ->whereIn('frais_category_id', $categories->pluck('id'))
                ->get()
                ->groupBy(fn ($c) => $c->frais_category_id . '_' . $c->filiere_id . '_' . $c->niveau_id);

            $buckets = $this->emptyAging();

            foreach ($inscriptions as $inscription) {
                $totalDue = $this->computeInscriptionDue($inscription, $categories, $subscriptions, $configurations);
                $totalPaid = (float) ($paiementsByInsc[$inscription->id] ?? 0);
                $outstanding = max(0, $totalDue - $totalPaid);

                if ($outstanding <= 0) {
                    continue;
                }

                $createdAt = $inscription->created_at ? \Carbon\Carbon::parse($inscription->created_at) : now();
                $daysOld = (int) $createdAt->diffInDays(now());
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
            Log::error("Tenant aging failed for {$tenant->code}: {$e->getMessage()}");
            return $this->emptyAging();
        } finally {
            $this->connectionManager->closeConnection($conn);
        }
    }

    /**
     * Compute expected amount for a single inscription using same logic as computeRevenueExpected.
     */
    private function computeInscriptionDue($inscription, $categories, $subscriptionsByInsc, $configurations): float
    {
        $inscSubs = $subscriptionsByInsc->get($inscription->id, collect());
        $totalDue = 0;

        foreach ($categories as $category) {
            $sub = $inscSubs->where('frais_category_id', $category->id)->first();
            if ($category->is_mandatory) {
                if ($sub) {
                    $montant = $sub->amount;
                } else {
                    $key = $category->id . '_' . $inscription->filiere_id . '_' . $inscription->niveau_id;
                    $config = $configurations->get($key, collect())->first();
                    if ($config) {
                        $status = $inscription->affectation_status ?? 'affecté';
                        $field = 'amount_' . str_replace('é', 'e', $status);
                        $montant = $config->{$field} ?? $config->amount ?? $category->default_amount;
                    } else {
                        $montant = $category->default_amount;
                    }
                }
            } else {
                $montant = $sub ? $sub->amount : 0;
            }
            if ($montant > 0) {
                $totalDue += $montant;
            }
        }

        return (float) $totalDue;
    }

    private function computeGroupHealthMetrics(Group $group): array
    {
        $tenants = $group->activeTenants;

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

        foreach ($tenants as $tenant) {
            // Quota status from master DB (no tenant connection)
            $quotaPct = $this->computeQuotaPercentages($tenant);
            if ($quotaPct['max'] >= 100) {
                $health['quota_exceeded_count']++;
                $health['alerts'][] = [
                    'severity' => 'critical',
                    'tenant_code' => $tenant->code,
                    'tenant_name' => $tenant->name,
                    'type' => 'quota_exceeded',
                    'message' => "Quota {$quotaPct['max_type']} dépassé ({$quotaPct['max']}%)",
                ];
            } elseif ($quotaPct['max'] >= 90) {
                $health['quota_critical_count']++;
                $health['alerts'][] = [
                    'severity' => 'warning',
                    'tenant_code' => $tenant->code,
                    'tenant_name' => $tenant->name,
                    'type' => 'quota_critical',
                    'message' => "Quota {$quotaPct['max_type']} à {$quotaPct['max']}%",
                ];
            }

            // Subscription expiration
            if ($tenant->subscription_end_date) {
                $daysUntil = (int) now()->diffInDays($tenant->subscription_end_date, false);
                if ($daysUntil < 0) {
                    $health['subscription_expired_count']++;
                    $health['alerts'][] = [
                        'severity' => 'critical',
                        'tenant_code' => $tenant->code,
                        'tenant_name' => $tenant->name,
                        'type' => 'subscription_expired',
                        'message' => 'Abonnement expiré depuis ' . abs($daysUntil) . ' jours',
                    ];
                } elseif ($daysUntil <= 30) {
                    $health['subscription_expiring_count']++;
                    $health['alerts'][] = [
                        'severity' => 'warning',
                        'tenant_code' => $tenant->code,
                        'tenant_name' => $tenant->name,
                        'type' => 'subscription_expiring',
                        'message' => "Abonnement expire dans {$daysUntil} jours",
                    ];
                }
            }

            // Reliquats + attrition (needs tenant connection)
            try {
                $details = $this->computeTenantHealthDetails($tenant);
                $health['active_reliquats_total'] += $details['active_reliquats'];

                if ($details['attrition_rate'] !== null && $details['previous_year_inscriptions'] > 0) {
                    $attritionData[] = [
                        'rate' => $details['attrition_rate'],
                        'weight' => $details['previous_year_inscriptions'],
                    ];

                    if ($details['attrition_rate'] > 15) {
                        $health['alerts'][] = [
                            'severity' => 'warning',
                            'tenant_code' => $tenant->code,
                            'tenant_name' => $tenant->name,
                            'type' => 'high_attrition',
                            'message' => "Attrition élevée : {$details['attrition_rate']}%",
                        ];
                    }
                }

                if ($details['active_reliquats'] > 0) {
                    $health['alerts'][] = [
                        'severity' => 'info',
                        'tenant_code' => $tenant->code,
                        'tenant_name' => $tenant->name,
                        'type' => 'active_reliquats',
                        'message' => number_format($details['active_reliquats'], 0, ',', ' ') . ' FCFA de reliquats actifs',
                    ];
                }
            } catch (\Exception $e) {
                Log::error("Health details failed for {$tenant->code}: {$e->getMessage()}");
            }
        }

        // Weighted avg attrition (weighted by previous year students count)
        if (!empty($attritionData)) {
            $totalWeight = array_sum(array_column($attritionData, 'weight'));
            if ($totalWeight > 0) {
                $weightedSum = array_sum(array_map(fn ($d) => $d['rate'] * $d['weight'], $attritionData));
                $health['attrition_rate_avg'] = round($weightedSum / $totalWeight, 1);
            }
        }

        // Sort alerts: critical > warning > info
        $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($health['alerts'], fn ($a, $b) => $severityOrder[$a['severity']] <=> $severityOrder[$b['severity']]);

        return $health;
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
        $conn = $this->connectionManager->createConnection($tenant);

        try {
            $currentYear = DB::connection($conn)->table('esbtp_annee_universitaires')->where('is_current', 1)->first();
            if (!$currentYear) {
                return ['active_reliquats' => 0, 'attrition_rate' => null, 'previous_year_inscriptions' => 0];
            }

            // Active reliquats : ESBTPReliquatDetail actif/partiellement_regle via inscriptions année courante
            $activeReliquats = 0;
            if (DB::connection($conn)->getSchemaBuilder()->hasTable('esbtp_reliquats_details')) {
                $activeReliquats = (float) DB::connection($conn)
                    ->table('esbtp_reliquats_details')
                    ->join('esbtp_inscriptions', 'esbtp_reliquats_details.inscription_destination_id', '=', 'esbtp_inscriptions.id')
                    ->where('esbtp_inscriptions.annee_universitaire_id', $currentYear->id)
                    ->whereIn('esbtp_reliquats_details.statut', ['actif', 'partiellement_regle'])
                    ->sum('esbtp_reliquats_details.solde_restant');
            }

            // Attrition : étudiants année précédente NON réinscrits année courante
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
            Log::error("Tenant health details failed for {$tenant->code}: {$e->getMessage()}");
            return ['active_reliquats' => 0, 'attrition_rate' => null, 'previous_year_inscriptions' => 0];
        } finally {
            $this->connectionManager->closeConnection($conn);
        }
    }

    private function computeGroupTrends(Group $group): array
    {
        $tenants = $group->activeTenants;

        $trends = [
            'revenue_mom' => ['current' => 0, 'previous' => 0, 'delta_pct' => 0],
            'revenue_yoy' => ['current' => 0, 'previous' => 0, 'delta_pct' => 0],
            'inscriptions_yoy' => ['current' => 0, 'previous' => 0, 'delta_pct' => 0],
            'by_tenant' => [],
        ];

        foreach ($tenants as $tenant) {
            try {
                $tenantTrends = $this->computeTenantTrends($tenant);
                $trends['revenue_mom']['current'] += $tenantTrends['revenue_mom']['current'];
                $trends['revenue_mom']['previous'] += $tenantTrends['revenue_mom']['previous'];
                $trends['revenue_yoy']['current'] += $tenantTrends['revenue_yoy']['current'];
                $trends['revenue_yoy']['previous'] += $tenantTrends['revenue_yoy']['previous'];
                $trends['inscriptions_yoy']['current'] += $tenantTrends['inscriptions_yoy']['current'];
                $trends['inscriptions_yoy']['previous'] += $tenantTrends['inscriptions_yoy']['previous'];
                $trends['by_tenant'][$tenant->code] = array_merge(
                    ['tenant_name' => $tenant->name],
                    $tenantTrends
                );
            } catch (\Exception $e) {
                Log::error("Trends failed for {$tenant->code}: {$e->getMessage()}");
            }
        }

        foreach (['revenue_mom', 'revenue_yoy', 'inscriptions_yoy'] as $key) {
            $prev = $trends[$key]['previous'];
            if ($prev > 0) {
                $trends[$key]['delta_pct'] = round((($trends[$key]['current'] - $prev) / $prev) * 100, 1);
            } elseif ($trends[$key]['current'] > 0) {
                $trends[$key]['delta_pct'] = 100; // from 0 to something = 100%
            }
        }

        return $trends;
    }

    private function computeTenantTrends(Tenant $tenant): array
    {
        $conn = $this->connectionManager->createConnection($tenant);

        try {
            $currentYear = DB::connection($conn)->table('esbtp_annee_universitaires')->where('is_current', 1)->first();
            if (!$currentYear) {
                return $this->emptyTrends();
            }

            $previousYear = DB::connection($conn)
                ->table('esbtp_annee_universitaires')
                ->where('id', '<', $currentYear->id)
                ->orderByDesc('id')
                ->first();

            $currentMonthStart = now()->startOfMonth();
            $currentMonthEnd = now()->endOfMonth();
            $previousMonthStart = now()->subMonth()->startOfMonth();
            $previousMonthEnd = now()->subMonth()->endOfMonth();

            $revenueCurrentMonth = (float) DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'validé')
                ->whereNull('deleted_at')
                ->whereBetween('date_paiement', [$currentMonthStart, $currentMonthEnd])
                ->sum('montant');

            $revenuePreviousMonth = (float) DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'validé')
                ->whereNull('deleted_at')
                ->whereBetween('date_paiement', [$previousMonthStart, $previousMonthEnd])
                ->sum('montant');

            $revenueCurrentYear = (float) DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'validé')
                ->whereNull('deleted_at')
                ->sum('montant');

            $revenuePreviousYear = 0;
            $inscriptionsPreviousYear = 0;
            if ($previousYear) {
                $revenuePreviousYear = (float) DB::connection($conn)
                    ->table('esbtp_paiements')
                    ->where('annee_universitaire_id', $previousYear->id)
                    ->where('status', 'validé')
                    ->whereNull('deleted_at')
                    ->sum('montant');

                $inscriptionsPreviousYear = DB::connection($conn)
                    ->table('esbtp_inscriptions')
                    ->where('annee_universitaire_id', $previousYear->id)
                    ->where('status', 'active')
                    ->where('workflow_step', 'etudiant_cree')
                    ->distinct()
                    ->count('etudiant_id');
            }

            $inscriptionsCurrentYear = DB::connection($conn)
                ->table('esbtp_inscriptions')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'active')
                ->where('workflow_step', 'etudiant_cree')
                ->distinct()
                ->count('etudiant_id');

            return [
                'revenue_mom' => [
                    'current' => $revenueCurrentMonth,
                    'previous' => $revenuePreviousMonth,
                ],
                'revenue_yoy' => [
                    'current' => $revenueCurrentYear,
                    'previous' => $revenuePreviousYear,
                ],
                'inscriptions_yoy' => [
                    'current' => $inscriptionsCurrentYear,
                    'previous' => $inscriptionsPreviousYear,
                ],
            ];
        } catch (\Exception $e) {
            Log::error("Tenant trends failed for {$tenant->code}: {$e->getMessage()}");
            return $this->emptyTrends();
        } finally {
            $this->connectionManager->closeConnection($conn);
        }
    }

    private function emptyAging(): array
    {
        return [
            '0-30' => ['count' => 0, 'amount' => 0],
            '31-60' => ['count' => 0, 'amount' => 0],
            '61-90' => ['count' => 0, 'amount' => 0],
            '90+' => ['count' => 0, 'amount' => 0],
        ];
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
