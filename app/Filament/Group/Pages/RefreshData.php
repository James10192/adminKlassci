<?php

namespace App\Filament\Group\Pages;

use App\Services\TenantAggregationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class RefreshData extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Actualiser les données';

    protected static ?string $title = 'Actualiser les données';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.group.pages.refresh-data';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Actualiser maintenant')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Actualiser les données')
                ->modalDescription('Cette action va rafraîchir le cache et re-interroger toutes les bases de données des établissements. Cela peut prendre quelques secondes.')
                ->action(function () {
                    $group = auth('group')->user()->group;
                    $service = app(TenantAggregationService::class);
                    $service->refreshGroupCache($group);

                    // Force recompute
                    $service->getGroupKpis($group);

                    Notification::make()
                        ->title('Données actualisées')
                        ->body('Les KPIs de tous vos établissements ont été rafraîchis.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
