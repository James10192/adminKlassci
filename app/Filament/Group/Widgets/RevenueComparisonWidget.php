<?php

namespace App\Filament\Group\Widgets;

use App\Services\TenantAggregationService;
use Filament\Widgets\ChartWidget;

class RevenueComparisonWidget extends ChartWidget
{
    // Keep lazy: cross-DB queries can be slow

    protected static ?string $heading = 'Revenus par établissement';

    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '300s';

    protected static ?string $maxHeight = '350px';

    protected function getData(): array
    {
        $group = auth('group')->user()->group;
        $service = app(TenantAggregationService::class);
        $financials = $service->getGroupFinancials($group);

        $labels = [];
        $expected = [];
        $collected = [];

        foreach ($financials as $code => $data) {
            $labels[] = $data['tenant_name'];
            $expected[] = round($data['revenue_expected'] / 1000000, 1); // En millions
            $collected[] = round($data['revenue_collected'] / 1000000, 1);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Attendu (M FCFA)',
                    'data' => $expected,
                    'backgroundColor' => 'rgba(4, 83, 203, 0.2)',
                    'borderColor' => 'rgba(4, 83, 203, 1)',
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Encaissé (M FCFA)',
                    'data' => $collected,
                    'backgroundColor' => 'rgba(16, 185, 129, 0.2)',
                    'borderColor' => 'rgba(16, 185, 129, 1)',
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
