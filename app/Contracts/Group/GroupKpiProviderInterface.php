<?php

namespace App\Contracts\Group;

use App\Models\Group;
use App\Models\Tenant;
use App\Support\Period\PeriodInterface;

interface GroupKpiProviderInterface
{
    /**
     * Aggregated KPIs across all active tenants of a group.
     *
     * When $period is null, the implementation MUST behave identically to pre-PR4d
     * (PeriodFactory::default() applied). This keeps LSP intact for callers that
     * don't pass a Period.
     *
     * Period semantics (per-metric):
     *   - Snapshot metrics (students, staff, inscriptions_count) : Period ignored —
     *     always reflect the current academic year / now-state.
     *   - Windowed metrics (revenue_collected) : filtered by [Period::startDate,
     *     Period::endDate].
     *   - Attendance rate : computed over [Period::startDate, Period::endDate] when
     *     fourni, else the last 30 days (pre-PR4d behaviour).
     *
     * @return array<string,mixed>
     */
    public function computeGroupKpis(Group $group, ?PeriodInterface $period = null): array;

    /**
     * KPIs for a single tenant (same per-metric semantics as computeGroupKpis).
     *
     * @return array<string,mixed>
     */
    public function computeTenantKpis(Tenant $tenant, ?PeriodInterface $period = null): array;
}
