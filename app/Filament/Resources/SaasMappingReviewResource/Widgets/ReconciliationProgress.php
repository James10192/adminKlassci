<?php

namespace App\Filament\Resources\SaasMappingReviewResource\Widgets;

use App\Models\SaasMappingReviewRecord;
use App\Models\Tenant;
use App\Services\Saas\SaasCapabilities;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

final class ReconciliationProgress extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        if (! Gate::allows(SaasCapabilities::VIEW)) {
            return [];
        }

        $statuses = SaasMappingReviewRecord::query()
            ->selectRaw('state, COUNT(*) as n')
            ->groupBy('state')->pluck('n', 'state');
        $legacyCount = Tenant::query()->count();
        $reviewCount = SaasMappingReviewRecord::query()->count();

        return [
            Stat::make('Instances à qualifier', max(0, $legacyCount - $reviewCount))
                ->description('Tenants existants, hors archives ; pas forcément des écoles')
                ->descriptionIcon('heroicon-o-magnifying-glass')
                ->color('gray'),
            Stat::make('Dossiers en validation', (int) ($statuses['submitted'] ?? 0))
                ->description('Second contrôleur requis')
                ->descriptionIcon('heroicon-o-shield-check')
                ->color('warning'),
            Stat::make('Qualifiés sans import', (int) ($statuses['approved'] ?? 0))
                ->description('Classification approuvée ; aucune activation')
                ->descriptionIcon('heroicon-o-check-badge')
                ->color('success'),
        ];
    }
}
