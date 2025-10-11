<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TenantUpdateStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:update-stats
                            {tenant? : Code du tenant (si omis, met à jour tous les tenants actifs)}
                            {--all : Forcer la mise à jour de tous les tenants (actifs + suspendus)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mettre à jour les statistiques d\'usage des tenants (users, staff, students, storage)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantCode = $this->argument('tenant');
        $updateAll = $this->option('all');

        if ($tenantCode) {
            // Mettre à jour un seul tenant
            $tenant = Tenant::where('code', $tenantCode)->first();

            if (!$tenant) {
                $this->error("❌ Tenant '{$tenantCode}' introuvable.");
                return 1;
            }

            $this->updateTenantStats($tenant);
        } else {
            // Mettre à jour plusieurs tenants
            $query = Tenant::query();

            if (!$updateAll) {
                $query->active();
            }

            $tenants = $query->get();

            if ($tenants->isEmpty()) {
                $this->warn('⚠️  Aucun tenant à mettre à jour.');
                return 0;
            }

            $this->info("📊 Mise à jour des statistiques pour {$tenants->count()} tenant(s)...");
            $this->newLine();

            $bar = $this->output->createProgressBar($tenants->count());
            $bar->start();

            foreach ($tenants as $tenant) {
                $this->updateTenantStats($tenant, false);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
            $this->info('✅ Mise à jour terminée !');
        }

        return 0;
    }

    /**
     * Mettre à jour les statistiques d'un tenant
     */
    private function updateTenantStats(Tenant $tenant, bool $verbose = true)
    {
        if ($verbose) {
            $this->info("📊 Mise à jour des statistiques pour '{$tenant->code}'...");
        }

        try {
            // Connexion à la base de données du tenant
            $credentials = $tenant->database_credentials;

            config([
                'database.connections.tenant_temp' => [
                    'driver' => 'mysql',
                    'host' => $credentials['host'] ?? 'localhost',
                    'port' => $credentials['port'] ?? 3306,
                    'database' => $tenant->database_name,
                    'username' => $credentials['username'],
                    'password' => $credentials['password'],
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                ]
            ]);

            // Compter les utilisateurs (table users)
            $currentUsers = DB::connection('tenant_temp')->table('users')->count();

            // Compter le personnel (users avec rôles enseignant/coordinateur/secrétaire via model_has_roles)
            $currentStaff = DB::connection('tenant_temp')
                ->table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->whereIn('roles.name', ['enseignant', 'coordinateur', 'secretaire', 'serviceTechnique'])
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->distinct('model_has_roles.model_id')
                ->count('model_has_roles.model_id');

            // Compter les étudiants (table esbtp_etudiants)
            $currentStudents = DB::connection('tenant_temp')->table('esbtp_etudiants')->count();

            // Calculer l'espace de stockage (dossier storage/ du tenant)
            $storagePath = env('PRODUCTION_PATH') . $tenant->code . '/storage';
            $currentStorageMb = 0;

            if (file_exists($storagePath) && is_dir($storagePath)) {
                $currentStorageMb = $this->getDirectorySize($storagePath) / 1024 / 1024; // Convertir en MB
            }

            // Mettre à jour le tenant
            $tenant->update([
                'current_users' => $currentUsers,
                'current_staff' => $currentStaff,
                'current_students' => $currentStudents,
                'current_storage_mb' => (int) $currentStorageMb,
            ]);

            if ($verbose) {
                $this->newLine();
                $this->table(
                    ['Statistique', 'Valeur actuelle', 'Limite'],
                    [
                        ['Utilisateurs', $currentUsers, $tenant->max_users],
                        ['Personnel', $currentStaff, $tenant->max_staff],
                        ['Étudiants', $currentStudents, $tenant->max_students],
                        ['Stockage (MB)', number_format($currentStorageMb, 2), $tenant->max_storage_mb],
                    ]
                );
                $this->newLine();
                $this->info('✅ Statistiques mises à jour avec succès !');
            }

        } catch (\Exception $e) {
            if ($verbose) {
                $this->error("❌ Erreur : {$e->getMessage()}");
            }
            \Log::error("Erreur mise à jour stats tenant {$tenant->code}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Calculer la taille d'un répertoire (récursif)
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;

        foreach (glob(rtrim($path, '/') . '/*', GLOB_NOSORT) as $file) {
            $size += is_file($file) ? filesize($file) : $this->getDirectorySize($file);
        }

        return $size;
    }
}
