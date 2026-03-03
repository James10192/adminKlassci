<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Models\TenantHealthCheck;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
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

    /** Indique si un health-check global est en cours (affiche un spinner) */
    public bool $isRunningAll = false;

    /** Code du tenant en cours de vérification individuelle */
    public string $isRunningTenant = '';

    /** Timestamp (microtime) du dernier lancement pour détecter la fin */
    public float $runStartedAt = 0;

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

    /**
     * Appelé toutes les 3 secondes par wire:poll quand un check est en cours.
     * Rafraîchit les données et détecte la fin du process.
     */
    public function pollCheck(): void
    {
        if (! $this->isRunningAll && $this->isRunningTenant === '') {
            return;
        }

        // Vérifier si de nouveaux checks sont apparus depuis le lancement
        $latest = TenantHealthCheck::latest('checked_at')->first();
        $latestTs = $latest?->checked_at?->timestamp ?? 0;

        if ($latestTs >= (int) $this->runStartedAt) {
            // Des nouvelles données sont disponibles : recharger et arrêter le poll
            $this->loadData();
            $this->isRunningAll    = false;
            $this->isRunningTenant = '';
            $this->runStartedAt    = 0;
        }

        // Timeout de sécurité : 3 minutes sans résultats → arrêter le spinner
        if ($this->runStartedAt > 0 && (microtime(true) - $this->runStartedAt) > 180) {
            $this->loadData();
            $this->isRunningAll    = false;
            $this->isRunningTenant = '';
            $this->runStartedAt    = 0;
        }
    }

    public function loadData(): void
    {
        $tenants = Tenant::active()->orderBy('name')->get();

        $tenantChecks  = [];
        $healthyCount  = 0;
        $degradedCount = 0;
        $unhealthyCount = 0;

        foreach ($tenants as $tenant) {
            $checks = [];
            $tenantGlobalStatus = 'healthy';
            $lastCheck = null;

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

            match($tenantGlobalStatus) {
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
        $this->stats = [
            'healthy'   => $healthyCount,
            'degraded'  => $degradedCount,
            'unhealthy' => $unhealthyCount,
        ];

        $latest = TenantHealthCheck::latest('checked_at')->first();
        $this->lastCheckedAt = $latest?->checked_at;
    }

    /**
     * Lance la commande Artisan en background (non-bloquant).
     * Le poll Livewire détectera la fin et rafraîchira les données.
     */
    private function runArtisanBackground(array $args): void
    {
        $php     = PHP_BINARY;
        $artisan = base_path('artisan');
        $log     = storage_path('logs/health-checks.log');

        $cmd = implode(' ', array_map('escapeshellarg', [
            $php, '-d', 'memory_limit=512M', $artisan, ...$args,
        ]));

        // Lancer en background : la requête HTTP retourne immédiatement
        exec("{$cmd} >> " . escapeshellarg($log) . ' 2>&1 &');
    }

    /**
     * Lance en foreground (bloquant) — pour les commandes rapides (un seul tenant).
     */
    private function runArtisanSync(array $args): void
    {
        $php     = PHP_BINARY;
        $artisan = base_path('artisan');

        $process = new Process([$php, '-d', 'memory_limit=512M', $artisan, ...$args]);
        $process->setTimeout(60);
        $process->run();
    }

    public function runAllChecks(): void
    {
        $this->runStartedAt = microtime(true);
        $this->isRunningAll = true;

        // Lance en background — ne bloque pas la requête HTTP
        $this->runArtisanBackground(['tenant:health-check', '--all']);
    }

    public function runTenantCheck(string $tenantCode): void
    {
        $this->runStartedAt    = microtime(true);
        $this->isRunningTenant = $tenantCode;

        // Un seul tenant : suffisamment rapide pour être synchrone
        $this->runArtisanSync(['tenant:health-check', $tenantCode]);

        // Recharger immédiatement après
        $this->loadData();
        $this->isRunningTenant = '';
        $this->runStartedAt    = 0;

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

        $this->runArtisanSync($args);

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
