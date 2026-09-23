<?php

namespace App\Filament\Resources\SupportTicketResource\Pages;

use App\Domain\Care\Tickets\Enums\StatutTicket as S;
use App\Filament\Resources\SupportTicketResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSupportTickets extends ListRecords
{
    protected static string $resource = SupportTicketResource::class;

    public function getTabs(): array
    {
        return [
            'a_trier' => Tab::make('À trier')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', S::TriagePending->value)),
            'mes' => Tab::make('Mes demandes')
                ->modifyQueryUsing(fn (Builder $query) => $query->ouverts()->where('assigned_admin_id', auth()->id())),
            'attente_support' => Tab::make('Attente support')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [S::WaitingSupport->value, S::Triaged->value, S::Confirmed->value])),
            'attente_client' => Tab::make('Attente client')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', S::WaitingCustomer->value)),
            'ouvertes' => Tab::make('Ouvertes')
                ->modifyQueryUsing(fn (Builder $query) => $query->ouverts()),
            'toutes' => Tab::make('Toutes'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'a_trier';
    }
}
