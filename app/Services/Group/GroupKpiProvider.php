<?php

namespace App\Services\Group;

use App\Contracts\Group\GroupKpiProviderInterface;
use App\Models\Group;
use App\Models\Tenant;
use App\Services\TenantConnectionManager;
use App\Support\Period\PeriodFactory;
use App\Support\Period\PeriodInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GroupKpiProvider implements GroupKpiProviderInterface
{
    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantAggregator $aggregator,
        private readonly TenantBillingContext $billingContext,
    ) {
    }

    public function computeGroupKpis(Group $group, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();

        $totals = [
            'total_students' => 0,
            'total_inscriptions' => 0,
            'total_revenue_expected' => 0,
            'total_revenue_collected' => 0,
            'total_staff' => 0,
            'establishments' => [],
        ];

        $perTenant = $this->aggregator->aggregate($group, self::class, 'computeTenantKpis', 'TenantKpis', $period);

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

    public function computeTenantKpis(Tenant $tenant, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();
        $conn = $this->connectionManager->createConnection($tenant);

        try {
            $currentYear = DB::connection($conn)
                ->table('esbtp_annee_universitaires')
                ->where('is_current', 1)
                ->first();

            if (!$currentYear) {
                return $this->emptyKpis($tenant);
            }

            // Snapshot metrics (students, inscriptions, staff) — Period deliberately ignored.
            // These reflect the current academic year regardless of the date window.
            $inscriptions = DB::connection($conn)
                ->table('esbtp_inscriptions')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'active')
                ->where('workflow_step', 'etudiant_cree')
                ->count();

            $students = DB::connection($conn)
                ->table('esbtp_inscriptions')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'active')
                ->where('workflow_step', 'etudiant_cree')
                ->distinct()
                ->count('etudiant_id');

            $revenueExpected = $this->billingContext->computeRevenueExpected($conn, $tenant->id, $currentYear->id);

            // Windowed metric: revenue_collected filtered by Period [start, end].
            // When Period === default (CurrentYear), the window spans Jan 1 → Dec 31
            // which is effectively equivalent to the pre-PR4d annee_universitaire_id filter.
            $revenueCollected = (float) DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'validé')
                ->whereBetween('date_paiement', [$period->startDate(), $period->endDate()])
                ->sum('montant');

            $staff = DB::connection($conn)
                ->table('users')
                ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->whereIn('roles.name', ['enseignant', 'coordinateur', 'secretaire', 'comptable'])
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->distinct()
                ->count('users.id');

            // Attendance windowed by Period when explicit, else pre-PR4d 30-day behaviour.
            $attendanceRate = $this->computeAttendanceRate($conn, $period);

            return [
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
        } catch (\Exception $e) {
            Log::error("[group-refactor] computeTenantKpis failed for {$tenant->code}: {$e->getMessage()}");
            return $this->emptyKpis($tenant);
        } finally {
            $this->connectionManager->closeConnection($conn);
        }
    }

    private function computeAttendanceRate(string $conn, PeriodInterface $period): float
    {
        try {
            $stats = DB::connection($conn)
                ->table('esbtp_attendances')
                ->whereBetween('date', [$period->startDate(), $period->endDate()])
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

    public function emptyKpis(Tenant $tenant): array
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
}
