<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class TenantClearLimitsCache extends Command
{
    protected $signature = 'tenant:clear-limits-cache
                            {tenant : Code du tenant}';

    protected $description = 'Invalider le cache des limites/abonnement d\'un tenant (clé paywall_limits_{code})';

    public function handle(): int
    {
        $code = $this->argument('tenant');

        $tenant = Tenant::where('code', $code)->first();

        if (! $tenant) {
            $this->error("Tenant '{$code}' introuvable.");
            return 1;
        }

        $productionPath = rtrim(env('PRODUCTION_PATH', ''), '/');

        if (! $productionPath) {
            // En développement local, PRODUCTION_PATH n'est pas défini — non bloquant
            return 0;
        }

        $tenantPath = "{$productionPath}/{$code}";

        if (! is_dir($tenantPath)) {
            return 0;
        }

        $cacheKey  = 'paywall_limits_' . $code;
        $phpBinary = $this->detectPhpBinary();

        exec(
            "cd {$tenantPath} && {$phpBinary} artisan cache:forget {$cacheKey} 2>&1",
            $output,
            $exitCode
        );

        if ($exitCode === 0) {
            $this->info("Cache invalide pour '{$code}' (cle : {$cacheKey})");
        }

        return 0;
    }

    private function detectPhpBinary(): string
    {
        $candidates = [
            '/opt/alt/php83/usr/bin/php',
            '/opt/alt/php82/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/cpanel/ea-php84/root/usr/bin/php',
            '/opt/cpanel/ea-php83/root/usr/bin/php',
            '/opt/cpanel/ea-php82/root/usr/bin/php',
            'php',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === 'php') {
                return 'php';
            }
            if (file_exists($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }
}
