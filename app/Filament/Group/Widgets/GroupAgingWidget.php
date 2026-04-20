<?php

namespace App\Filament\Group\Widgets;

use App\Services\TenantAggregationService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class GroupAgingWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '180s';

    protected ?string $heading = 'Répartition des impayés par ancienneté';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $group = auth('group')->user()->group;
        $aging = app(TenantAggregationService::class)->getGroupOutstandingAging($group);

        return [
            Stat::make('0 - 30 jours', number_format($aging['0-30']['amount'] ?? 0, 0, ',', ' ') . ' F')
                ->description(($aging['0-30']['count'] ?? 0) . ' dossier' . (($aging['0-30']['count'] ?? 0) > 1 ? 's' : ''))
                ->descriptionIcon('heroicon-o-clock')
                ->color('success'),

            Stat::make('31 - 60 jours', number_format($aging['31-60']['amount'] ?? 0, 0, ',', ' ') . ' F')
                ->description(($aging['31-60']['count'] ?? 0) . ' dossier' . (($aging['31-60']['count'] ?? 0) > 1 ? 's' : '') . ' à surveiller')
                ->descriptionIcon('heroicon-o-bell-alert')
                ->color('warning'),

            Stat::make('61 - 90 jours', number_format($aging['61-90']['amount'] ?? 0, 0, ',', ' ') . ' F')
                ->description(($aging['61-90']['count'] ?? 0) . ' dossier' . (($aging['61-90']['count'] ?? 0) > 1 ? 's' : '') . ' critique' . (($aging['61-90']['count'] ?? 0) > 1 ? 's' : ''))
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('danger'),

            Stat::make('Plus de 90 jours', number_format($aging['90+']['amount'] ?? 0, 0, ',', ' ') . ' F')
                ->description(($aging['90+']['count'] ?? 0) . ' dossier' . (($aging['90+']['count'] ?? 0) > 1 ? 's' : '') . ' à recouvrer urgemment')
                ->descriptionIcon('heroicon-o-fire')
                ->color('danger'),
        ];
    }
}
