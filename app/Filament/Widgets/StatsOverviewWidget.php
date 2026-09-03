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
 *
 * La couleur, elle, ne décore pas non plus. « Établissements actifs » était
 * vert et « Revenus annuels » orange, sans que ni l'un ni l'autre ne dise
 * quoi que ce soit sur un état : le bandeau posait alors un filet d'alerte
 * au-dessus d'un chiffre parfaitement sain. Seule la tuile des alertes porte
 * désormais une couleur, et elle la mérite.
 */
class StatsOverviewWidget extends BaseWidget
{
    // Deux bandeaux titres valent mieux que huit tuiles anonymes : le premier
    // dit ce que le parc EST, le second ce qu on en SAIT.
    protected ?string $heading = 'Le parc';

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
            Stat::make('Établissements actifs', $activeTenantsCount)
                ->description($activeTenantsCount . ' sur ' . Tenant::count() . ' au total')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('gray'),

            Stat::make('Étudiants', number_format($totalStudents, 0, ',', ' '))
                ->description('Inscrits, tous établissements confondus')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('gray'),

            // L'unité descend dans la description : « 700 000 FCFA » passait à
            // la ligne au milieu du bandeau, et un montant coupé en deux se lit
            // deux fois.
            Stat::make('Revenus annuels', number_format($mrr, 0, ',', ' '))
                ->description('FCFA · abonnements en cours')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('gray'),

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
