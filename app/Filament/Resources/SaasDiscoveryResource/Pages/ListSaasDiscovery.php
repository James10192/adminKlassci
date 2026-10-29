<?php

namespace App\Filament\Resources\SaasDiscoveryResource\Pages;

use App\Filament\Resources\SaasDiscoveryResource;
use App\Models\Tenant;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

final class ListSaasDiscovery extends ListRecords
{
    protected static string $resource = SaasDiscoveryResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('À qualifier')
                ->badge(Tenant::query()->whereDoesntHave('mappingReview')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereNull('deleted_at')->whereDoesntHave('mappingReview')),
            'in_progress' => Tab::make('En traitement')
                ->badge(Tenant::query()->whereHas('mappingReview',
                    fn (Builder $q) => $q->whereIn('state', ['draft', 'submitted', 'rejected']))->count())
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereNull('deleted_at')->whereHas('mappingReview',
                        fn (Builder $q) => $q->whereIn('state', ['draft', 'submitted', 'rejected']))),
            'approved' => Tab::make('Qualifiées')
                ->badge(Tenant::query()->whereHas('mappingReview',
                    fn (Builder $q) => $q->where('state', 'approved'))->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereNull('deleted_at')->whereHas('mappingReview',
                        fn (Builder $q) => $q->where('state', 'approved'))),
            'archived' => Tab::make('Archivées')
                ->badge(Tenant::onlyTrashed()->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('deleted_at')),
        ];
    }
}
