<?php

namespace App\Filament\Resources\TenantDeploymentResource\Pages;

use App\Filament\Resources\TenantDeploymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewTenantDeployment extends ViewRecord
{
    protected static string $resource = TenantDeploymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
