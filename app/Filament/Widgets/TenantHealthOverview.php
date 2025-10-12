<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use App\Models\TenantHealthCheck;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TenantHealthOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    // Rafraîchissement automatique toutes les 30 secondes
    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        // Récupérer les derniers health checks pour chaque tenant (1 par tenant)
        $latestChecks = TenantHealthCheck::select('tenant_id')
            ->selectRaw('MAX(created_at) as latest_check')
            ->groupBy('tenant_id')
            ->get();

        $healthyCount = 0;
        $warningCount = 0;
        $criticalCount = 0;
        $unknownCount = 0;
        $tenantsWithIssues = [];

        foreach ($latestChecks as $checkInfo) {
            // Récupérer le dernier check pour ce tenant
            $latestCheck = TenantHealthCheck::where('tenant_id', $checkInfo->tenant_id)
                ->where('created_at', $checkInfo->latest_check)
                ->with('tenant')
                ->first();

            if (!$latestCheck) {
                $unknownCount++;
                continue;
            }

            // Déterminer le statut global du tenant
            $tenantChecks = TenantHealthCheck::where('tenant_id', $checkInfo->tenant_id)
                ->where('created_at', '>=', now()->subMinutes(10))
                ->get();

            $hasCritical = $tenantChecks->where('status', 'unhealthy')->count() > 0;
            $hasWarning = $tenantChecks->where('status', 'degraded')->count() > 0;

            if ($hasCritical) {
                $criticalCount++;
                $tenantsWithIssues[] = [
                    'name' => $latestCheck->tenant->name,
                    'status' => 'unhealthy',
                    'id' => $latestCheck->tenant->id,
                ];
            } elseif ($hasWarning) {
                $warningCount++;
                $tenantsWithIssues[] = [
                    'name' => $latestCheck->tenant->name,
                    'status' => 'degraded',
                    'id' => $latestCheck->tenant->id,
                ];
            } else {
                $healthyCount++;
            }
        }

        // Total tenants actifs
        $totalTenants = Tenant::where('status', 'active')->count();

        return [
            Stat::make('Tenants Healthy', $healthyCount)
                ->description("Sur {$totalTenants} tenants actifs")
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success')
                ->chart([7, 6, 8, 5, 6, 7, $healthyCount]),

            Stat::make('Tenants Warning', $warningCount)
                ->description('Nécessitent attention')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->chart([1, 2, 1, 3, 2, 1, $warningCount]),

            Stat::make('Tenants Critical', $criticalCount)
                ->description('Action immédiate requise')
                ->descriptionIcon('heroicon-o-x-circle')
                ->color('danger')
                ->chart([0, 1, 0, 0, 1, 2, $criticalCount]),
        ];
    }
}
