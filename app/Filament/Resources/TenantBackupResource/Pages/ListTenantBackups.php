<?php

namespace App\Filament\Resources\TenantBackupResource\Pages;

use App\Filament\Resources\TenantBackupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTenantBackups extends ListRecords
{
    protected static string $resource = TenantBackupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
