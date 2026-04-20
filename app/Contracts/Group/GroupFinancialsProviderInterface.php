<?php

namespace App\Contracts\Group;

use App\Models\Group;
use App\Models\Tenant;

interface GroupFinancialsProviderInterface
{
    /**
     * Financial aggregations across all active tenants (revenues, outstanding, collection rate).
     *
     * @return array<string,mixed>
     */
    public function computeGroupFinancials(Group $group): array;

    /**
     * Financial breakdown for a single tenant (monthly revenue, by_type, etc).
     *
     * @return array<string,mixed>
     */
    public function computeTenantFinancials(Tenant $tenant): array;
}
