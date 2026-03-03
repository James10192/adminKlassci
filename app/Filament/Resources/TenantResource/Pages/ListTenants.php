<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Tenant;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
