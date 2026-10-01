<?php

namespace App\Filament\Resources\RetourAssistantResource\Pages;

use App\Domain\Care\Retours\Actions\TraiterRetourAssistant;
use App\Domain\Care\Retours\Actions\TransformerRetourEnDemande;
use App\Domain\Care\Retours\Enums\AvisAssistant;
use App\Domain\Care\Retours\Models\RetourAssistant;
use App\Domain\Care\Tickets\Exceptions\CleIdempotenceReutilisee;
use App\Filament\Resources\RetourAssistantResource;
use App\Filament\Resources\SupportTicketResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

/** Un retour sur Nanan, lu en entier : question, réponse, commentaire. */
class ViewRetourAssistant extends ViewRecord
{
    protected static string $resource = RetourAssistantResource::class;

    public function getTitle(): string
    {
        /** @var RetourAssistant $r */
        $r = $this->record;

        return $r->avis->pictogramme().' Retour de '.$r->utilisateur_nom.' · '.($r->tenant?->code ?? '—');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('traiter')
                ->label('Marquer traité')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => Gate::allows('support.tickets.manage') && ! $this->record->estTraite())
                ->form([
                    Forms\Components\Textarea::make('note')->label('Note interne (facultative)')->rows(4)->maxLength(2000)
                        ->helperText("Ce qui a été fait. Visible de l'équipe seulement."),
                ])
                ->action(function (array $data) {
                    app(TraiterRetourAssistant::class)->executer($this->record, auth()->user(), $data['note'] ?? null);
                    $this->record->refresh();
                    Notification::make()->success()->title('Retour marqué traité.')->send();
                }),

            Actions\Action::make('demande')
                ->label('Créer une demande')
                ->icon('heroicon-o-lifebuoy')
                ->visible(fn () => Gate::allows('support.tickets.manage')
                    && $this->record->avis === AvisAssistant::PasUtile
                    && $this->record->support_ticket_id === null)
                ->requiresConfirmation()
                ->modalDescription("Une demande est ouverte au nom de la personne qui a donné l'avis, avec la question et la réponse. Elle la verra dans ses demandes et recevra votre réponse.")
                ->action(function () {
                    try {
                        $ticket = app(TransformerRetourEnDemande::class)->executer($this->record, auth()->user());
                    } catch (CleIdempotenceReutilisee $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    $this->record->refresh();
                    Notification::make()->success()->title("Demande {$ticket->reference} créée.")->send();
                    $this->redirect(SupportTicketResource::getUrl('view', ['record' => $ticket]));
                }),

            Actions\Action::make('voirDemande')
                ->label('Voir la demande')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn () => $this->record->support_ticket_id !== null)
                ->url(fn () => SupportTicketResource::getUrl('view', ['record' => $this->record->support_ticket_id])),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Le retour')
                ->schema([
                    Infolists\Components\TextEntry::make('avis')->label('Avis')->badge()
                        ->formatStateUsing(fn (AvisAssistant $state) => $state->pictogramme().' '.$state->libelle())
                        ->color(fn (AvisAssistant $state) => $state->ton()),
                    Infolists\Components\TextEntry::make('raison')->label('Raison')->placeholder('—')
                        ->formatStateUsing(fn ($state, RetourAssistant $record) => $record->raisonLibelle()),
                    Infolists\Components\TextEntry::make('tenant.name')->label('École'),
                    Infolists\Components\TextEntry::make('utilisateur_nom')->label('Donné par')
                        ->suffix(fn (RetourAssistant $record) => $record->utilisateur_role ? ' · '.$record->utilisateur_role : ''),
                    Infolists\Components\TextEntry::make('donne_le')->label('Donné le')->dateTime('d/m/Y H:i'),
                    Infolists\Components\TextEntry::make('modele')->label('Modèle')->fontFamily('mono')->placeholder('—'),
                    Infolists\Components\TextEntry::make('page')->label('Page')->fontFamily('mono')->placeholder('—'),
                    Infolists\Components\TextEntry::make('conversation_ref')->label('Conversation / message')->fontFamily('mono')
                        ->formatStateUsing(fn ($state, RetourAssistant $record) => ($state ?? '—').' / '.$record->message_ref)->copyable(),
                    Infolists\Components\TextEntry::make('commentaire')->label('Commentaire')->columnSpanFull()->placeholder('Aucun commentaire'),
                ])->columns(4),

            Infolists\Components\Section::make('Question posée')
                ->schema([Infolists\Components\TextEntry::make('question')->label('')->columnSpanFull()->prose()]),

            Infolists\Components\Section::make('Réponse de Nanan')
                ->schema([Infolists\Components\TextEntry::make('reponse')->label('')->columnSpanFull()->prose()]),

            Infolists\Components\Section::make('Traitement')
                ->schema([
                    Infolists\Components\TextEntry::make('traite_le')->label('Traité le')->dateTime('d/m/Y H:i')->placeholder('Pas encore'),
                    Infolists\Components\TextEntry::make('traitePar.name')->label('Par')->placeholder('—'),
                    Infolists\Components\TextEntry::make('ticket.reference')->label('Demande créée')->placeholder('—')->fontFamily('mono'),
                    Infolists\Components\TextEntry::make('note_interne')->label('Note interne')->columnSpanFull()->placeholder('—'),
                ])->columns(3),
        ]);
    }
}
