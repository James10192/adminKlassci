<?php

namespace App\Filament\Resources\SaasProductResource\Pages;

use App\Filament\Resources\SaasProductResource;
use Filament\Resources\Pages\EditRecord;

final class EditSaasProduct extends EditRecord
{
    protected static string $resource = SaasProductResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
