<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Les chiffres du groupe, sans courbe.
 *
 * Ces tuiles portaient des sparklines fabriquées : une série codée en dur
 * (3, 4, 4, 5, 5, 6, valeur) ou reconstituée en partant de 70 % du chiffre du
 * jour. Elles montaient toujours, quoi qu'il arrive dans les établissements.
 * Un fondateur qui lit « Revenus annuels » sous une courbe ascendante en
 * conclut que le revenu monte — c'est un graphique qui ment, pas une
 * décoration.
 *
 * La base maîtresse ne garde aucun historique de ces valeurs : `tenants` ne
 * stocke que l'état courant. Une vraie tendance demande un relevé quotidien,
 * qui n'existe pas encore. En attendant, pas de courbe : un chiffre nu est
 * moins beau et infiniment plus honnête.
 */
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

        $totalAlerts = $tenantsOverQuota + $expiringTenants;

        return [
            Stat::make('Établissements Actifs', $activeTenantsCount)
                ->description($activeTenantsCount . ' / ' . Tenant::count() . ' tenants au total')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('success'),

            Stat::make('Total Étudiants', number_format($totalStudents, 0, ',', ' '))
                ->description('Inscrits sur tous les établissements')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('primary'),

            Stat::make('Revenus Annuels', number_format($mrr, 0, ',', ' ') . ' FCFA')
                ->description('Abonnements actifs en cours')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),

            Stat::make('Alertes', $totalAlerts)
                ->description(
                    ($tenantsOverQuota > 0 ? "{$tenantsOverQuota} quota(s) dépassé(s)" : 'Aucun quota dépassé')
                    . ' • '
                    . ($expiringTenants > 0 ? "{$expiringTenants} expiration(s)" : 'Aucune expiration proche')
                )
                ->descriptionIcon($totalAlerts > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($totalAlerts > 0 ? 'danger' : 'success'),
        ];
    }
}
