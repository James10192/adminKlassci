<?php

namespace App\Filament\Group\Widgets;

use App\Services\TenantAggregationService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KpiOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '300s';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $group = auth('group')->user()->group;
        $service = app(TenantAggregationService::class);

        $kpis = $service->getGroupKpis($group);
        $trends = $service->getGroupTrends($group);
        $aging = $service->getGroupOutstandingAging($group);

        // Delta MoM revenus
        $revenueDelta = $trends['revenue_mom']['delta_pct'] ?? 0;
        $revenueDeltaStr = ($revenueDelta > 0 ? '+' : '') . $revenueDelta . '%';
        $revenueIcon = match (true) {
            $revenueDelta > 5 => 'heroicon-o-arrow-trending-up',
            $revenueDelta < -5 => 'heroicon-o-arrow-trending-down',
            default => 'heroicon-o-minus-small',
        };
        $revenueColor = match (true) {
            $revenueDelta > 0 => 'success',
            $revenueDelta < -15 => 'danger',
            $revenueDelta < 0 => 'warning',
            default => 'gray',
        };

        // Delta YoY inscriptions
        $inscDelta = $trends['inscriptions_yoy']['delta_pct'] ?? 0;
        $inscDeltaStr = ($inscDelta > 0 ? '+' : '') . $inscDelta . '%';
        $inscIcon = match (true) {
            $inscDelta > 5 => 'heroicon-o-arrow-trending-up',
            $inscDelta < -5 => 'heroicon-o-arrow-trending-down',
            default => 'heroicon-o-minus-small',
        };

        // Couleur recouvrement
        $collectionRate = $kpis['collection_rate'] ?? 0;
        $collectionColor = $collectionRate >= 70 ? 'success' : ($collectionRate >= 50 ? 'warning' : 'danger');

        // Impayés > 30j cross-groupe (cumul 31-60 + 61-90 + 90+)
        $impayes30j = ($aging['31-60']['amount'] ?? 0) + ($aging['61-90']['amount'] ?? 0) + ($aging['90+']['amount'] ?? 0);
        $impayes30jCount = ($aging['31-60']['count'] ?? 0) + ($aging['61-90']['count'] ?? 0) + ($aging['90+']['count'] ?? 0);

        // Attendance
        $attendance = $kpis['avg_attendance_rate'] ?? 0;
        $attendanceColor = $attendance >= 85 ? 'success' : ($attendance >= 70 ? 'warning' : ($attendance > 0 ? 'danger' : 'gray'));

        return [
            Stat::make('Étudiants inscrits', number_format($kpis['total_students'], 0, ',', ' '))
                ->description($inscDeltaStr . ' vs année précédente')
                ->descriptionIcon($inscIcon)
                ->color($inscDelta >= 0 ? 'success' : 'warning'),

            Stat::make('Encaissés ce mois', number_format($trends['revenue_mom']['current'] ?? 0, 0, ',', ' ') . ' F')
                ->description($revenueDeltaStr . ' vs mois dernier')
                ->descriptionIcon($revenueIcon)
                ->color($revenueColor),

            Stat::make('Taux de recouvrement', $collectionRate . '%')
                ->description(number_format($kpis['total_revenue_collected'] ?? 0, 0, ',', ' ') . ' F sur ' . number_format($kpis['total_revenue_expected'] ?? 0, 0, ',', ' ') . ' F')
                ->descriptionIcon('heroicon-o-chart-bar')
                ->color($collectionColor),

            Stat::make('Impayés > 30 jours', number_format($impayes30j, 0, ',', ' ') . ' F')
                ->description($impayes30jCount . ' dossier' . ($impayes30jCount > 1 ? 's' : '') . ' à relancer')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($impayes30j > 0 ? 'danger' : 'success'),

            Stat::make('Taux de présence', $attendance . '%')
                ->description($attendance > 0 ? 'moyenne pondérée groupe' : 'aucune donnée')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color($attendanceColor),

            Stat::make('Établissements', $kpis['establishment_count'] ?? 0)
                ->description($kpis['total_staff'] . ' membres du personnel')
                ->descriptionIcon('heroicon-o-building-office-2')
                ->color('primary'),
        ];
    }
}
