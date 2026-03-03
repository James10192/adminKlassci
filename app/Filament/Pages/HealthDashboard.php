<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Models\TenantHealthCheck;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;

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

        $tenantChecks = [];
        $healthyCount = 0;
        $degradedCount = 0;
        $unhealthyCount = 0;

        foreach ($tenants as $tenant) {
            // Dernier check de chaque type pour ce tenant
            $checks = [];
            $tenantGlobalStatus = 'healthy';
            $lastCheck = null;

            foreach (self::CHECK_TYPES as $checkType) {
                $latest = TenantHealthCheck::where('tenant_id', $tenant->id)
                    ->where('check_type', $checkType)
                    ->latest('checked_at')
                    ->first();

                $checks[$checkType] = [
                    'status'          => $latest?->status ?? 'unknown',
                    'response_time_ms'=> $latest?->response_time_ms,
                    'details'         => $latest?->details,
                    'checked_at'      => $latest?->checked_at,
                ];

                // Calculer le statut global du tenant
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

        // Trier : critiques en premier, puis dégradés, puis sains
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

        // Dernier check global toutes entités confondues
        $latest = TenantHealthCheck::latest('checked_at')->first();
        $this->lastCheckedAt = $latest?->checked_at;
    }

    public function runAllChecks(): void
    {
        Artisan::call('tenant:health-check', ['--all' => true]);

        $this->loadData();

        Notification::make()
            ->title('Health checks terminés')
            ->body('Tous les tenants actifs ont été vérifiés.')
            ->success()
            ->send();
    }

    public function runTenantCheck(string $tenantCode): void
    {
        Artisan::call('tenant:health-check', ['tenant' => $tenantCode]);

        $this->loadData();

        Notification::make()
            ->title('Check terminé')
            ->body("Vérification de {$tenantCode} effectuée.")
            ->success()
            ->send();
    }
}
