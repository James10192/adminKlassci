<?php

namespace App\Filament\Group\Widgets;

use App\Services\TenantAggregationService;
use Filament\Widgets\Widget;

class GroupAlertsWidget extends Widget
{
    protected static ?int $sort = 4;

    protected static string $view = 'filament.group.widgets.group-alerts';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '300s';

    public function getViewData(): array
    {
        $group = auth('group')->user()->group;
        $service = app(TenantAggregationService::class);

        $health = $service->getGroupHealthMetrics($group);

        return [
            'alerts' => array_slice($health['alerts'], 0, 5),
            'totalAlerts' => count($health['alerts']),
            'quotaCriticalCount' => $health['quota_critical_count'],
            'quotaExceededCount' => $health['quota_exceeded_count'],
            'subscriptionExpiringCount' => $health['subscription_expiring_count'],
            'subscriptionExpiredCount' => $health['subscription_expired_count'],
            'activeReliquatsTotal' => $health['active_reliquats_total'],
            'attritionRateAvg' => $health['attrition_rate_avg'],
        ];
    }
}
