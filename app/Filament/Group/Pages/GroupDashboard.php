<?php

namespace App\Filament\Group\Pages;

use Filament\Pages\Dashboard;

class GroupDashboard extends Dashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $title = 'Tableau de bord';

    protected static string $routePath = '/';

    public function getWidgets(): array
    {
        return [
            \App\Filament\Group\Widgets\GroupWelcomeWidget::class,
            \App\Filament\Group\Widgets\KpiOverviewWidget::class,
            \App\Filament\Group\Widgets\EstablishmentCardsWidget::class,
            \App\Filament\Group\Widgets\RevenueComparisonWidget::class,
            \App\Filament\Group\Widgets\EnrollmentWidget::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
