<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Domain\Exploitation\Sauvegarde\CoffreSauvegarde;
use App\Domain\Exploitation\Sauvegarde\PipelineSauvegarde;
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

            // Déposer une copie hors du serveur qu'on vient de sauvegarder.
            // Tant qu'elle reste ici, elle protège d'une fausse manipulation,
            // pas d'une perte du serveur — et elle disparaît avec lui.
            $horsSite = [];

            foreach (array_filter([$databaseBackupPath, $storageBackupPath]) as $fichier) {
                $depot = CoffreSauvegarde::deposer($fichier, $tenant->code);

                if ($depot !== null) {
                    $horsSite[] = $depot;
                }
            }

            $backup->update([
                'database_backup_path' => $databaseBackupPath,
                'storage_backup_path' => $storageBackupPath,
                'size_bytes' => $totalSize,
                'est_chiffre' => CoffreSauvegarde::cle() !== null,
                'copie_hors_site' => $horsSite === [] ? null : implode(', ', $horsSite),
                'copie_hors_site_at' => $horsSite === [] ? null : now(),
                'status' => 'completed',
            ]);

            // Ce qui manque doit se voir. Une sauvegarde en clair, ou restée
            // sur le serveur qu'elle protège, n'est pas une faute de la
            // commande — c'est un réglage absent, et il faut qu'on le sache.
            if (CoffreSauvegarde::cle() === null) {
                $this->avertirUneFois('sauvegardes non chiffrées : SAUVEGARDE_CLE absente ou trop courte (32 caractères minimum)');
            }

            if (CoffreSauvegarde::disqueHorsSite() === null) {
                $this->avertirUneFois('sauvegardes conservées sur le serveur sauvegardé : SAUVEGARDE_DISQUE_HORS_SITE absent');
            }

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

    /**
     * Le dump de la base d'une instance, compressé et — si une clé est posée —
     * chiffré.
     *
     * La commande est construite par `PipelineSauvegarde`, qui est vérifiée.
     * Ce qui se joue ici, c'est le cycle de vie des deux secrets : ils vivent
     * dans des fichiers en 0600, et sont effacés quoi qu'il arrive. Le `finally`
     * n'est pas une politesse — sans lui, un dump qui échoue laisse le mot de
     * passe de la base d'une école dans /tmp.
     */
    private function backupDatabase(Tenant $tenant, string $backupDir, string $backupName): ?string
    {
        $cle = CoffreSauvegarde::cle();
        $chiffre = $cle !== null;

        $backupFile = "{$backupDir}/" . PipelineSauvegarde::extension("{$backupName}_database.sql.gz", $chiffre);

        $fichierOptions = null;
        $fichierCle = null;

        try {
            $fichierOptions = CoffreSauvegarde::fichierSecret(
                PipelineSauvegarde::fichierOptions($tenant->database_credentials ?? []),
            );

            if ($chiffre) {
                $fichierCle = CoffreSauvegarde::fichierSecret($cle);
            }

            exec(PipelineSauvegarde::commandeDump(
                $fichierOptions,
                $tenant->database_name,
                $backupFile,
                $fichierCle,
            ), $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \Exception("Échec du backup de la base de données (code: {$returnCode})");
            }
        } finally {
            CoffreSauvegarde::effacerSecret($fichierOptions);
            CoffreSauvegarde::effacerSecret($fichierCle);
        }

        $doute = PipelineSauvegarde::raisonDeDouter($backupFile, $chiffre);

        if ($doute !== null) {
            throw new \Exception("Sauvegarde de la base inutilisable : {$doute}");
        }

        return $backupFile;
    }

    /** L'archive du dossier `storage` d'une instance, chiffrée si une clé est posée. */
    private function backupFiles(Tenant $tenant, string $backupDir, string $backupName): ?string
    {
        $tenantPath = env('PRODUCTION_PATH') . $tenant->code;

        if (!file_exists($tenantPath) || !is_dir($tenantPath)) {
            throw new \Exception("Répertoire tenant introuvable: {$tenantPath}");
        }

        if (!file_exists("{$tenantPath}/storage")) {
            throw new \Exception("Répertoire storage introuvable: {$tenantPath}/storage");
        }

        $cle = CoffreSauvegarde::cle();
        $chiffre = $cle !== null;

        $backupFile = "{$backupDir}/" . PipelineSauvegarde::extension("{$backupName}_files.tar.gz", $chiffre);

        $fichierCle = null;

        try {
            if ($chiffre) {
                $fichierCle = CoffreSauvegarde::fichierSecret($cle);
            }

            exec(PipelineSauvegarde::commandeFichiers($tenantPath, $backupFile, $fichierCle), $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \Exception("Échec du backup des fichiers (code: {$returnCode})");
            }
        } finally {
            CoffreSauvegarde::effacerSecret($fichierCle);
        }

        $doute = PipelineSauvegarde::raisonDeDouter($backupFile, $chiffre);

        if ($doute !== null) {
            throw new \Exception("Sauvegarde des fichiers inutilisable : {$doute}");
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

    /**
     * Signale un réglage manquant, une fois par exécution.
     *
     * `--all` passe sur six instances : sans ce garde-fou, le même
     * avertissement s'écrirait six fois et se lirait zéro.
     */
    private function avertirUneFois(string $message): void
    {
        static $deja = [];

        if (isset($deja[$message])) {
            return;
        }

        $deja[$message] = true;

        $this->warn("⚠️  {$message}");
        \Log::warning("[sauvegarde] {$message}");
    }
}
