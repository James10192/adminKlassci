<?php

namespace App\Filament\Resources\TenantActivityLogResource\Pages;

use App\Filament\Resources\TenantActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListTenantActivityLogs extends ListRecords
{
    protected static string $resource = TenantActivityLogResource::class;
}
