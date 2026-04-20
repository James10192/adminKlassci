<?php

namespace App\Filament\Group\Widgets;

use App\Filament\Group\Concerns\PeriodAwareConcern;
use App\Services\TenantAggregationService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Str;

class GroupAgingWidget extends StatsOverviewWidget
{
    use PeriodAwareConcern;

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
        $aging = app(TenantAggregationService::class)
            ->getGroupOutstandingAging($group, $this->currentPeriod());

        // `pluralize_qualifier` only true when the suffix is an adjective (critique → critiques).
        // Invariant suffixes like "à surveiller" / "à recouvrer urgemment" keep the same form.
        $buckets = [
            ['key' => '0-30',  'label' => '0 - 30 jours',     'color' => 'success', 'icon' => 'heroicon-o-clock',                'qualifier' => null,                     'pluralize_qualifier' => false],
            ['key' => '31-60', 'label' => '31 - 60 jours',    'color' => 'warning', 'icon' => 'heroicon-o-bell-alert',           'qualifier' => 'à surveiller',           'pluralize_qualifier' => false],
            ['key' => '61-90', 'label' => '61 - 90 jours',    'color' => 'danger',  'icon' => 'heroicon-o-exclamation-triangle', 'qualifier' => 'critique',               'pluralize_qualifier' => true],
            ['key' => '90+',   'label' => 'Plus de 90 jours', 'color' => 'danger',  'icon' => 'heroicon-o-fire',                 'qualifier' => 'à recouvrer urgemment',  'pluralize_qualifier' => false],
        ];

        return array_map(function (array $b) use ($aging) {
            $count = $aging[$b['key']]['count'] ?? 0;
            $amount = $aging[$b['key']]['amount'] ?? 0;
            $description = $count . ' ' . Str::plural('dossier', $count);

            if ($b['qualifier']) {
                $suffix = $b['qualifier'] . ($b['pluralize_qualifier'] && $count > 1 ? 's' : '');
                $description .= ' ' . $suffix;
            }

            return Stat::make($b['label'], number_format($amount, 0, ',', ' ') . ' F')
                ->description($description)
                ->descriptionIcon($b['icon'])
                ->color($b['color']);
        }, $buckets);
    }
}
