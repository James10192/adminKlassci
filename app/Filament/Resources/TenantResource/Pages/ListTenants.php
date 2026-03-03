<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Tenant;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;

class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('discover_tenants')
                ->label('Actualiser les tenants')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Scanner les tenants sur disque')
                ->modalDescription(
                    'Cette action va scanner le répertoire de production et importer automatiquement '
                    . 'tout tenant présent sur disque mais absent en base de données. '
                    . 'Les tenants déjà connus ne seront pas modifiés.'
                )
                ->modalSubmitActionLabel('Lancer le scan')
                ->action(function () {
                    $exitCode = Artisan::call('tenant:discover');
                    $output   = Artisan::output();

                    // Extraire le résumé depuis la sortie console
                    $imported     = 0;
                    $alreadyKnown = 0;
                    $skipped      = 0;

                    if (preg_match('/Importés\s*\|\s*(\d+)/', $output, $m)) {
                        $imported = (int) $m[1];
                    }
                    if (preg_match('/Déjà connus\s*\|\s*(\d+)/', $output, $m)) {
                        $alreadyKnown = (int) $m[1];
                    }
                    if (preg_match('/Ignorés.*\|\s*(\d+)/', $output, $m)) {
                        $skipped = (int) $m[1];
                    }

                    if ($exitCode !== 0) {
                        Notification::make()
                            ->title('Erreur lors du scan')
                            ->body('Impossible de scanner les tenants. Vérifiez PRODUCTION_PATH dans le .env.')
                            ->danger()
                            ->send();
                        return;
                    }

                    if ($imported > 0) {
                        Notification::make()
                            ->title("{$imported} nouveau(x) tenant(s) importé(s)")
                            ->body(
                                "{$imported} importé(s) · {$alreadyKnown} déjà connus · {$skipped} ignoré(s). "
                                . "La liste a été mise à jour."
                            )
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Aucun nouveau tenant détecté')
                            ->body(
                                "{$alreadyKnown} tenant(s) déjà connus · {$skipped} ignoré(s). "
                                . "Tous les établissements déployés sont déjà enregistrés."
                            )
                            ->info()
                            ->send();
                    }
                }),

            Actions\CreateAction::make()
                ->label('Nouvel établissement')
                ->icon('heroicon-o-plus-circle'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tous')
                ->badge(Tenant::count()),

            'active' => Tab::make('Actifs')
                ->badge(Tenant::where('status', 'active')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'active')),

            'suspended' => Tab::make('Suspendus')
                ->badge(Tenant::where('status', 'suspended')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'suspended')),

            'expiring' => Tab::make('Expirant bientôt')
                ->badge(Tenant::where('status', 'active')
                    ->where('subscription_end_date', '<=', now()->addDays(30))
                    ->where('subscription_end_date', '>=', now())
                    ->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', 'active')
                    ->where('subscription_end_date', '<=', now()->addDays(30))
                    ->where('subscription_end_date', '>=', now())
                ),
        ];
    }
}
