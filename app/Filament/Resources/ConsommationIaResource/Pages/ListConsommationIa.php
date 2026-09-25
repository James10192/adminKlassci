<?php

namespace App\Filament\Resources\ConsommationIaResource\Pages;

use App\Filament\Resources\ConsommationIaResource;
use Filament\Resources\Pages\ListRecords;

class ListConsommationIa extends ListRecords
{
    protected static string $resource = ConsommationIaResource::class;

    protected function getHeaderWidgets(): array
    {
        return [\App\Filament\Resources\ConsommationIaResource\Widgets\ConsommationIaParEcole::class];
    }
}
