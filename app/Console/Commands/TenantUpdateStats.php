<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantConnectionManager;
use Illuminate\Console\Command;

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
    protected $description = 'Mettre à jour les statistiques d\'usage des tenants (staff, students, inscriptions, storage)';

    protected TenantConnectionManager $connectionManager;

    public function __construct(TenantConnectionManager $connectionManager)
    {
        parent::__construct();
        $this->connectionManager = $connectionManager;
    }

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

            $this->updateTenantStats($tenant, true);
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

            $success = 0;
            $failed = 0;

            foreach ($tenants as $tenant) {
                if ($this->updateTenantStats($tenant, false)) {
                    $success++;
                } else {
                    $failed++;
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
            $this->info("✅ Mise à jour terminée : {$success} réussis, {$failed} échoués");
        }

        return 0;
    }

    /**
     * Mettre à jour les statistiques d'un tenant
     */
    private function updateTenantStats(Tenant $tenant, bool $verbose = true): bool
    {
        if ($verbose) {
            $this->info("📊 Mise à jour des statistiques pour '{$tenant->name}' ({$tenant->code})...");
        }

        try {
            // Utiliser le service TenantConnectionManager
            $stats = $this->connectionManager->getTenantStats($tenant);

            // Mettre à jour le tenant
            $tenant->update($stats);

            if ($verbose) {
                $this->newLine();
                $this->table(
                    ['Statistique', 'Valeur actuelle', 'Limite', 'Status'],
                    [
                        [
                            'Staff (personnel)',
                            $stats['current_staff'],
                            $tenant->max_staff,
                            $stats['current_staff'] > $tenant->max_staff ? '⚠️ Dépassé' : '✓ OK'
                        ],
                        [
                            'Students (avec compte)',
                            $stats['current_students'],
                            $tenant->max_students,
                            $stats['current_students'] > $tenant->max_students ? '⚠️ Dépassé' : '✓ OK'
                        ],
                        [
                            'Inscriptions (année courante)',
                            $stats['current_inscriptions_per_year'],
                            $tenant->max_inscriptions_per_year,
                            $stats['current_inscriptions_per_year'] > $tenant->max_inscriptions_per_year ? '⚠️ Dépassé' : '✓ OK'
                        ],
                        [
                            'Stockage (MB)',
                            number_format($stats['current_storage_mb'], 2),
                            $tenant->max_storage_mb,
                            $stats['current_storage_mb'] > $tenant->max_storage_mb ? '⚠️ Dépassé' : '✓ OK'
                        ],
                    ]
                );
                $this->newLine();
                $this->info('✅ Statistiques mises à jour avec succès !');
            }

            return true;

        } catch (\Exception $e) {
            if ($verbose) {
                $this->error("❌ Erreur : {$e->getMessage()}");
            }
            \Log::error("Erreur mise à jour stats tenant {$tenant->code}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return false;
        }
    }
}
