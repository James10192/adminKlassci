<?php

namespace App\Providers;

use App\Contracts\Group\GroupFinancialsProviderInterface;
use App\Contracts\Group\GroupKpiProviderInterface;
use App\Services\Group\BounceTracker;
use App\Services\Group\GroupFinancialsProvider;
use App\Services\Group\GroupKpiProvider;
use App\Services\Group\TenantBillingContext;
use Illuminate\Support\ServiceProvider;

class GroupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped — memoization resets between requests (critical for Octane / long-running processes).
        $this->app->scoped(TenantBillingContext::class);

        // Interface-to-concrete bindings for dependency inversion.
        $this->app->bind(GroupKpiProviderInterface::class, GroupKpiProvider::class);
        $this->app->bind(GroupFinancialsProviderInterface::class, GroupFinancialsProvider::class);

        // BounceTracker threshold comes from config — autowiring can't resolve scalars.
        $this->app->singleton(BounceTracker::class, fn () => new BounceTracker(
            threshold: (int) config('group_portal.bounce_threshold', 3),
        ));
    }

    public function boot(): void
    {
        //
    }
}
