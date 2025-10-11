<?php

namespace App\Filament\Resources\TenantHealthCheckResource\Pages;

use App\Filament\Resources\TenantHealthCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTenantHealthCheck extends EditRecord
{
    protected static string $resource = TenantHealthCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
