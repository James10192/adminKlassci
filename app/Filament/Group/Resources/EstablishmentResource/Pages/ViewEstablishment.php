<?php

namespace App\Filament\Group\Resources\EstablishmentResource\Pages;

use App\Filament\Group\Resources\EstablishmentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEstablishment extends ViewRecord
{
    protected static string $resource = EstablishmentResource::class;

    protected static ?string $title = 'Détail Établissement';
}
