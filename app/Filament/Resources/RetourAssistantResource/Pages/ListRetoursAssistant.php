<?php

namespace App\Filament\Resources\RetourAssistantResource\Pages;

use App\Domain\Care\Retours\Enums\AvisAssistant;
use App\Filament\Resources\RetourAssistantResource;
use App\Filament\Resources\RetourAssistantResource\Widgets\SatisfactionNanan;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListRetoursAssistant extends ListRecords
{
    protected static string $resource = RetourAssistantResource::class;

    protected function getHeaderWidgets(): array
    {
        return [SatisfactionNanan::class];
    }

    public function getTabs(): array
    {
        return [
            'a_lire' => Tab::make('👎 à lire')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('avis', AvisAssistant::PasUtile->value)->whereNull('traite_le')),
            'commentes' => Tab::make('Avec commentaire')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('commentaire')),
            'tous' => Tab::make('Tous'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'a_lire';
    }
}
