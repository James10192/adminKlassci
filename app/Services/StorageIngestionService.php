<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Measures a tenant's actual disk usage and persists it to
 * `tenants.current_storage_mb`. Unblocks the storage alert path — PR-C's
 * `collectQuotaAlerts` already fires when the ratio crosses thresholds,
 * but it's been a no-op because the column was never populated (see
 * `TenantConnectionManager.php:154-156` TODO).
 *
 * All KLASSCI tenants currently share a single cPanel account on the same
 * host (`c2569688c@web44.lws-hosting.com`), so we shell out via SSH + `du`
 * from the master app. When the topology grows, split the SSH executor
 * into a per-tenant strategy.
 *
 * Dev environments have no SSH credentials — the service silent-fails with
 * a logged warning and returns null so `tenant:update-storage` can run
 * locally without crashing.
 */
class StorageIngestionService
{
    /**
     * Returns disk usage in MB, or null if ingestion is disabled / fails.
     * Caller decides what to do with null (typically: skip update, don't
     * overwrite the previous value with 0).
     */
    public function measureTenantStorageMb(Tenant $tenant): ?int
    {
        if (! config('group_portal.storage_ingestion_enabled', false)) {
            return null;
        }

        $host = (string) config('group_portal.storage_ssh_host', '');
        $user = (string) config('group_portal.storage_ssh_user', '');
        $basePath = rtrim((string) config('group_portal.storage_tenant_base_path', ''), '/');

        if ($host === '' || $user === '' || $basePath === '' || $tenant->subdomain === null) {
            Log::warning('[storage-ingestion] missing configuration — skipping', [
                'tenant' => $tenant->code,
                'host_set' => $host !== '',
                'user_set' => $user !== '',
                'base_path_set' => $basePath !== '',
            ]);
            return null;
        }

        $remotePath = $basePath . '/' . $tenant->subdomain;
        $sshCommand = $this->buildSshCommand($user, $host, $remotePath);
        $timeout = (int) config('group_portal.storage_ssh_timeout_sec', 30);

        try {
            $result = Process::timeout($timeout)->run($sshCommand);

            if (! $result->successful()) {
                Log::warning('[storage-ingestion] SSH du failed', [
                    'tenant' => $tenant->code,
                    'exit_code' => $result->exitCode(),
                    'stderr' => substr($result->errorOutput(), 0, 500),
                ]);
                return null;
            }

            return $this->parseDuOutput($result->output());
        } catch (\Throwable $e) {
            Log::warning('[storage-ingestion] command threw', [
                'tenant' => $tenant->code,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * `du -sm` output shape: `<size>\t<path>\n`. We take the first column
     * and coerce to int. Empty / malformed output returns null so the
     * caller can distinguish "measurement failed" from "genuinely 0 MB".
     */
    public function parseDuOutput(string $rawOutput): ?int
    {
        $trimmed = trim($rawOutput);
        if ($trimmed === '') {
            return null;
        }

        $firstField = preg_split('/\s+/', $trimmed, 2)[0] ?? '';
        if (! is_numeric($firstField)) {
            return null;
        }

        return max(0, (int) $firstField);
    }

    /**
     * Escapes path for safe inclusion in the remote shell command. Tenant
     * subdomains come from the `tenants` table (controlled by ops), but
     * defense-in-depth: if a comma or space ever sneaks in, escapeshellarg
     * prevents it becoming a remote command injection vector.
     */
    public function buildSshCommand(string $user, string $host, string $remotePath): string
    {
        $sshOptions = '-o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new';

        return sprintf(
            'ssh %s %s@%s "du -sm %s"',
            $sshOptions,
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg($remotePath),
        );
    }
}
