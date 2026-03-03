<?php

namespace App\Filament\Resources\SaasAdminResource\Pages;

use App\Filament\Resources\SaasAdminResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSaasAdmin extends EditRecord
{
    protected static string $resource = SaasAdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->hidden(fn (): bool => $this->record->id === auth()->id()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
