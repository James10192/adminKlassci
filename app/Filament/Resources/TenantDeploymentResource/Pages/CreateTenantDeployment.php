<?php

namespace App\Filament\Resources\TenantDeploymentResource\Pages;

use App\Filament\Resources\TenantDeploymentResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantDeployment extends CreateRecord
{
    protected static string $resource = TenantDeploymentResource::class;
}
