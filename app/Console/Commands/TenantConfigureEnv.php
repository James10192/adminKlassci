<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class TenantConfigureEnv extends Command
{
    protected $signature = 'tenant:configure-env
                            {tenant : Code du tenant}
                            {--dry-run : Afficher les changements sans les appliquer}';

    protected $description = 'Mettre à jour le .env du tenant avec MASTER_API_URL, MASTER_API_TOKEN et TENANT_CODE';

    public function handle(): int
    {
        $code = $this->argument('tenant');
        $dryRun = $this->option('dry-run');

        $tenant = Tenant::where('code', $code)->first();

        if (!$tenant) {
            $this->error("❌ Tenant '{$code}' introuvable.");
            return 1;
        }

        if (!$tenant->api_token) {
            $this->error("❌ Le tenant '{$code}' n'a pas de token API. Générez-en un d'abord.");
            $this->line("   php artisan tenant:generate-token {$code}");
            return 1;
        }

        $productionPath = rtrim(env('PRODUCTION_PATH', ''), '/');
        if (!$productionPath) {
            $this->error('❌ PRODUCTION_PATH non défini dans le .env admin.');
            return 1;
        }

        $envPath = "{$productionPath}/{$code}/.env";

        if (!file_exists($envPath)) {
            $this->error("❌ Fichier .env introuvable : {$envPath}");
            return 1;
        }

        $masterApiUrl = rtrim(config('app.url'), '/') . '/api';
        $token = $tenant->api_token;

        $updates = [
            'MASTER_API_URL'   => $masterApiUrl,
            'MASTER_API_TOKEN' => $token,
            'TENANT_CODE'      => $code,
        ];

        if ($dryRun) {
            $this->info("🔍 [DRY RUN] Changements qui seraient appliqués dans {$envPath} :");
            foreach ($updates as $key => $value) {
                $this->line("   {$key}={$value}");
            }
            return 0;
        }

        $content = file_get_contents($envPath);
        $changed = [];

        foreach ($updates as $key => $value) {
            // Escape special chars for regex (token peut contenir des chiffres/lettres seulement, mais sécurité)
            $escapedKey = preg_quote($key, '/');

            if (preg_match("/^{$escapedKey}=.*/m", $content)) {
                // La clé existe — on la remplace
                $content = preg_replace("/^{$escapedKey}=.*/m", "{$key}={$value}", $content);
                $changed[] = "  ✏️  {$key}={$value} (mis à jour)";
            } else {
                // La clé n'existe pas — on l'ajoute à la fin
                $content .= "\n{$key}={$value}";
                $changed[] = "  ➕ {$key}={$value} (ajouté)";
            }
        }

        file_put_contents($envPath, $content);

        // Vider le cache config du tenant
        $tenantPath = "{$productionPath}/{$code}";
        $phpBinary = PHP_BINARY;
        exec("cd {$tenantPath} && {$phpBinary} artisan config:clear 2>&1", $output, $exitCode);

        $this->info("✅ .env du tenant '{$code}' mis à jour :");
        foreach ($changed as $line) {
            $this->line($line);
        }

        if ($exitCode === 0) {
            $this->line("  🔄 Cache config vidé.");
        } else {
            $this->warn("  ⚠️  Impossible de vider le cache config (non bloquant).");
        }

        return 0;
    }
}
