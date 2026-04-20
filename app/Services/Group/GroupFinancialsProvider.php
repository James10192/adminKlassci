<?php

namespace App\Services\Group;

use App\Contracts\Group\GroupFinancialsProviderInterface;
use App\Models\Group;
use App\Models\Tenant;
use App\Services\TenantConnectionManager;
use App\Support\Period\PeriodFactory;
use App\Support\Period\PeriodInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GroupFinancialsProvider implements GroupFinancialsProviderInterface
{
    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantAggregator $aggregator,
        private readonly TenantBillingContext $billingContext,
    ) {
    }

    public function computeGroupFinancials(Group $group, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();
        $perTenant = $this->aggregator->aggregate($group, self::class, 'computeTenantFinancials', 'Financials', $period);

        $financials = [];
        foreach ($group->activeTenants as $tenant) {
            $financials[$tenant->code] = $perTenant[$tenant->code] ?? $this->emptyFinancials($tenant);
        }

        return $financials;
    }

    public function computeTenantFinancials(Tenant $tenant, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();
        $conn = $this->connectionManager->createConnection($tenant);

        try {
            $currentYear = DB::connection($conn)->table('esbtp_annee_universitaires')->where('is_current', 1)->first();
            if (! $currentYear) {
                return $this->emptyFinancials($tenant);
            }

            // Windowed: monthlyRevenue filtered by Period, still grouped by month.
            $monthlyRevenue = DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'validé')
                ->whereBetween('date_paiement', [$period->startDate(), $period->endDate()])
                ->selectRaw('MONTH(date_paiement) as month, SUM(montant) as total')
                ->groupByRaw('MONTH(date_paiement)')
                ->pluck('total', 'month')
                ->toArray();

            // YTD-locked: revenue_expected stays annual (see interface docblock).
            $totalExpected = $this->billingContext->computeRevenueExpected($conn, $tenant->id, $currentYear->id);

            // Windowed: totalCollected filtered by Period.
            $totalCollected = (float) DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'validé')
                ->whereBetween('date_paiement', [$period->startDate(), $period->endDate()])
                ->sum('montant');

            // Windowed: byType breakdown over the same Period.
            $byType = DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'validé')
                ->whereBetween('date_paiement', [$period->startDate(), $period->endDate()])
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
        } catch (\Exception $e) {
            Log::error("[group-refactor] computeTenantFinancials failed for {$tenant->code}: {$e->getMessage()}");
            return $this->emptyFinancials($tenant);
        } finally {
            $this->connectionManager->closeConnection($conn);
        }
    }

    public function emptyFinancials(Tenant $tenant): array
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
