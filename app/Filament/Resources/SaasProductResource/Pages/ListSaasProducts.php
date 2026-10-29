<?php

namespace App\Filament\Resources\SaasProductResource\Pages;

use App\Filament\Resources\SaasProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListSaasProducts extends ListRecords
{
    protected static string $resource = SaasProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Ajouter un produit')
                ->visible(fn () => SaasProductResource::canCreate()),
        ];
    }
}
