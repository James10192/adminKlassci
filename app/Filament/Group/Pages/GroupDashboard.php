<?php

namespace App\Filament\Group\Pages;

use App\Services\TenantAggregationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Artisan;

class GroupDashboard extends Dashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $title = 'Tableau de bord';

    protected static string $routePath = '/';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Actualiser')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    $group = auth('group')->user()->group;
                    $service = app(TenantAggregationService::class);
                    $service->refreshGroupCache($group);
                    $service->getGroupKpis($group);

                    Notification::make()
                        ->title('Données actualisées')
                        ->success()
                        ->send();
                }),
            Action::make('check_alerts')
                ->label('Vérifier alertes')
                ->icon('heroicon-o-bell-alert')
                ->color('warning')
                ->action(function () {
                    $group = auth('group')->user()->group;
                    Artisan::call('group:alert-check', ['--group' => $group->code]);

                    Notification::make()
                        ->title('Alertes vérifiées')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\Group\Widgets\GroupWelcomeWidget::class,
            \App\Filament\Group\Widgets\KpiOverviewWidget::class,
            \App\Filament\Group\Widgets\GroupAlertsWidget::class,
            \App\Filament\Group\Widgets\GroupAgingWidget::class,
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
