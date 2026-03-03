<?php

namespace App\Filament\Resources\SaasAdminResource\Pages;

use App\Filament\Resources\SaasAdminResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSaasAdmin extends CreateRecord
{
    protected static string $resource = SaasAdminResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
