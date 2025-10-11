<?php

namespace App\Filament\Resources\TenantHealthCheckResource\Pages;

use App\Filament\Resources\TenantHealthCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewTenantHealthCheck extends ViewRecord
{
    protected static string $resource = TenantHealthCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
