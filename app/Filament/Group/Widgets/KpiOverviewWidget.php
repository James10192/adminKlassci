<?php

namespace App\Filament\Group\Widgets;

use App\Services\TenantAggregationService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KpiOverviewWidget extends StatsOverviewWidget
{
    // Lazy loading requis : les connexions cross-DB tenant peuvent être lentes

    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '300s';

    protected function getStats(): array
    {
        $group = auth('group')->user()->group;
        $service = app(TenantAggregationService::class);
        $kpis = $service->getGroupKpis($group);

        $collectionColor = $kpis['collection_rate'] >= 70 ? 'success' : ($kpis['collection_rate'] >= 50 ? 'warning' : 'danger');

        return [
            Stat::make('Établissements actifs', $kpis['establishment_count'])
                ->description('dans le groupe')
                ->descriptionIcon('heroicon-o-building-office-2')
                ->color('primary')
                ->chart([1, 1, 1, 1, $kpis['establishment_count']]),

            Stat::make('Étudiants inscrits', number_format($kpis['total_inscriptions'], 0, ',', ' '))
                ->description("{$kpis['total_students']} étudiants uniques")
                ->descriptionIcon('heroicon-o-academic-cap')
                ->color('success'),

            Stat::make('Revenus encaissés', number_format($kpis['total_revenue_collected'], 0, ',', ' ') . ' F')
                ->description(number_format($kpis['total_revenue_expected'], 0, ',', ' ') . ' F attendus')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color($collectionColor),

            Stat::make('Recouvrement', $kpis['collection_rate'] . '%')
                ->description($kpis['has_surplus'] ?? false ? 'surplus collecté' : 'sur l\'ensemble du groupe')
                ->descriptionIcon('heroicon-o-chart-bar')
                ->color($collectionColor),

            Stat::make('Personnel total', $kpis['total_staff'])
                ->description('enseignants & admin')
                ->descriptionIcon('heroicon-o-user-group')
                ->color('info'),
        ];
    }
}
