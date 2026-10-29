<?php

namespace App\Filament\Resources\GroupResource\Pages;

use App\Filament\Resources\GroupResource;
use App\Filament\Resources\GroupResource\Actions\GroupWorkspaceActions;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGroup extends EditRecord
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GroupWorkspaceActions::associateTenant($this->getRecord()),
            GroupWorkspaceActions::addMember($this->getRecord()),
            $this->getSaveFormAction()->formId('form'),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->requiresConfirmation(),
        ];
    }
}
