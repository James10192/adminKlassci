<?php

namespace App\Filament\Resources\TenantHealthCheckResource\Pages;

use App\Filament\Resources\TenantHealthCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTenantHealthChecks extends ManageRecords
{
    protected static string $resource = TenantHealthCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
