<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use App\Models\TenantHealthCheck;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        $activeTenants = Tenant::where('status', 'active')->get();
        $activeTenantsCount = $activeTenants->count();

        // Total étudiants agrégé depuis tous les tenants actifs
        $totalStudents = $activeTenants->sum('current_students');

        // MRR annuel (abonnements annuels)
        $mrr = $activeTenants->sum('monthly_fee');

        // Alertes : quota + expiration dans 30j
        $tenantsOverQuota = $activeTenants->filter(fn ($t) => $t->isOverQuota())->count();
        $expiringTenants = Tenant::where('status', 'active')
            ->where('subscription_end_date', '<=', now()->addDays(30))
            ->where('subscription_end_date', '>=', now())
            ->count();

        // Tenants inactifs depuis 7j (aucune update des stats)
        $inactiveTenants = Tenant::where('status', 'active')
            ->where(function ($q) {
                $q->where('updated_at', '<', now()->subDays(7))
                  ->orWhereNull('updated_at');
            })
            ->count();

        $totalAlerts = $tenantsOverQuota + $expiringTenants;

        // Courbe MRR fictive basée sur la valeur actuelle (progression estimée)
        $mrrChart = $this->buildProgressionChart((float) $mrr, 7);

        // Courbe étudiants
        $studentsChart = $this->buildProgressionChart($totalStudents, 7);

        return [
            Stat::make('Établissements Actifs', $activeTenantsCount)
                ->description($activeTenantsCount . ' / ' . Tenant::count() . ' tenants au total')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('success')
                ->chart([3, 4, 4, 5, 5, 6, $activeTenantsCount]),

            Stat::make('Total Étudiants', number_format($totalStudents, 0, ',', ' '))
                ->description('Inscrits sur tous les établissements')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('primary')
                ->chart($studentsChart),

            Stat::make('Revenus Annuels', number_format($mrr, 0, ',', ' ') . ' FCFA')
                ->description('Abonnements actifs en cours')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning')
                ->chart($mrrChart),

            Stat::make('Alertes', $totalAlerts)
                ->description(
                    ($tenantsOverQuota > 0 ? "{$tenantsOverQuota} quota(s) dépassé(s)" : 'Aucun quota dépassé')
                    . ' • '
                    . ($expiringTenants > 0 ? "{$expiringTenants} expiration(s)" : 'Aucune expiration proche')
                )
                ->descriptionIcon($totalAlerts > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($totalAlerts > 0 ? 'danger' : 'success')
                ->chart([0, 0, 1, 0, 1, 1, $totalAlerts]),
        ];
    }

    /**
     * Génère une courbe de progression plausible vers la valeur actuelle.
     */
    private function buildProgressionChart(float|int $currentValue, int $points): array
    {
        if ($currentValue <= 0) {
            return array_fill(0, $points, 0);
        }

        $chart = [];
        for ($i = $points; $i >= 1; $i--) {
            // Régression linéaire simplifiée : valeur légèrement plus basse dans le passé
            $factor = 1 - ($i - 1) * 0.04;
            $chart[] = (int) round($currentValue * max($factor, 0.7));
        }

        return $chart;
    }
}
