<?php

namespace App\Services\Group;

use App\Models\Group;
use App\Support\Period\PeriodInterface;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;

/**
 * Runs a per-tenant computation across all active tenants of a group.
 *
 * - Uses Concurrency::run (process pool) when >2 tenants AND driver != sync.
 * - Falls back to sync iteration on any failure.
 * - Each parallel task resolves the provider class from the container so child processes
 *   get a fresh DI container (scoped bindings reset as documented in Laravel 12 concurrency docs).
 */
class TenantAggregator
{
    /**
     * @param  class-string  $providerClass  Concrete class resolvable from the container.
     *                                       Each child process does app($providerClass) fresh.
     * @param  ?PeriodInterface  $period     Optional period forwarded as second arg to $methodName.
     *                                       Readonly value object — safe to serialize across processes.
     * @return array<string,mixed>  keyed by tenant code
     */
    public function aggregate(
        Group $group,
        string $providerClass,
        string $methodName,
        string $label,
        ?PeriodInterface $period = null,
    ): array {
        $tenants = $group->activeTenants;

        if ($tenants->count() <= 2 || config('concurrency.default') === 'sync') {
            return $this->aggregateSync($tenants, $providerClass, $methodName, $label, $period);
        }

        $tasks = [];
        foreach ($tenants as $tenant) {
            $tasks[$tenant->code] = function () use ($tenant, $providerClass, $methodName, $label, $period) {
                try {
                    return app($providerClass)->{$methodName}($tenant, $period);
                } catch (\Exception $e) {
                    Log::error("[group-refactor] {$label} failed for {$tenant->code}: {$e->getMessage()}");
                    return null;
                }
            };
        }

        try {
            $results = Concurrency::run($tasks);
            return array_filter($results, fn ($r) => $r !== null);
        } catch (\Exception $e) {
            Log::warning("[group-refactor] Concurrency::run failed for {$label}, falling back to sync: {$e->getMessage()}");
            return $this->aggregateSync($tenants, $providerClass, $methodName, $label, $period);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function aggregateSync(
        $tenants,
        string $providerClass,
        string $methodName,
        string $label,
        ?PeriodInterface $period = null,
    ): array {
        $results = [];
        $provider = app($providerClass);
        foreach ($tenants as $tenant) {
            try {
                $results[$tenant->code] = $provider->{$methodName}($tenant, $period);
            } catch (\Exception $e) {
                Log::error("[group-refactor] {$label} failed for {$tenant->code}: {$e->getMessage()}");
            }
        }
        return $results;
    }
}
