<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Services\TenantAggregationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;

class BenchmarkGroupConcurrency extends Command
{
    protected $signature = 'group:benchmark-concurrency {--group= : Group code}';

    protected $description = 'Compare sync vs parallel execution of TenantAggregationService aggregate methods';

    public function handle(): int
    {
        $group = $this->option('group')
            ? Group::where('code', $this->option('group'))->firstOrFail()
            : Group::first();

        $this->info("Group: {$group->name} ({$group->activeTenants->count()} active tenants)");
        $this->newLine();

        // Smoke test Concurrency
        $this->info('Smoke test Concurrency::run:');
        try {
            $t0 = microtime(true);
            $out = Concurrency::run([
                'a' => fn () => 1,
                'b' => fn () => 2,
            ]);
            $this->line(sprintf('  OK — %dms — result: %s', (microtime(true) - $t0) * 1000, json_encode($out)));
        } catch (\Exception $e) {
            $this->error('  FAILED: ' . $e->getMessage());
            return self::FAILURE;
        }
        $this->newLine();

        $svc = app(TenantAggregationService::class);

        // Sync path
        $svc->refreshGroupCache($group);
        $tSync = $this->measure(fn () => $this->callAll($svc, $group), 'Sync');

        // Force parallel by using driver config override — requires >2 tenants naturally or
        // we force threshold bypass via direct call to parallel aggregate.
        $svc->refreshGroupCache($group);
        $tParallel = $this->measureParallel($svc, $group);

        $this->newLine();
        $this->info(sprintf('Summary: sync %dms, parallel %dms (delta: %+dms)',
            $tSync,
            $tParallel,
            $tParallel - $tSync
        ));

        if ($group->activeTenants->count() <= 2) {
            $this->warn('Note: with ≤2 tenants, sync path is expected to be faster (process pool overhead).');
            $this->warn('Real gain from parallelization shows with 5+ tenants.');
        }

        return self::SUCCESS;
    }

    private function measure(callable $fn, string $label): int
    {
        $t0 = microtime(true);
        $fn();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $this->line(sprintf('  %s: %dms', $label, $ms));
        return $ms;
    }

    private function callAll(TenantAggregationService $svc, Group $group): void
    {
        $svc->getGroupKpis($group);
        $svc->getGroupFinancials($group);
        $svc->getGroupEnrollment($group);
        $svc->getGroupOutstandingAging($group);
        $svc->getGroupHealthMetrics($group);
        $svc->getGroupTrends($group);
    }

    private function measureParallel(TenantAggregationService $svc, Group $group): int
    {
        $tenants = $group->activeTenants;

        $t0 = microtime(true);
        $tasks = [];
        foreach ($tenants as $tenant) {
            $tenantId = $tenant->id;
            $tasks[$tenant->code] = function () use ($tenantId) {
                $t = \App\Models\Tenant::find($tenantId);
                $s = app(TenantAggregationService::class);
                return [
                    'kpis' => $s->getTenantKpis($t),
                ];
            };
        }

        try {
            $results = Concurrency::run($tasks);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $this->line(sprintf('  Parallel (Concurrency::run %d tasks): %dms', count($tasks), $ms));
            $this->line(sprintf('  Results returned: %d', count($results)));
            return $ms;
        } catch (\Exception $e) {
            $this->error('  Parallel FAILED: ' . $e->getMessage());
            return -1;
        }
    }
}
