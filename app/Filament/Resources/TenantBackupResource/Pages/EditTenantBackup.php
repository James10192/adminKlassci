<?php

namespace App\Filament\Resources\TenantBackupResource\Pages;

use App\Filament\Resources\TenantBackupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTenantBackup extends EditRecord
{
    protected static string $resource = TenantBackupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
