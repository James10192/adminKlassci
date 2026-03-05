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

    public function handle(): int
    {
        $tenantCode = $this->argument('tenant');
        $branchOverride = $this->option('branch');
        $skipBackup = $this->option('skip-backup');
        $skipMigrations = $this->option('skip-migrations');
        $deployAll = $this->option('all');

        // Validate required env variables
        $productionPath = config('app.production_path', env('PRODUCTION_PATH'));
        if (!$productionPath) {
            $this->error('❌ La variable PRODUCTION_PATH n\'est pas définie dans le .env.');
            $this->line('   Exemple: PRODUCTION_PATH=/home/c2569688c/public_html/');
            return 1;
        }

        if ($tenantCode) {
            $tenant = Tenant::where('code', $tenantCode)->first();

            if (!$tenant) {
                $this->error("❌ Tenant '{$tenantCode}' introuvable.");
                return 1;
            }

            return $this->deployTenant($tenant, $branchOverride, $skipBackup, $skipMigrations);
        }

        // Deploy multiple tenants
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

    private function deployTenant(
        Tenant $tenant,
        ?string $branchOverride = null,
        bool $skipBackup = false,
        bool $skipMigrations = false,
        bool $verbose = true
    ): int {
        $startTime = microtime(true);
        $branch = $branchOverride ?? $tenant->git_branch ?? 'presentation';
        $productionPath = rtrim(env('PRODUCTION_PATH', ''), '/');
        $tenantPath = "{$productionPath}/{$tenant->code}";

        if ($verbose) {
            $this->info("🚀 Déploiement de '{$tenant->code}' sur '{$tenantPath}' (branche: {$branch})...");
            $this->newLine();
        }

        // Verify directory exists before proceeding
        if (!is_dir($tenantPath)) {
            $this->error("❌ Répertoire introuvable : {$tenantPath}");
            $this->line("   Vérifiez que PRODUCTION_PATH est correct et que le tenant a été provisionné.");
            return 1;
        }

        // Detect PHP binary (cPanel uses versioned paths)
        $phpBin = $this->detectPhpBinary();
        // Detect Composer binary
        $composerBin = $this->detectComposerBinary($tenantPath);

        if ($verbose) {
            $this->line("   PHP : {$phpBin}");
            $this->line("   Composer : {$composerBin}");
        }

        $deployment = TenantDeployment::create([
            'tenant_id' => $tenant->id,
            'git_branch' => $branch,
            'git_commit_hash' => null,
            'status' => 'in_progress',
            'deployed_by_user_id' => auth()->id(),
            'started_at' => now(),
            'deployment_log' => [],
        ]);

        $steps = [];

        try {
            // Step 1: Backup (optional)
            if (!$skipBackup) {
                if ($verbose) $this->line('📦 Création d\'un backup...');
                $t = microtime(true);
                $this->call('tenant:backup', ['tenant' => $tenant->code, '--type' => 'database_only']);
                $steps[] = ['step' => 'backup', 'status' => 'ok', 'output' => 'Backup BDD créé avec succès.', 'duration_ms' => (int)((microtime(true) - $t) * 1000)];
                $deployment->update(['deployment_log' => $steps]);
            }

            // Step 2: Maintenance mode ON
            if ($verbose) $this->line('🔧 Activation du mode maintenance...');
            $t = microtime(true);
            $out = $this->runProcess($tenantPath, "{$phpBin} artisan down --retry=60 --secret=klassci-deploy", true);
            $steps[] = ['step' => 'maintenance_on', 'status' => 'ok', 'output' => trim($out) ?: 'Mode maintenance activé.', 'duration_ms' => (int)((microtime(true) - $t) * 1000)];
            $deployment->update(['deployment_log' => $steps]);

            // Step 3: Git checkout + pull
            // On s'assure d'abord d'être sur la bonne branche avant de pull,
            // pour éviter un merge cross-branch (ex: git pull origin presentation dans hetec/).
            if ($verbose) $this->line('📥 Git checkout + pull...');
            $t = microtime(true);
            $checkoutOut = $this->runProcess($tenantPath, "git fetch origin 2>&1 && git checkout {$branch} 2>&1", true);
            $out = $this->runProcess($tenantPath, "git pull origin {$branch} 2>&1", true);
            $steps[] = ['step' => 'git_pull', 'status' => 'ok', 'output' => trim($checkoutOut . "\n" . $out), 'duration_ms' => (int)((microtime(true) - $t) * 1000)];
            $deployment->update(['deployment_log' => $steps]);

            // Get commit info
            $commitHash = trim($this->runProcess($tenantPath, 'git rev-parse HEAD', true));
            $commitInfo = trim($this->runProcess($tenantPath, 'git log -1 --format="%H|%an|%ae|%s|%ai"', true));
            $commitParts = explode('|', $commitInfo);
            $steps[] = [
                'step' => 'commit_info',
                'status' => 'ok',
                'output' => $commitInfo,
                'commit' => [
                    'hash'    => $commitParts[0] ?? $commitHash,
                    'author'  => $commitParts[1] ?? 'N/A',
                    'email'   => $commitParts[2] ?? '',
                    'message' => $commitParts[3] ?? 'N/A',
                    'date'    => $commitParts[4] ?? '',
                ],
                'duration_ms' => 0,
            ];
            $deployment->update(['deployment_log' => $steps]);

            // Step 4: Composer install
            if ($verbose) $this->line('📦 Composer install...');
            $t = microtime(true);
            $out = $this->runProcess($tenantPath, "{$composerBin} install --no-dev --optimize-autoloader --no-interaction 2>&1", true);
            $steps[] = ['step' => 'composer_install', 'status' => 'ok', 'output' => trim($out), 'duration_ms' => (int)((microtime(true) - $t) * 1000)];
            $deployment->update(['deployment_log' => $steps]);

            // Step 5: Migrations
            if (!$skipMigrations) {
                if ($verbose) $this->line('🗄️  Exécution des migrations...');
                $t = microtime(true);
                [$migrateStatus, $migrateOut] = $this->runMigrations($tenantPath, $phpBin);
                $steps[] = ['step' => 'migrations', 'status' => $migrateStatus, 'output' => trim($migrateOut), 'duration_ms' => (int)((microtime(true) - $t) * 1000)];
                $deployment->update(['deployment_log' => $steps]);
                // Only abort if it's a real failure (not "table already exists")
                if ($migrateStatus === 'failed') {
                    throw new \Exception("Migration échouée :\n{$migrateOut}");
                }
            }

            // Step 6: Clear all caches
            if ($verbose) $this->line('🧹 Nettoyage des caches...');
            $t = microtime(true);
            foreach (['config:clear', 'cache:clear', 'view:clear', 'route:clear', 'event:clear'] as $cmd) {
                $this->runProcess($tenantPath, "{$phpBin} artisan {$cmd} 2>&1");
            }
            $steps[] = ['step' => 'cache_clear', 'status' => 'ok', 'output' => 'config:clear, cache:clear, view:clear, route:clear, event:clear', 'duration_ms' => (int)((microtime(true) - $t) * 1000)];
            $deployment->update(['deployment_log' => $steps]);

            // Step 7: Rebuild optimized caches
            // NOTE: config:cache et route:cache sont volontairement omis :
            // - config:cache : InstallationHelper vérifie l'état de la BDD dynamiquement
            // - route:cache  : incompatible avec les routes définies via closures (InvalidSignatureException)
            if ($verbose) $this->line('🔄 Reconstruction des caches...');
            $t = microtime(true);
            $steps[] = ['step' => 'cache_rebuild', 'status' => 'ok', 'output' => 'config:cache et route:cache omis intentionnellement (closures + InstallationHelper)', 'duration_ms' => (int)((microtime(true) - $t) * 1000)];
            $deployment->update(['deployment_log' => $steps]);

            // Step 8: Fix permissions
            if ($verbose) $this->line('🔐 Correction des permissions...');
            $t = microtime(true);
            $this->runProcess($tenantPath, 'chmod -R 775 storage bootstrap/cache 2>&1');
            $steps[] = ['step' => 'permissions', 'status' => 'ok', 'output' => 'chmod -R 775 storage bootstrap/cache', 'duration_ms' => (int)((microtime(true) - $t) * 1000)];
            $deployment->update(['deployment_log' => $steps]);

            // Step 9: Maintenance mode OFF
            if ($verbose) $this->line('✅ Désactivation du mode maintenance...');
            $t = microtime(true);
            $out = $this->runProcess($tenantPath, "{$phpBin} artisan up 2>&1", true);
            $steps[] = ['step' => 'maintenance_off', 'status' => 'ok', 'output' => trim($out) ?: 'Site remis en ligne.', 'duration_ms' => (int)((microtime(true) - $t) * 1000)];
            $deployment->update(['deployment_log' => $steps]);

            $duration = (int) (microtime(true) - $startTime);

            $deployment->update([
                'git_commit_hash' => $commitHash,
                'status' => 'success',
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'deployment_log' => $steps,
            ]);

            $tenant->update([
                'git_commit_hash' => $commitHash,
                'last_deployed_at' => now(),
            ]);

            if ($verbose) {
                $this->newLine();
                $this->displayDeploymentInfo($deployment, $tenant);
            }

            return 0;

        } catch (\Exception $e) {
            // Always try to bring the site back up
            try {
                $this->runProcess($tenantPath, "{$phpBin} artisan up 2>&1");
            } catch (\Exception) {
                // Ignore — site may already be up or path wrong
            }

            $duration = (int) (microtime(true) - $startTime);

            $steps[] = ['step' => 'error', 'status' => 'failed', 'output' => $e->getMessage(), 'duration_ms' => 0];

            $deployment->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_seconds' => $duration,
                'deployment_log' => $steps,
            ]);

            if ($verbose) {
                $this->newLine();
                $this->error("❌ Erreur de déploiement : {$e->getMessage()}");
                $this->newLine();
            }

            \Log::error("Erreur déploiement tenant {$tenant->code}", [
                'error' => $e->getMessage(),
                'path' => $tenantPath,
                'branch' => $branch,
            ]);

            return 1;
        }
    }

    /**
     * Run migrations, tolerating "table already exists" errors.
     * Returns ['ok'|'warning'|'failed', output].
     */
    private function runMigrations(string $directory, string $phpBin): array
    {
        $env = [];
        if (!getenv('HOME')) {
            $env['HOME'] = posix_getpwuid(posix_geteuid())['dir'] ?? '/tmp';
        }

        $result = Process::path($directory)->env($env)->run("{$phpBin} artisan migrate --force 2>&1");
        $output = trim($result->output());

        if ($result->successful()) {
            return ['ok', $output];
        }

        // "Table already exists" means migrations ran but some are already applied outside
        // the migrations table — treat as warning, not fatal error.
        if (str_contains($output, 'already exists') || str_contains($output, 'Base table or view already exists')) {
            return ['warning', $output];
        }

        return ['failed', $output];
    }

    /**
     * Run a shell command in the given directory.
     * Throws an exception if the command fails.
     */
    private function runProcess(string $directory, string $command, bool $returnOutput = false): string
    {
        // HOME doit être défini pour Composer (absent quand lancé via web/Artisan::call)
        $env = [];
        if (!getenv('HOME')) {
            $env['HOME'] = posix_getpwuid(posix_geteuid())['dir'] ?? '/tmp';
        }

        $result = Process::path($directory)->env($env)->run($command);

        if (!$result->successful()) {
            throw new \Exception(
                "Commande échouée dans {$directory}:\n"
                . "  CMD : {$command}\n"
                . "  STDOUT : " . trim($result->output()) . "\n"
                . "  STDERR : " . trim($result->errorOutput())
            );
        }

        return $returnOutput ? $result->output() : '';
    }

    /**
     * Detect the correct PHP binary for cPanel shared hosting.
     * cPanel uses ea-php** paths; fallback to system `php`.
     */
    private function detectPhpBinary(): string
    {
        // Common cPanel PHP binary locations (ordered by preference)
        $candidates = [
            '/usr/local/bin/php',          // cPanel default symlink
            '/opt/cpanel/ea-php84/root/usr/bin/php',
            '/opt/cpanel/ea-php83/root/usr/bin/php',
            '/opt/cpanel/ea-php82/root/usr/bin/php',
            '/opt/cpanel/ea-php81/root/usr/bin/php',
            'php',                          // System PATH fallback
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === 'php') {
                return 'php'; // Always valid as PATH fallback
            }
            if (file_exists($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    /**
     * Detect Composer binary: prefer composer.phar in tenant dir, then system.
     */
    private function detectComposerBinary(string $tenantPath): string
    {
        $phpBin = $this->detectPhpBinary();

        $candidates = [
            "{$tenantPath}/composer.phar" => "{$phpBin} {$tenantPath}/composer.phar",
            '/usr/local/bin/composer'     => '/usr/local/bin/composer',
            '/usr/bin/composer'           => '/usr/bin/composer',
        ];

        foreach ($candidates as $file => $command) {
            if (file_exists($file)) {
                return $command;
            }
        }

        return 'composer'; // PATH fallback
    }

    private function displayDeploymentInfo(TenantDeployment $deployment, Tenant $tenant): void
    {
        $this->table(
            ['Propriété', 'Valeur'],
            [
                ['ID Déploiement', $deployment->id],
                ['Tenant', $tenant->name . ' (' . $tenant->code . ')'],
                ['Branche', $deployment->git_branch],
                ['Commit', $deployment->git_commit_hash ? substr($deployment->git_commit_hash, 0, 8) : 'N/A'],
                ['Statut', '✅ ' . $deployment->status],
                ['Durée', $deployment->duration_seconds . ' secondes'],
                ['Démarré', $deployment->started_at->format('Y-m-d H:i:s')],
                ['Terminé', $deployment->completed_at->format('Y-m-d H:i:s')],
            ]
        );

        $this->newLine();
        $this->info("✅ Déploiement de '{$tenant->code}' terminé avec succès !");
        $this->newLine();
    }
}
