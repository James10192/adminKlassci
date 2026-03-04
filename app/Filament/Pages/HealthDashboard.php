<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Models\TenantHealthCheck;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Symfony\Component\Process\Process;

class HealthDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationLabel = 'Santé des tenants';

    protected static ?string $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.health-dashboard';

    protected static ?string $title = 'Tableau de bord Santé';

    public array $tenantChecks = [];
    public array $stats = ['healthy' => 0, 'degraded' => 0, 'unhealthy' => 0];
    public mixed $lastCheckedAt = null;

    public int $logRotateDays = 30;
    public bool $logRotateDryRun = false;

    /** Modal terminal ouvert */
    public bool $terminalOpen = false;

    /** Run ID du process en cours (UUID court) */
    public string $runId = '';

    /** Offset de lecture du fichier log (nb de bytes déjà lus) */
    public int $logOffset = 0;

    /** Process terminé */
    public bool $runDone = false;

    /** Indique qu'un reload des données est nécessaire à la fermeture */
    public bool $pendingReload = false;

    /** Check individuel en cours */
    public string $isRunningTenant = '';

    private const CHECK_TYPES = [
        'http_status',
        'database_connection',
        'disk_space',
        'ssl_certificate',
        'application_errors',
        'queue_workers',
    ];

    public static function getNavigationBadge(): ?string
    {
        $count = TenantHealthCheck::whereIn('status', ['degraded', 'unhealthy'])
            ->where('checked_at', '>=', now()->subHour())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $critical = TenantHealthCheck::where('status', 'unhealthy')
            ->where('checked_at', '>=', now()->subHour())
            ->count();

        return $critical > 0 ? 'danger' : 'warning';
    }

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $tenants = Tenant::active()->orderBy('name')->get();

        $tenantChecks   = [];
        $healthyCount   = 0;
        $degradedCount  = 0;
        $unhealthyCount = 0;

        foreach ($tenants as $tenant) {
            $checks             = [];
            $tenantGlobalStatus = 'healthy';
            $lastCheck          = null;

            foreach (self::CHECK_TYPES as $checkType) {
                $latest = TenantHealthCheck::where('tenant_id', $tenant->id)
                    ->where('check_type', $checkType)
                    ->latest('checked_at')
                    ->first();

                $checks[$checkType] = [
                    'status'           => $latest?->status ?? 'unknown',
                    'response_time_ms' => $latest?->response_time_ms,
                    'details'          => $latest?->details,
                    'checked_at'       => $latest?->checked_at,
                ];

                if (($latest?->status ?? 'unknown') === 'unhealthy') {
                    $tenantGlobalStatus = 'unhealthy';
                } elseif (($latest?->status ?? 'unknown') === 'degraded' && $tenantGlobalStatus !== 'unhealthy') {
                    $tenantGlobalStatus = 'degraded';
                }

                if ($latest && (! $lastCheck || $latest->checked_at > $lastCheck)) {
                    $lastCheck = $latest->checked_at;
                }
            }

            $tenantChecks[] = [
                'tenant'        => $tenant,
                'checks'        => $checks,
                'global_status' => $tenantGlobalStatus,
                'last_check'    => $lastCheck,
            ];

            match ($tenantGlobalStatus) {
                'healthy'   => $healthyCount++,
                'degraded'  => $degradedCount++,
                'unhealthy' => $unhealthyCount++,
                default     => null,
            };
        }

        usort($tenantChecks, function ($a, $b) {
            $order = ['unhealthy' => 0, 'degraded' => 1, 'healthy' => 2];
            return ($order[$a['global_status']] ?? 3) <=> ($order[$b['global_status']] ?? 3);
        });

        $this->tenantChecks = $tenantChecks;
        $this->stats        = [
            'healthy'   => $healthyCount,
            'degraded'  => $degradedCount,
            'unhealthy' => $unhealthyCount,
        ];

        $latest              = TenantHealthCheck::latest('checked_at')->first();
        $this->lastCheckedAt = $latest?->checked_at;
    }

    /**
     * Lance la vérification en arrière-plan et ouvre le terminal.
     * La sortie est écrite dans un fichier tmp lu par pollTerminal().
     */
    public function runAllChecks(): void
    {
        $this->runId         = uniqid('health_', true);
        $this->logOffset     = 0;
        $this->runDone       = false;
        $this->pendingReload = false;
        $this->terminalOpen  = true;

        $logFile = $this->logFile();
        $pidFile = $this->pidFile();

        // PHP_BINARY sur LWS pointe vers le binaire LSAPI (mode web) qui ne supporte pas -d.
        // On utilise le PHP CLI : /usr/local/bin/php (LWS standard), sinon PHP_BINARY.
        $php     = file_exists('/usr/local/bin/php') ? '/usr/local/bin/php' : PHP_BINARY;
        $artisan = base_path('artisan');

        // Lance en arrière-plan via proc_open avec STDIN/STDOUT/STDERR fermés
        // proc_open + /dev/null garantit un vrai détachement même sur LWS/Varnish
        // où shell_exec('... &') bloque quand même jusqu'à la fin du process
        $cmd = sprintf(
            '%s -d memory_limit=512M %s tenant:health-check --all > %s 2>&1; echo $? > %s',
            escapeshellarg($php),
            escapeshellarg($artisan),
            escapeshellarg($logFile),
            escapeshellarg($pidFile)
        );

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],  // stdin  : fermé
            1 => ['file', '/dev/null', 'w'],  // stdout : fermé (la commande redirige elle-même)
            2 => ['file', '/dev/null', 'w'],  // stderr : fermé
        ];

        $fullCmd = 'nohup bash -c ' . escapeshellarg($cmd) . ' &';
        $proc = proc_open($fullCmd, $descriptors, $pipes);
        if (is_resource($proc)) {
            proc_close($proc);
        }
    }

    /**
     * Appelé par wire:poll — lit les nouvelles lignes du fichier log
     * et les dispatch vers le JS via un événement.
     */
    public function pollTerminal(): void
    {
        if (! $this->terminalOpen || $this->runId === '') {
            return;
        }

        $logFile = $this->logFile();
        $pidFile = $this->pidFile();

        // Lire les nouvelles lignes depuis l'offset actuel
        $newLines = [];
        if (file_exists($logFile)) {
            $handle = fopen($logFile, 'r');
            if ($handle) {
                fseek($handle, $this->logOffset);
                $chunk = '';
                while (! feof($handle)) {
                    $chunk .= fread($handle, 8192);
                }
                $this->logOffset = ftell($handle);
                fclose($handle);

                // Nettoyer les codes ANSI et les \r (progress bars)
                $chunk = preg_replace('/\x1B\[[0-9;]*[a-zA-Z]/', '', $chunk); // codes ANSI
                $chunk = preg_replace('/\x1B\][^\x07]*\x07/', '', $chunk);    // OSC sequences

                foreach (explode("\n", $chunk) as $raw) {
                    // Garder seulement la dernière partie après un \r (dernière état de la progress bar)
                    $parts = explode("\r", $raw);
                    $line  = trim(end($parts));
                    if ($line !== '') {
                        $newLines[] = $this->renderLine($this->detectLineType($line), $line);
                    }
                }
            }
        }

        // Vérifier si le process est terminé (fichier .pid créé avec exit code)
        $isDone = false;
        if (! $this->runDone && file_exists($pidFile)) {
            $exitCode = (int) trim(file_get_contents($pidFile));
            $isDone   = true;
            $this->runDone = true;

            // Ligne de fin
            $newLines[] = $this->renderLine('dim', '');
            if ($exitCode === 0) {
                $newLines[] = $this->renderLine('success', '✔ Vérification terminée avec succès.');
            } else {
                $newLines[] = $this->renderLine('error', "✘ Terminé avec le code d'erreur {$exitCode}.");
            }

            // Marquer qu'un reload est nécessaire — on NE recharge PAS ici
            // pour éviter que Livewire re-render le composant et détruise
            // le terminal Alpine avant que l'event terminal-update soit traité.
            $this->pendingReload = true;

            // Nettoyer les fichiers tmp
            @unlink($logFile);
            @unlink($pidFile);
        }

        // Dispatcher les nouvelles lignes + état done vers Alpine.js
        if (! empty($newLines) || $isDone) {
            $this->dispatch('terminal-update', lines: $newLines, done: $isDone);
        }
    }

    public function closeTerminal(): void
    {
        $needsReload = $this->pendingReload;

        $this->terminalOpen  = false;
        $this->runId         = '';
        $this->logOffset     = 0;
        $this->runDone       = false;
        $this->pendingReload = false;

        // Nettoyage fichiers si le terminal est fermé avant la fin
        @unlink($this->logFile());
        @unlink($this->pidFile());

        // Recharger les données maintenant que le terminal est fermé
        // (le re-render Livewire ne détruit plus rien)
        if ($needsReload) {
            $this->loadData();
        }

        // Déclencher la mise à jour des timestamps relatifs côté JS
        $this->dispatch('refresh-timeago');
    }

    private function logFile(): string
    {
        return sys_get_temp_dir() . '/health_run_' . $this->runId . '.log';
    }

    private function pidFile(): string
    {
        return sys_get_temp_dir() . '/health_run_' . $this->runId . '.done';
    }

    /** Détermine le style d'une ligne selon son contenu */
    private function detectLineType(string $line): string
    {
        $lower = strtolower($line);
        if (str_contains($lower, 'error') || str_contains($lower, 'fail') || str_contains($lower, 'critical') || str_contains($lower, 'unhealthy')) {
            return 'error';
        }
        if (str_contains($lower, 'warn') || str_contains($lower, 'degraded')) {
            return 'warning';
        }
        if (str_contains($lower, 'ok') || str_contains($lower, 'healthy') || str_contains($lower, 'success') || str_contains($lower, '✔') || str_contains($lower, 'done')) {
            return 'success';
        }
        if (str_contains($lower, 'checking') || str_contains($lower, 'tenant') || str_contains($lower, '►') || str_contains($lower, '→')) {
            return 'info';
        }
        return 'normal';
    }

    /** Retourne le HTML d'une ligne de terminal */
    private function renderLine(string $lineType, string $text): string
    {
        $color = match ($lineType) {
            'success' => '#4ade80',
            'error'   => '#f87171',
            'warning' => '#fbbf24',
            'info'    => '#67e8f9',
            'dim'     => '#6b7280',
            default   => '#e2e8f0',
        };

        $escaped = htmlspecialchars($text, ENT_QUOTES);

        return "<div style=\"color:{$color};line-height:1.6;padding:1px 0;font-size:0.8rem;font-family:monospace;white-space:pre-wrap;word-break:break-all\">{$escaped}</div>";
    }

    /**
     * Lance en foreground synchrone — pour un seul tenant (assez rapide).
     */
    public function runTenantCheck(string $tenantCode): void
    {
        $this->isRunningTenant = $tenantCode;

        $php     = file_exists('/usr/local/bin/php') ? '/usr/local/bin/php' : PHP_BINARY;
        $artisan = base_path('artisan');

        $before = \App\Models\TenantHealthCheck::latest('checked_at')->value('checked_at');

        $process = new Process([$php, '-d', 'memory_limit=512M', $artisan, 'tenant:health-check', $tenantCode]);
        $process->setTimeout(60);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();
        $newChecksCount = \App\Models\TenantHealthCheck::where('checked_at', '>', $before ?? '1970-01-01')->count();

        \Log::info('[HealthDashboard] runTenantCheck', [
            'tenantCode'    => $tenantCode,
            'exitCode'      => $process->getExitCode(),
            'newChecks'     => $newChecksCount,
            'output'        => substr($output, 0, 500),
        ]);

        $this->loadData();
        $this->isRunningTenant = '';

        Notification::make()
            ->title('Check terminé')
            ->body("Vérification de {$tenantCode} effectuée. ({$newChecksCount} checks créés)")
            ->success()
            ->send();
    }

    public function rotateLogs(): void
    {
        $args = ['tenant:rotate-logs', '--days=' . $this->logRotateDays, '--all'];

        if ($this->logRotateDryRun) {
            $args[] = '--dry-run';
        }

        $php     = file_exists('/usr/local/bin/php') ? '/usr/local/bin/php' : PHP_BINARY;
        $artisan = base_path('artisan');

        $process = new Process([$php, '-d', 'memory_limit=512M', $artisan, ...$args]);
        $process->setTimeout(120);
        $process->run();

        $title = $this->logRotateDryRun ? 'Simulation terminée' : 'Rotation terminée';
        $body  = $this->logRotateDryRun
            ? "Simulation sur {$this->logRotateDays} jours — aucun fichier modifié."
            : "Rotation effectuée — logs de plus de {$this->logRotateDays} jours supprimés.";

        Notification::make()
            ->title($title)
            ->body($body)
            ->success()
            ->send();
    }
}
