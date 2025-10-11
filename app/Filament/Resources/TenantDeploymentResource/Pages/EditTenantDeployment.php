<?php

namespace App\Filament\Resources\TenantDeploymentResource\Pages;

use App\Filament\Resources\TenantDeploymentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTenantDeployment extends EditRecord
{
    protected static string $resource = TenantDeploymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
