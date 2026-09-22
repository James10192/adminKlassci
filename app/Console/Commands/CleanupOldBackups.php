<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Domain\Exploitation\Sauvegarde\SceauSauvegarde;
use App\Models\TenantBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupOldBackups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:cleanup-backups
                            {--days=30 : Nombre de jours de rétention des backups}
                            {--tenant= : Code du tenant (optionnel, si non spécifié = tous les tenants)}
                            {--dry-run : Mode simulation sans suppression réelle}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nettoie les backups expirés ou trop anciens';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $retentionDays = (int) $this->option('days');
        $tenantCode = $this->option('tenant');
        $dryRun = $this->option('dry-run');

        $this->info('🧹 Nettoyage des backups...');
        $this->info("📅 Rétention: {$retentionDays} jours");

        if ($dryRun) {
            $this->warn('⚠️  MODE SIMULATION (dry-run) - Aucune suppression réelle');
        }

        // Récupérer les tenants concernés
        $tenants = $tenantCode
            ? Tenant::where('code', $tenantCode)->get()
            : Tenant::where('status', 'active')->get();

        if ($tenants->isEmpty()) {
            $this->error('❌ Aucun tenant trouvé');
            return 1;
        }

        $this->info("📦 {$tenants->count()} tenant(s) à traiter");
        $this->newLine();

        $totalDeleted = 0;
        $totalFreedSpace = 0;

        foreach ($tenants as $tenant) {
            $this->info("🔄 Tenant: {$tenant->name} ({$tenant->code})");

            // Récupérer les backups expirés ou trop anciens
            $backupsToDelete = TenantBackup::where('tenant_id', $tenant->id)
                ->where(function ($query) use ($retentionDays) {
                    // Backups expirés
                    $query->where('expires_at', '<', now())
                          // OU backups trop anciens (créés il y a plus de X jours)
                          ->orWhere('created_at', '<', now()->subDays($retentionDays));
                })
                ->where('status', '!=', 'in_progress') // Ne pas supprimer les backups en cours
                ->get();

            if ($backupsToDelete->isEmpty()) {
                $this->line("  ✓ Aucun backup à supprimer");
                continue;
            }

            $this->line("  📋 {$backupsToDelete->count()} backup(s) à supprimer:");

            foreach ($backupsToDelete as $backup) {
                $age = $backup->created_at->diffInDays(now());
                $size = $backup->size_bytes ? round($backup->size_bytes / 1024 / 1024, 2) . ' MB' : 'N/A';

                $this->line("    • ID {$backup->id} - {$backup->type} - {$size} - {$age}j");

                if (!$dryRun) {
                    // Supprimer les fichiers physiques
                    $filesDeleted = $this->deleteBackupFiles($backup);

                    // Supprimer l'enregistrement en base
                    $totalFreedSpace += $backup->size_bytes ?? 0;
                    $backup->delete();

                    $totalDeleted++;
                }
            }

            $this->newLine();
        }

        // Résumé
        $this->newLine();
        $this->info('📊 Résumé du nettoyage:');
        $this->line("  • Backups supprimés: {$totalDeleted}");
        $this->line("  • Espace libéré: " . round($totalFreedSpace / 1024 / 1024, 2) . ' MB');

        if ($dryRun) {
            $this->warn('⚠️  Aucune action effectuée (mode simulation)');
        } else {
            $this->info('✅ Nettoyage terminé avec succès');
        }

        return 0;
    }

    /**
     * Supprime les fichiers ou dossiers physiques d'un backup
     */
    private function deleteBackupFiles(TenantBackup $backup): bool
    {
        $deleted = true;

        // `backup_path` désigne le dossier de l'instance, celui où vivent TOUTES
        // ses sauvegardes. Le supprimer pour une seule archive expirée effaçait
        // aussi celle de la nuit : le nettoyage de 3 h détruisait la sauvegarde
        // de 2 h. On ne supprime donc que des fichiers, jamais ce dossier.
        $paths = array_filter([
            is_file((string) $backup->backup_path) ? $backup->backup_path : null,
            $backup->database_backup_path,
            $backup->storage_backup_path,
        ]);

        // Le sceau d'une archive part avec elle : laissé seul, il ne scelle
        // plus rien et encombre le dossier.
        foreach (array_filter([$backup->database_backup_path, $backup->storage_backup_path]) as $archive) {
            $paths[] = SceauSauvegarde::chemin($archive);
        }

        foreach ($paths as $path) {
            if (!file_exists($path) && !is_dir($path)) {
                continue;
            }

            if (is_dir($path)) {
                $deleted = $deleted && $this->deleteDirectory($path);
            } else {
                $deleted = $deleted && unlink($path);
            }
        }

        return $deleted;
    }

    /**
     * Supprime un dossier récursivement
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        return rmdir($dir);
    }
}
