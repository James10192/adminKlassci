<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantDeployment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class TenantDeploy extends Command
{
    protected $signature = 'tenant:deploy
                            {tenant? : Code du tenant (si omis, déploie tous les tenants actifs)}
                            {--branch= : Branche Git à déployer (si différente de celle configurée)}
                            {--skip-backup : Ne pas créer de backup avant le déploiement}
                            {--skip-migrations : Ne pas exécuter les migrations}
                            {--all : Forcer le déploiement de tous les tenants (actifs + suspendus)}';

    protected $description = 'Déployer les mises à jour d\'un tenant (Git pull + Composer + Migrations + Cache)';

    public function handle()
    {
        $tenantCode = $this->argument('tenant');
        $branchOverride = $this->option('branch');
        $skipBackup = $this->option('skip-backup');
        $skipMigrations = $this->option('skip-migrations');
        $deployAll = $this->option('all');

        if ($tenantCode) {
            // Déployer un seul tenant
            $tenant = Tenant::where('code', $tenantCode)->first();

            if (!$tenant) {
                $this->error("❌ Tenant '{$tenantCode}' introuvable.");
                return 1;
            }

            return $this->deployTenant($tenant, $branchOverride, $skipBackup, $skipMigrations);
        } else {
            // Déployer plusieurs tenants
            $query = Tenant::query();

            if (!$deployAll) {
                $query->active();
            }

            $tenants = $query->get();

            if ($tenants->isEmpty()) {
                $this->warn('⚠️  Aucun tenant à déployer.');
                return 0;
            }

            $this->info("🚀 Déploiement de {$tenants->count()} tenant(s)...");
            $this->newLine();

            $bar = $this->output->createProgressBar($tenants->count());
            $bar->start();

            $successCount = 0;
            $failureCount = 0;

            foreach ($tenants as $tenant) {
                $result = $this->deployTenant($tenant, $branchOverride, $skipBackup, $skipMigrations, false);
                if ($result === 0) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
            $this->info("✅ Déploiements terminés : {$successCount} réussis, {$failureCount} échoués");

            return $failureCount > 0 ? 1 : 0;
        }
    }

    private function deployTenant(
        Tenant $tenant,
        ?string $branchOverride = null,
        bool $skipBackup = false,
        bool $skipMigrations = false,
        bool $verbose = true
    ): int {
        $startTime = microtime(true);
        $branch = $branchOverride ?? $tenant->git_branch;

        if ($verbose) {
            $this->info("🚀 Déploiement de '{$tenant->code}' (branche: {$branch})...");
            $this->newLine();
        }

        // Créer l'enregistrement de déploiement
        $deployment = TenantDeployment::create([
            'tenant_id' => $tenant->id,
            'git_branch' => $branch,
            'git_commit_hash' => null, // Sera mis à jour après git pull
            'status' => 'in_progress',
            'deployed_by_user_id' => null, // CLI execution
            'started_at' => now(),
        ]);

        try {
            $tenantPath = env('PRODUCTION_PATH') . $tenant->code;

            if (!file_exists($tenantPath) || !is_dir($tenantPath)) {
                throw new \Exception("Répertoire tenant introuvable: {$tenantPath}");
            }

            // Étape 1: Backup (si non désactivé)
            if (!$skipBackup) {
                if ($verbose) $this->line('📦 Création d\'un backup...');
                $this->call('tenant:backup', [
                    'tenant' => $tenant->code,
                    '--type' => 'full',
                ]);
            }

            // Étape 2: Mise en maintenance
            if ($verbose) $this->line('🔧 Activation du mode maintenance...');
            $this->executeRemoteCommand($tenantPath, 'php artisan down --retry=60');

            // Étape 3: Git pull
            if ($verbose) $this->line('📥 Git pull...');
            $this->executeRemoteCommand($tenantPath, "git fetch origin {$branch}");
            $this->executeRemoteCommand($tenantPath, "git reset --hard origin/{$branch}");

            // Récupérer le commit hash actuel
            $commitHash = trim($this->executeRemoteCommand($tenantPath, 'git rev-parse HEAD', true));

            // Étape 4: Composer install
            if ($verbose) $this->line('📦 Composer install...');
            $this->executeRemoteCommand($tenantPath, 'composer install --no-dev --optimize-autoloader --no-interaction');

            // Étape 5: Migrations (si non désactivées)
            if (!$skipMigrations) {
                if ($verbose) $this->line('🗄️  Exécution des migrations...');
                $this->executeRemoteCommand($tenantPath, 'php artisan migrate --force');
            }

            // Étape 6: Clear caches
            if ($verbose) $this->line('🧹 Nettoyage des caches...');
            $this->executeRemoteCommand($tenantPath, 'php artisan config:clear');
            $this->executeRemoteCommand($tenantPath, 'php artisan cache:clear');
            $this->executeRemoteCommand($tenantPath, 'php artisan view:clear');
            $this->executeRemoteCommand($tenantPath, 'php artisan route:clear');

            // Étape 7: Rebuild caches
            if ($verbose) $this->line('🔄 Reconstruction des caches...');
            $this->executeRemoteCommand($tenantPath, 'php artisan config:cache');
            $this->executeRemoteCommand($tenantPath, 'php artisan route:cache');

            // Étape 8: Permissions
            if ($verbose) $this->line('🔐 Correction des permissions...');
            $this->executeRemoteCommand($tenantPath, 'chmod -R 775 storage');
            $this->executeRemoteCommand($tenantPath, 'chmod -R 775 bootstrap/cache');

            // Étape 9: Sortie du mode maintenance
            if ($verbose) $this->line('✅ Désactivation du mode maintenance...');
            $this->executeRemoteCommand($tenantPath, 'php artisan up');

            // Calculer la durée
            $duration = (int) ((microtime(true) - $startTime));

            // Mettre à jour le déploiement avec succès
            $deployment->update([
                'git_commit_hash' => $commitHash,
                'status' => 'success',
                'completed_at' => now(),
                'duration_seconds' => $duration,
            ]);

            // Mettre à jour le tenant
            $tenant->update([
                'git_commit_hash' => $commitHash,
                'last_deployed_at' => now(),
            ]);

            if ($verbose) {
                $this->newLine();
                $this->displayDeploymentInfo($deployment);
            }

            return 0;

        } catch (\Exception $e) {
            // Tenter de sortir du mode maintenance en cas d'erreur
            try {
                $this->executeRemoteCommand($tenantPath, 'php artisan up');
            } catch (\Exception $upException) {
                // Ignorer les erreurs lors de la sortie du mode maintenance
            }

            $duration = (int) ((microtime(true) - $startTime));

            $deployment->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_seconds' => $duration,
            ]);

            if ($verbose) {
                $this->newLine();
                $this->error("❌ Erreur : {$e->getMessage()}");
                $this->newLine();
            }

            \Log::error("Erreur déploiement tenant {$tenant->code}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return 1;
        }
    }

    private function executeRemoteCommand(string $path, string $command, bool $returnOutput = false): string
    {
        $fullCommand = "cd {$path} && {$command}";

        // En local (développement)
        if (app()->environment('local')) {
            $result = Process::run($fullCommand);

            if (!$result->successful()) {
                throw new \Exception("Commande échouée: {$command}\n{$result->errorOutput()}");
            }

            return $returnOutput ? $result->output() : '';
        }

        // En production (via SSH)
        $host = env('PRODUCTION_HOST');
        $user = env('PRODUCTION_USER');
        $sshCommand = "ssh {$user}@{$host} '{$fullCommand}'";

        $result = Process::run($sshCommand);

        if (!$result->successful()) {
            throw new \Exception("Commande SSH échouée: {$command}\n{$result->errorOutput()}");
        }

        return $returnOutput ? $result->output() : '';
    }

    private function displayDeploymentInfo(TenantDeployment $deployment): void
    {
        $this->table(
            ['Propriété', 'Valeur'],
            [
                ['ID Déploiement', $deployment->id],
                ['Branche', $deployment->git_branch],
                ['Commit', substr($deployment->git_commit_hash, 0, 8)],
                ['Statut', $deployment->status],
                ['Durée', $deployment->duration_seconds . ' secondes'],
                ['Démarré', $deployment->started_at->format('Y-m-d H:i:s')],
                ['Terminé', $deployment->completed_at->format('Y-m-d H:i:s')],
            ]
        );

        $this->newLine();
        $this->info('✅ Déploiement terminé avec succès !');
        $this->newLine();
    }
}
