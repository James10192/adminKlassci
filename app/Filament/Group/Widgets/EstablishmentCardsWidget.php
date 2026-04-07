<?php

namespace App\Filament\Group\Widgets;

use App\Services\TenantAggregationService;
use Filament\Widgets\Widget;

class EstablishmentCardsWidget extends Widget
{
    // Keep lazy: cross-DB queries can be slow

    protected static string $view = 'filament.group.widgets.establishment-cards';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '300s';

    public function getEstablishments(): array
    {
        $group = auth('group')->user()->group;
        $service = app(TenantAggregationService::class);
        $kpis = $service->getGroupKpis($group);

        return $kpis['establishments'] ?? [];
    }
}
