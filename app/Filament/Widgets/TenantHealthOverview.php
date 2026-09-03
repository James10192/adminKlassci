<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use App\Models\TenantHealthCheck;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * État de santé des établissements, à l'instant présent.
 *
 * Les tuiles portaient elles aussi des sparklines inventées : une série codée
 * en dur (1, 0, 1, 2, 1, 0, valeur) ou de l'arithmétique sur le chiffre du
 * jour présentée comme un historique. Sur un écran de supervision, une courbe
 * qui ne vient pas des relevés est pire qu'inutile : elle rassure.
 *
 * `tenant_health_checks` garde bien des relevés horodatés, donc une vraie
 * tendance est calculable — mais elle demanderait un index de tête sur la
 * colonne de date, absent aujourd'hui, alors que ce widget se rafraîchit
 * toutes les 30 secondes. À faire quand l'index sera là.
 *
 * La ligne « Dernière vérif. il y a N minutes », elle, est réelle et suffit à
 * dire si le chiffre affiché est frais.
 */
class TenantHealthOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '30s';

    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        $totalTenants = Tenant::where('status', 'active')->count();

        // No health checks yet — use tenant count as baseline
        if (TenantHealthCheck::count() === 0) {
            return [
                Stat::make('Tenants Opérationnels', $totalTenants)
                    ->description("Aucun health check exécuté pour l'instant")
                    ->descriptionIcon('heroicon-o-information-circle')
                    ->color('gray'),

                Stat::make('Tenants en Alerte', 0)
                    ->description('Nécessitent attention')
                    ->descriptionIcon('heroicon-o-exclamation-triangle')
                    ->color('warning'),

                Stat::make('Tenants Critiques', 0)
                    ->description('Lancez un health-check pour détecter les problèmes')
                    ->descriptionIcon('heroicon-o-shield-check')
                    ->color('success'),
            ];
        }

        // Get latest check per tenant (subquery approach for performance)
        $latestCheckIds = TenantHealthCheck::selectRaw('MAX(id) as id')
            ->groupBy('tenant_id')
            ->pluck('id');

        $latestChecks = TenantHealthCheck::whereIn('id', $latestCheckIds)
            ->with('tenant')
            ->get();

        $healthyCount = 0;
        $warningCount = 0;
        $criticalCount = 0;
        $checkedTenantIds = [];

        foreach ($latestChecks as $check) {
            if (!$check->tenant) continue;

            $tenantId = $check->tenant_id;
            if (in_array($tenantId, $checkedTenantIds)) continue;
            $checkedTenantIds[] = $tenantId;

            // Look at recent checks for this tenant (last 15 min)
            $recentStatuses = TenantHealthCheck::where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->subMinutes(15))
                ->pluck('status')
                ->toArray();

            if (empty($recentStatuses)) {
                // Fall back to latest single check
                $recentStatuses = [$check->status];
            }

            if (in_array('unhealthy', $recentStatuses)) {
                $criticalCount++;
            } elseif (in_array('degraded', $recentStatuses)) {
                $warningCount++;
            } else {
                $healthyCount++;
            }
        }

        // Tenants with no health check at all
        $uncheckedCount = $totalTenants - count($checkedTenantIds);
        $healthyCount = max(0, $healthyCount + $uncheckedCount);

        $lastCheckTime = TenantHealthCheck::latest('created_at')->value('created_at');
        $lastCheckLabel = $lastCheckTime
            ? 'Dernière vérif. ' . \Carbon\Carbon::parse($lastCheckTime)->diffForHumans()
            : 'Aucune vérification';

        return [
            Stat::make('Opérationnels', $healthyCount)
                ->description("Sur {$totalTenants} actifs · {$lastCheckLabel}")
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('En Alerte', $warningCount)
                ->description($warningCount > 0 ? 'Vérification recommandée' : 'Aucune dégradation détectée')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($warningCount > 0 ? 'warning' : 'gray'),

            Stat::make('Critiques', $criticalCount)
                ->description($criticalCount > 0 ? 'Intervention immédiate requise !' : 'Aucun incident critique')
                ->descriptionIcon($criticalCount > 0 ? 'heroicon-o-x-circle' : 'heroicon-o-shield-check')
                ->color($criticalCount > 0 ? 'danger' : 'success'),
        ];
    }
}
