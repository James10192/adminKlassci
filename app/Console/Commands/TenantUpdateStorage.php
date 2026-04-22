<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\StorageIngestionService;
use Illuminate\Console\Command;

class TenantUpdateStorage extends Command
{
    protected $signature = 'tenant:update-storage {--tenant= : Limit to a single tenant code for testing}';

    protected $description = 'Measures actual disk usage per tenant (via SSH `du`) and updates tenants.current_storage_mb. Unblocks the storage quota alert.';

    public function handle(StorageIngestionService $ingestion): int
    {
        if (! config('group_portal.storage_ingestion_enabled', false)) {
            $this->info('group_portal.storage_ingestion_enabled is false — nothing to do.');
            return self::SUCCESS;
        }

        $query = Tenant::query()->where('status', 'active');
        if ($code = $this->option('tenant')) {
            $query->where('code', $code);
        }

        $tenants = $query->get();
        $updated = 0;
        $skipped = 0;

        foreach ($tenants as $tenant) {
            $mb = $ingestion->measureTenantStorageMb($tenant);

            if ($mb === null) {
                $skipped++;
                continue;
            }

            // Only update when we have a real measurement — never overwrite
            // a previous value with 0 because the ingestion failed.
            $tenant->update(['current_storage_mb' => $mb]);
            $updated++;
            $this->line("[{$tenant->code}] updated: {$mb} MB");
        }

        $this->info("Storage ingestion complete — {$updated} updated, {$skipped} skipped, {$tenants->count()} total.");

        // Total failure (every tenant skipped, nothing updated) surfaces as a
        // non-zero exit so cron job monitoring catches silent ingestion outages.
        // Some skips are fine; ALL skips with zero successes = ops problem.
        if ($tenants->count() > 0 && $updated === 0) {
            \Log::error('[storage-ingestion] every tenant skipped — ingestion pipeline likely broken', [
                'total' => $tenants->count(),
                'skipped' => $skipped,
            ]);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
