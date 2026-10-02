<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Shell\BinairePhp;
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

        // Null en développement local (PRODUCTION_PATH absent) — non bloquant
        $tenantPath = $tenant->cheminInstallationExistant();

        if ($tenantPath === null) {
            return 0;
        }

        $cacheKey  = 'paywall_limits_' . $code;
        $phpBinary = BinairePhp::detecter();

        exec(
            "cd " . escapeshellarg($tenantPath) . " && {$phpBinary} artisan cache:forget " . escapeshellarg($cacheKey) . " 2>&1",
            $output,
            $exitCode
        );

        if ($exitCode === 0) {
            $this->info("Cache invalide pour '{$code}' (cle : {$cacheKey})");
        }

        return 0;
    }

}
