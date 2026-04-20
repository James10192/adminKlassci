<?php

namespace App\Contracts\Group;

use App\Models\Group;
use App\Models\Tenant;

interface GroupKpiProviderInterface
{
    /**
     * Aggregated KPIs across all active tenants of a group.
     *
     * @return array<string,mixed>
     */
    public function computeGroupKpis(Group $group): array;

    /**
     * KPIs for a single tenant (keyed by tenant code when used as aggregate source).
     *
     * @return array<string,mixed>
     */
    public function computeTenantKpis(Tenant $tenant): array;
}
