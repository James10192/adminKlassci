<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantBackup as TenantBackupModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TenantBackup extends Command
{
    protected $signature = 'tenant:backup
                            {tenant? : Code du tenant (si omis, sauvegarde tous les tenants actifs)}
                            {--type=full : Type de backup (full, database_only, files_only)}
                            {--retention=30 : Durée de rétention en jours}
                            {--all : Forcer la sauvegarde de tous les tenants (actifs + suspendus)}';

    protected $description = 'Créer un backup complet ou partiel d\'un tenant (DB + fichiers)';

    private const BACKUP_TYPES = ['full', 'database_only', 'files_only', 'automated', 'manual'];

    public function handle()
    {
        $tenantCode = $this->argument('tenant');
        $backupType = $this->option('type');
        $retentionDays = (int) $this->option('retention');
        $backupAll = $this->option('all');

        // Validation du type de backup
        if (!in_array($backupType, self::BACKUP_TYPES)) {
            $this->error("❌ Type de backup invalide. Options: " . implode(', ', self::BACKUP_TYPES));
            return 1;
        }

        if ($tenantCode) {
            // Sauvegarder un seul tenant
            $tenant = Tenant::where('code', $tenantCode)->first();

            if (!$tenant) {
                $this->error("❌ Tenant '{$tenantCode}' introuvable.");
                return 1;
            }

            $this->performBackup($tenant, $backupType, $retentionDays);
        } else {
            // Sauvegarder plusieurs tenants
            $query = Tenant::query();

            if (!$backupAll) {
                $query->active();
            }

            $tenants = $query->get();

            if ($tenants->isEmpty()) {
                $this->warn('⚠️  Aucun tenant à sauvegarder.');
                return 0;
            }

            $this->info("💾 Sauvegarde de {$tenants->count()} tenant(s) (type: {$backupType})...");
            $this->newLine();

            $bar = $this->output->createProgressBar($tenants->count());
            $bar->start();

            foreach ($tenants as $tenant) {
                $this->performBackup($tenant, $backupType, $retentionDays, false);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
            $this->info('✅ Sauvegardes terminées !');
        }

        return 0;
    }

    private function performBackup(Tenant $tenant, string $backupType, int $retentionDays, bool $verbose = true)
    {
        if ($verbose) {
            $this->info("💾 Sauvegarde de '{$tenant->code}' (type: {$backupType})...");
            $this->newLine();
        }

        try {
            // Créer le répertoire de backup s'il n'existe pas
            $backupBaseDir = storage_path('app/backups/' . $tenant->code);
            if (!file_exists($backupBaseDir)) {
                mkdir($backupBaseDir, 0755, true);
            }

            // Nom du backup avec timestamp
            $timestamp = now()->format('Y-m-d_His');
            $backupName = "{$tenant->code}_{$backupType}_{$timestamp}";

            // Créer l'enregistrement de backup
            $backup = TenantBackupModel::create([
                'tenant_id' => $tenant->id,
                'type' => $backupType === 'full' ? 'manual' : $backupType,
                'backup_path' => $backupBaseDir,
                'status' => 'in_progress',
                'expires_at' => now()->addDays($retentionDays),
                'created_by_user_id' => null, // CLI execution
            ]);

            $databaseBackupPath = null;
            $storageBackupPath = null;
            $totalSize = 0;

            // Backup de la base de données
            if (in_array($backupType, ['full', 'database_only'])) {
                $databaseBackupPath = $this->backupDatabase($tenant, $backupBaseDir, $backupName);
                if ($databaseBackupPath && file_exists($databaseBackupPath)) {
                    $totalSize += filesize($databaseBackupPath);
                }
            }

            // Backup des fichiers
            if (in_array($backupType, ['full', 'files_only'])) {
                $storageBackupPath = $this->backupFiles($tenant, $backupBaseDir, $backupName);
                if ($storageBackupPath && file_exists($storageBackupPath)) {
                    $totalSize += filesize($storageBackupPath);
                }
            }

            // Mettre à jour le backup avec les chemins et la taille
            $backup->update([
                'database_backup_path' => $databaseBackupPath,
                'storage_backup_path' => $storageBackupPath,
                'size_bytes' => $totalSize,
                'status' => 'completed',
            ]);

            if ($verbose) {
                $this->displayBackupInfo($backup);
            }

        } catch (\Exception $e) {
            if (isset($backup)) {
                $backup->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            if ($verbose) {
                $this->error("❌ Erreur : {$e->getMessage()}");
            }

            \Log::error("Erreur backup tenant {$tenant->code}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function backupDatabase(Tenant $tenant, string $backupDir, string $backupName): ?string
    {
        $credentials = $tenant->database_credentials;
        $databaseName = $tenant->database_name;

        $backupFile = "{$backupDir}/{$backupName}_database.sql.gz";

        // Commande mysqldump avec compression gzip
        $command = sprintf(
            'mysqldump -h %s -P %d -u %s -p%s %s | gzip > %s',
            $credentials['host'] ?? 'localhost',
            $credentials['port'] ?? 3306,
            escapeshellarg($credentials['username']),
            escapeshellarg($credentials['password']),
            escapeshellarg($databaseName),
            escapeshellarg($backupFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("Échec du backup de la base de données (code: {$returnCode})");
        }

        return $backupFile;
    }

    private function backupFiles(Tenant $tenant, string $backupDir, string $backupName): ?string
    {
        $tenantPath = env('PRODUCTION_PATH') . $tenant->code;

        if (!file_exists($tenantPath) || !is_dir($tenantPath)) {
            throw new \Exception("Répertoire tenant introuvable: {$tenantPath}");
        }

        $backupFile = "{$backupDir}/{$backupName}_files.tar.gz";

        // Créer une archive tar.gz du répertoire storage
        $storagePath = "{$tenantPath}/storage";

        if (!file_exists($storagePath)) {
            throw new \Exception("Répertoire storage introuvable: {$storagePath}");
        }

        $command = sprintf(
            'tar -czf %s -C %s storage',
            escapeshellarg($backupFile),
            escapeshellarg($tenantPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("Échec du backup des fichiers (code: {$returnCode})");
        }

        return $backupFile;
    }

    private function displayBackupInfo(TenantBackupModel $backup): void
    {
        $sizeMb = $backup->size_bytes / 1024 / 1024;

        $this->table(
            ['Propriété', 'Valeur'],
            [
                ['ID Backup', $backup->id],
                ['Type', $backup->type],
                ['Statut', $backup->status],
                ['Taille', number_format($sizeMb, 2) . ' MB'],
                ['Database', $backup->database_backup_path ? '✅ Oui' : '❌ Non'],
                ['Fichiers', $backup->storage_backup_path ? '✅ Oui' : '❌ Non'],
                ['Expire le', $backup->expires_at->format('Y-m-d H:i:s')],
            ]
        );

        $this->newLine();
        $this->info('✅ Backup terminé avec succès !');
        $this->newLine();
    }
}
