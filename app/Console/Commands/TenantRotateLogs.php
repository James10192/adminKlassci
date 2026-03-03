<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class TenantRotateLogs extends Command
{
    protected $signature = 'tenant:rotate-logs
                            {--days=30    : Nombre de jours de logs à conserver}
                            {--dry-run    : Affiche ce qui serait fait sans modifier les fichiers}
                            {--all        : Inclure aussi les tenants suspendus/inactifs}';

    protected $description = 'Tronque les fichiers laravel.log des tenants pour ne garder que les N derniers jours.';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $all    = $this->option('all');

        if ($days < 1) {
            $this->error('--days doit être >= 1.');
            return 1;
        }

        $tenants = $all
            ? Tenant::all()
            : Tenant::active()->get();

        if ($tenants->isEmpty()) {
            $this->warn('Aucun tenant trouvé.');
            return 0;
        }

        $productionPath = env('PRODUCTION_PATH', '');
        $cutoff         = now()->subDays($days)->format('Y-m-d');
        $totalSaved     = 0;
        $processed      = 0;

        $this->info("Rotation des logs — conservation des {$days} derniers jours (avant {$cutoff}).");
        if ($dryRun) {
            $this->warn('Mode dry-run : aucun fichier ne sera modifié.');
        }
        $this->newLine();

        foreach ($tenants as $tenant) {
            $logPath = rtrim($productionPath, '/') . '/' . $tenant->code . '/storage/logs/laravel.log';

            if (!file_exists($logPath)) {
                $this->line("  [{$tenant->code}] Pas de fichier log — ignoré.");
                continue;
            }

            $sizeBefore = filesize($logPath);

            if ($sizeBefore === 0) {
                $this->line("  [{$tenant->code}] Fichier vide — ignoré.");
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '  [%s] %s — serait tronqué (taille actuelle : %s)',
                    $tenant->code,
                    $logPath,
                    $this->formatBytes($sizeBefore)
                ));
                continue;
            }

            // Lire via tail/SplFileObject pour ne pas charger tout le fichier en RAM,
            // puis ne garder que les lignes datant de moins de $days jours.
            $kept = $this->filterRecentLines($logPath, $cutoff);

            if ($kept === null) {
                $this->error("  [{$tenant->code}] Impossible de lire le fichier — ignoré.");
                continue;
            }

            // Réécriture atomique : écriture dans un fichier temporaire puis rename
            $tmp = $logPath . '.tmp';
            if (file_put_contents($tmp, implode('', $kept), LOCK_EX) === false) {
                $this->error("  [{$tenant->code}] Impossible d'écrire le fichier temporaire.");
                @unlink($tmp);
                continue;
            }

            if (!rename($tmp, $logPath)) {
                $this->error("  [{$tenant->code}] Impossible de remplacer le fichier log.");
                @unlink($tmp);
                continue;
            }

            clearstatcache(true, $logPath);
            $sizeAfter = filesize($logPath);
            $saved     = max(0, $sizeBefore - $sizeAfter);
            $totalSaved += $saved;
            $processed++;

            $this->line(sprintf(
                '  [%s] %s → %s (économie : %s)',
                $tenant->code,
                $this->formatBytes($sizeBefore),
                $this->formatBytes($sizeAfter),
                $this->formatBytes($saved)
            ));
        }

        $this->newLine();

        if ($dryRun) {
            $this->info("Dry-run terminé — {$tenants->count()} tenant(s) analysé(s).");
        } else {
            $this->info(sprintf(
                'Rotation terminée — %d fichier(s) traité(s), %s libérés.',
                $processed,
                $this->formatBytes($totalSaved)
            ));
        }

        return 0;
    }

    /**
     * Lit le fichier log ligne par ligne (sans charger tout en RAM)
     * et retourne uniquement les lignes postérieures à $cutoff (YYYY-MM-DD).
     *
     * Stratégie : on parcourt le fichier avec SplFileObject pour ne pas
     * exploser la mémoire sur des fichiers de plusieurs centaines de MB.
     */
    private function filterRecentLines(string $path, string $cutoff): ?array
    {
        try {
            $file = new \SplFileObject($path, 'r');
            $file->setFlags(\SplFileObject::DROP_NEW_LINE | \SplFileObject::SKIP_EMPTY);

            $kept           = [];
            $currentEntry   = [];
            $keepCurrent    = false;

            // Pattern de début d'entrée Laravel : [YYYY-MM-DD HH:MM:SS]
            $datePattern = '/^\[(\d{4}-\d{2}-\d{2})/';

            foreach ($file as $raw) {
                $line = (string) $raw;

                if (preg_match($datePattern, $line, $m)) {
                    // Sauvegarder l'entrée précédente si elle doit être conservée
                    if ($keepCurrent && !empty($currentEntry)) {
                        foreach ($currentEntry as $l) {
                            $kept[] = $l . "\n";
                        }
                    }

                    // Démarrer une nouvelle entrée
                    $currentEntry = [$line];
                    $keepCurrent  = ($m[1] >= $cutoff);
                } else {
                    // Ligne de continuation (stack trace, etc.)
                    $currentEntry[] = $line;
                }
            }

            // Flush de la dernière entrée
            if ($keepCurrent && !empty($currentEntry)) {
                foreach ($currentEntry as $l) {
                    $kept[] = $l . "\n";
                }
            }

            return $kept;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 2) . ' MB';
        }
        if ($bytes >= 1_024) {
            return round($bytes / 1_024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
