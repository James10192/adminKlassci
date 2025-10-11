<?php

namespace App\Filament\Resources\TenantBackupResource\Pages;

use App\Filament\Resources\TenantBackupResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantBackup extends CreateRecord
{
    protected static string $resource = TenantBackupResource::class;
}
