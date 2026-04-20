<?php

namespace App\Contracts\Group;

use App\Models\Group;
use App\Models\Tenant;
use App\Support\Period\PeriodInterface;

interface GroupFinancialsProviderInterface
{
    /**
     * Financial aggregations across all active tenants.
     *
     * When $period is null, the implementation MUST behave identically to pre-PR4d
     * (PeriodFactory::default() applied).
     *
     * Period semantics (per-field):
     *   - Windowed: monthlyRevenue (GROUP BY MONTH filtered by Period),
     *     totalCollected (whereBetween date_paiement), byType (idem).
     *   - YTD-locked: revenue_expected stays annual — deriving it from Period
     *     would require recomputing subscription/configuration pro-rata, out of
     *     scope for PR4d.
     *
     * @return array<string,mixed>
     */
    public function computeGroupFinancials(Group $group, ?PeriodInterface $period = null): array;

    /**
     * Financial breakdown for a single tenant (same per-field semantics).
     *
     * @return array<string,mixed>
     */
    public function computeTenantFinancials(Tenant $tenant, ?PeriodInterface $period = null): array;
}
