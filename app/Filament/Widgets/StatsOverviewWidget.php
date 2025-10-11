<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        // Récupérer tous les tenants actifs
        $activeTenantsCount = Tenant::where('status', 'active')->count();

        // Total étudiants (agrégé de tous les tenants)
        $totalStudents = Tenant::where('status', 'active')->sum('current_students');

        // MRR (Monthly Recurring Revenue) - somme des frais mensuels
        $mrr = Tenant::where('status', 'active')->sum('monthly_fee');

        // Tenants avec dépassement de quotas
        $tenantsOverQuota = Tenant::where('status', 'active')
            ->get()
            ->filter(fn($tenant) => $tenant->isOverQuota())
            ->count();

        // Tenants dont l'abonnement expire dans les 30 prochains jours
        $expiringTenants = Tenant::where('status', 'active')
            ->where('subscription_end_date', '<=', now()->addDays(30))
            ->where('subscription_end_date', '>=', now())
            ->count();

        return [
            Stat::make('Établissements Actifs', $activeTenantsCount)
                ->description('Tenants avec statut actif')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('success')
                ->chart([7, 8, 10, 12, 15, 18, $activeTenantsCount]),

            Stat::make('Total Étudiants', number_format($totalStudents))
                ->description('Étudiants inscrits (tous établissements)')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('primary')
                ->chart([100, 150, 200, 250, 300, 350, $totalStudents]),

            Stat::make('MRR', number_format($mrr) . ' FCFA')
                ->description('Revenu mensuel récurrent')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('warning')
                ->chart([50000, 75000, 100000, 125000, 150000, 175000, $mrr]),

            Stat::make('Alertes', $tenantsOverQuota + $expiringTenants)
                ->description($tenantsOverQuota . ' quotas dépassés • ' . $expiringTenants . ' expirations proches')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($tenantsOverQuota > 0 ? 'danger' : 'success'),
        ];
    }
}
