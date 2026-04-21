<?php

namespace App\Filament\Group\Pages;

use App\Filament\Group\Concerns\HasCustomHero;
use App\Services\TenantAggregationService;
use Filament\Pages\Page;

class Benchmarking extends Page
{
    use HasCustomHero;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Benchmarking';

    protected static ?string $navigationGroup = 'Analytiques';

    protected static ?string $title = 'Benchmarking';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.group.pages.benchmarking';

    public function getComparisonData(): array
    {
        $group = auth('group')->user()->group;
        $service = app(TenantAggregationService::class);
        $kpis = $service->getGroupKpis($group);

        return $kpis['establishments'] ?? [];
    }

    public function getEnrollmentData(): array
    {
        $group = auth('group')->user()->group;
        $service = app(TenantAggregationService::class);

        return $service->getGroupEnrollment($group);
    }
}
