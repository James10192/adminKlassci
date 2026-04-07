<?php

namespace App\Filament\Group\Widgets;

use App\Services\TenantAggregationService;
use Filament\Widgets\ChartWidget;

class EnrollmentWidget extends ChartWidget
{
    // Keep lazy: cross-DB queries can be slow

    protected static ?string $heading = 'Effectifs par établissement';

    protected static ?int $sort = 5;

    protected static ?string $pollingInterval = '300s';

    protected static ?string $maxHeight = '350px';

    protected function getData(): array
    {
        $group = auth('group')->user()->group;
        $service = app(TenantAggregationService::class);
        $kpis = $service->getGroupKpis($group);

        $labels = [];
        $students = [];
        $staff = [];
        $colors = [
            'rgba(4, 83, 203, 0.7)',
            'rgba(16, 185, 129, 0.7)',
            'rgba(245, 158, 11, 0.7)',
            'rgba(139, 92, 246, 0.7)',
            'rgba(236, 72, 153, 0.7)',
            'rgba(14, 165, 233, 0.7)',
        ];

        $i = 0;
        foreach ($kpis['establishments'] ?? [] as $code => $data) {
            $labels[] = $data['tenant_name'];
            $students[] = $data['inscriptions'];
            $staff[] = $data['staff'];
            $i++;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Étudiants inscrits',
                    'data' => $students,
                    'backgroundColor' => array_slice($colors, 0, count($labels)),
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
