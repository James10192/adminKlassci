<?php

namespace App\Filament\Group\Resources\ReportScheduleResource\Pages;

use App\Filament\Group\Resources\ReportScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReportSchedule extends CreateRecord
{
    protected static string $resource = ReportScheduleResource::class;

    /**
     * Le groupe et l'auteur sont posés côté serveur, jamais lus du formulaire :
     * un champ caché suffirait à programmer l'envoi des états d'un autre groupe.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $membre = auth('group')->user();

        $data['group_id'] = $membre?->group_id;
        $data['created_by'] = $membre?->id;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
