<?php

namespace App\Filament\Group\Resources\ReportScheduleResource\Pages;

use App\Filament\Group\Resources\ReportScheduleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReportSchedule extends EditRecord
{
    protected static string $resource = ReportScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Le groupe d'une programmation ne change jamais : on le fige à chaque
     * enregistrement plutôt que de faire confiance à ce qui remonte du
     * formulaire.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['group_id'], $data['created_by']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
