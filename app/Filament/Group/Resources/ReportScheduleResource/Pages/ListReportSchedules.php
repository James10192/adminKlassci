<?php

namespace App\Filament\Group\Resources\ReportScheduleResource\Pages;

use App\Filament\Group\Resources\ReportScheduleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReportSchedules extends ListRecords
{
    protected static string $resource = ReportScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Programmer un envoi'),
        ];
    }
}
