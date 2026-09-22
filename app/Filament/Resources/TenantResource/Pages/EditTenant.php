<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * Voir TenantResource::secretsPourFormulaire() : $hidden les retire du remplissage.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return TenantResource::secretsPourFormulaire($this->getRecord(), $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
