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

    protected const CACHE_TTL = 900; // 15 minutes

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
            self::CACHE_TTL,
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
            self::CACHE_TTL,
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
            self::CACHE_TTL,
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
            self::CACHE_TTL,
            fn () => $this->computeGroupEnrollment($group)
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

            // Revenue: expected (sum of montant_scolarite from inscriptions)
            $revenueExpected = (float) DB::connection($conn)
                ->table('esbtp_inscriptions')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'active')
                ->sum('montant_scolarite');

            // Revenue: collected (sum of validated payments)
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

                // Outstanding debt
                $totalExpected = (float) DB::connection($conn)
                    ->table('esbtp_inscriptions')
                    ->where('annee_universitaire_id', $currentYear->id)
                    ->where('status', 'active')
                    ->sum('montant_scolarite');

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
            'collection_rate' => 0,
            'monthly_revenue' => [],
            'by_type' => [],
        ];
    }
}
