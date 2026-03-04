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

    /** Lignes accumulées dans le terminal */
    public array $terminalLines = [];

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
     * Ouvre le modal terminal et lance la commande en streaming.
     * Chaque ligne de sortie est streamée en temps réel via wire:stream.
     */
    public function runAllChecks(): void
    {
        $this->terminalLines = [];
        $this->terminalOpen  = true;

        $php     = PHP_BINARY;
        $artisan = base_path('artisan');

        $process = new Process([$php, '-d', 'memory_limit=512M', $artisan, 'tenant:health-check', '--all']);
        $process->setTimeout(300);
        $process->start();

        $this->stream('terminalOutput', $this->renderLine('', 'dim', '▶ Lancement de la vérification...'));
        $this->stream('terminalOutput', $this->renderLine('', 'dim', ''));

        foreach ($process as $type => $data) {
            foreach (explode("\n", rtrim($data)) as $raw) {
                $line = trim($raw);
                if ($line === '') {
                    continue;
                }
                $this->terminalLines[] = $line;
                $this->stream('terminalOutput', $this->renderLine($type, $this->detectLineType($line), $line));
            }
        }

        $exitCode = $process->getExitCode();

        $this->stream('terminalOutput', $this->renderLine('', 'dim', ''));
        if ($exitCode === 0) {
            $this->stream('terminalOutput', $this->renderLine('', 'success', '✔ Vérification terminée avec succès.'));
        } else {
            $this->stream('terminalOutput', $this->renderLine('', 'error', "✘ Terminé avec le code d'erreur {$exitCode}."));
        }

        // Recharger les données et fermer le modal via JS
        $this->loadData();
        $this->dispatch('terminal-done');
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
    private function renderLine(string $processType, string $lineType, string $text): string
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

        return "<div style=\"color:{$color};line-height:1.6;padding:1px 0;font-size:0.8rem;font-family:monospace;white-space:pre-wrap;word-break:break-all\">{$escaped}</div>\n";
    }

    public function closeTerminal(): void
    {
        $this->terminalOpen  = false;
        $this->terminalLines = [];
    }

    /**
     * Lance en foreground synchrone — pour un seul tenant (assez rapide).
     */
    public function runTenantCheck(string $tenantCode): void
    {
        $this->isRunningTenant = $tenantCode;

        $php     = PHP_BINARY;
        $artisan = base_path('artisan');

        $process = new Process([$php, '-d', 'memory_limit=512M', $artisan, 'tenant:health-check', $tenantCode]);
        $process->setTimeout(60);
        $process->run();

        $this->loadData();
        $this->isRunningTenant = '';

        Notification::make()
            ->title('Check terminé')
            ->body("Vérification de {$tenantCode} effectuée.")
            ->success()
            ->send();
    }

    public function rotateLogs(): void
    {
        $args = ['tenant:rotate-logs', '--days=' . $this->logRotateDays, '--all'];

        if ($this->logRotateDryRun) {
            $args[] = '--dry-run';
        }

        $php     = PHP_BINARY;
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
