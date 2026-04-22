<?php

namespace App\Filament\Group\Pages;

use App\Filament\Group\Concerns\HasCustomHero;
use App\Services\TenantAggregationService;
use Filament\Pages\Page;

/**
 * Full-page view of every alert the group portal surfaces, complementing the
 * `GroupAlertsWidget` which shows only the top 5 on the dashboard. Filtering
 * is client-side (Alpine) since the alerts payload caps at a few dozen rows
 * in practice — cross-tenant health metrics for 20 tenants = ~40 alerts max.
 */
class AlertsIndex extends Page
{
    use HasCustomHero;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Alertes';

    protected static ?string $navigationGroup = 'Analytiques';

    protected static ?string $title = 'Toutes les alertes';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.group.pages.alerts-index';

    protected static ?string $slug = 'alertes';

    /**
     * Poll every 5 min to match `TenantAggregationService::CACHE_TTL_HEALTH`.
     * Polling more often just re-renders the same cached payload.
     */
    protected ?string $pollingInterval = '300s';

    public function getAlerts(): array
    {
        $health = $this->getHealth();
        $alerts = $health['alerts'] ?? [];
        $dismissed = session('gp_alerts_acknowledged', []);

        // Stamp each alert with a client-stable fingerprint so the template
        // can filter acknowledged ones without a server round-trip. Same
        // tenant+type combo is considered the "same" alert across polls —
        // message text changes (e.g. days remaining) don't break the ack.
        return array_map(function (array $alert) use ($dismissed) {
            $fingerprint = $this->fingerprintOf($alert);
            $alert['fingerprint'] = $fingerprint;
            $alert['acknowledged'] = isset($dismissed[$fingerprint])
                && now()->lessThan($dismissed[$fingerprint]);

            return $alert;
        }, $alerts);
    }

    public function getStats(): array
    {
        $alerts = $this->getAlerts();

        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        $tenantsSeen = [];
        $activeCount = 0;

        foreach ($alerts as $alert) {
            if ($alert['acknowledged']) {
                continue;
            }
            $activeCount++;
            $tenantsSeen[$alert['tenant_code']] = true;
            $counts[$alert['severity']]++;
        }

        return [
            'total_active' => $activeCount,
            'total_all' => count($alerts),
            'critical' => $counts['critical'],
            'warning' => $counts['warning'],
            'info' => $counts['info'],
            'tenants_affected' => count($tenantsSeen),
        ];
    }

    private function getHealth(): array
    {
        $group = auth('group')->user()->group;

        return app(TenantAggregationService::class)->getGroupHealthMetrics($group);
    }

    private function fingerprintOf(array $alert): string
    {
        return md5(($alert['tenant_code'] ?? '') . '|' . ($alert['type'] ?? ''));
    }
}
