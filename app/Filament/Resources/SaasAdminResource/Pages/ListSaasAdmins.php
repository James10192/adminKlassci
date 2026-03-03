<?php

namespace App\Filament\Resources\SaasAdminResource\Pages;

use App\Filament\Resources\SaasAdminResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSaasAdmins extends ListRecords
{
    protected static string $resource = SaasAdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Ajouter un admin')
                ->icon('heroicon-o-user-plus'),
        ];
    }
}
