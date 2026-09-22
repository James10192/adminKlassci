<?php

namespace App\Filament\Resources\SupportTicketResource\Pages;

use App\Domain\Care\Tickets\Actions\AssignerTicket;
use App\Domain\Care\Tickets\Actions\ClasserTicket;
use App\Domain\Care\Tickets\Actions\RepondreTicket;
use App\Domain\Care\Tickets\Actions\RestreindreTicket;
use App\Domain\Care\Tickets\Enums\CategorieInterne;
use App\Domain\Care\Tickets\Enums\Priorite;
use App\Domain\Care\Tickets\Enums\Severite;
use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Domain\Care\Tickets\Enums\VisibiliteMessage;
use App\Domain\Care\Tickets\Exceptions\TransitionRefusee;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\TicketStateMachine;
use App\Filament\Resources\SupportTicketResource;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

/**
 * Le dossier d'une demande : ce que l'ecole a ecrit, le contexte capte, la
 * conversation et le journal. Toutes les actions passent par le domaine.
 */
class ViewSupportTicket extends ViewRecord
{
    protected static string $resource = SupportTicketResource::class;

    public function getTitle(): string
    {
        return $this->record->reference.' — '.$this->record->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->actionPrendre(),
            $this->actionRepondre(),
            $this->actionStatut(),
            $this->actionQualifier(),
            $this->actionAssigner(),
            $this->actionRestreindre(),
        ];
    }

    private function actionPrendre(): Actions\Action
    {
        return Actions\Action::make('prendre')
            ->label('Prendre en charge')
            ->icon('heroicon-o-hand-raised')
            ->visible(fn () => Gate::allows('support.tickets.manage') && $this->record->assigned_admin_id !== auth()->id())
            ->action(function () {
                app(AssignerTicket::class)->executer($this->record, auth()->user(), auth()->user());
                Notification::make()->success()->title('Demande assignée à vous.')->send();
            });
    }

    private function actionRepondre(): Actions\Action
    {
        return Actions\Action::make('repondre')
            ->label('Répondre')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('primary')
            ->visible(fn () => Gate::allows('support.tickets.reply') || Gate::allows('support.internal_notes'))
            ->form([
                // Pas de valeur par defaut : choisir qui lira ce message est un geste explicite.
                Forms\Components\ToggleButtons::make('visibilite')
                    ->label('Qui verra ce message ?')
                    ->required()
                    ->inline()
                    ->options(fn () => collect($this->visibilitesAutorisees())->mapWithKeys(fn ($v) => [$v->value => $v->libelle()]))
                    ->colors([VisibiliteMessage::PublicClient->value => 'warning']),
                Forms\Components\Textarea::make('corps')->label('Message')->required()->rows(6)->maxLength(5000),
            ])
            ->action(function (array $data) {
                $visibilite = VisibiliteMessage::from($data['visibilite']);
                abort_unless(in_array($visibilite, $this->visibilitesAutorisees(), true), 403);
                app(RepondreTicket::class)->executer($this->record, auth()->user(), $data['corps'], $visibilite);
                Notification::make()->success()->title($visibilite === VisibiliteMessage::PublicClient
                    ? "Réponse envoyée à l'école." : 'Note enregistrée.')->send();
            });
    }

    private function actionStatut(): Actions\Action
    {
        return Actions\Action::make('statut')
            ->label('Changer le statut')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn () => Gate::allows('support.tickets.manage'))
            ->form([
                Forms\Components\Select::make('vers')
                    ->label('Nouveau statut')
                    ->required()
                    ->options(fn () => collect(app(TicketStateMachine::class)->suivants($this->record->status))
                        ->mapWithKeys(fn (StatutTicket $s) => [$s->value => $s->libelle()])),
                Forms\Components\Textarea::make('motif')
                    ->label('Motif')
                    ->helperText('Obligatoire ('.TicketStateMachine::motifMin().' caractères minimum) pour un rejet ou un doublon.')
                    ->rows(3),
            ])
            ->action(fn (array $data) => $this->tenter(fn () => app(TicketStateMachine::class)->franchir(
                $this->record, StatutTicket::from($data['vers']), Acteur::personnel(auth()->user()), $data['motif'] ?? null,
            ), 'Statut mis à jour.'));
    }

    private function actionQualifier(): Actions\Action
    {
        return Actions\Action::make('qualifier')
            ->label('Qualifier')
            ->icon('heroicon-o-tag')
            ->visible(fn () => Gate::allows('support.tickets.manage'))
            ->fillForm(fn () => [
                'categorie' => $this->record->internal_category?->value,
                'severite' => $this->record->severity?->value,
                'priorite' => $this->record->priority?->value,
                'domaine' => $this->record->product_area,
            ])
            ->form([
                Forms\Components\Select::make('categorie')->label('Qualification')
                    ->options(collect(CategorieInterne::cases())->mapWithKeys(fn ($c) => [$c->value => $c->libelle()])),
                Forms\Components\Select::make('severite')->label('Sévérité')
                    ->options(collect(Severite::cases())->mapWithKeys(fn ($s) => [$s->value => $s->libelle()])),
                Forms\Components\Select::make('priorite')->label('Priorité')
                    ->options(collect(Priorite::cases())->mapWithKeys(fn ($p) => [$p->value => $p->value])),
                Forms\Components\TextInput::make('domaine')->label('Domaine produit')->maxLength(64)
                    ->placeholder('Notes, Paiements, Inscriptions…'),
                Forms\Components\Textarea::make('motif')->label('Motif')
                    ->helperText('Requis pour modifier une sévérité ou une priorité déjà posée.')->rows(2),
            ])
            ->action(fn (array $data) => $this->tenter(fn () => app(ClasserTicket::class)->executer(
                $this->record,
                auth()->user(),
                CategorieInterne::tryFrom((string) ($data['categorie'] ?? '')),
                Severite::tryFrom((string) ($data['severite'] ?? '')),
                Priorite::tryFrom((string) ($data['priorite'] ?? '')),
                $data['domaine'] ?? null,
                $data['motif'] ?? null,
            ), 'Qualification enregistrée.'));
    }

    private function actionAssigner(): Actions\Action
    {
        return Actions\Action::make('assigner')
            ->label('Assigner')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->visible(fn () => Gate::allows('support.tickets.manage'))
            ->form([
                Forms\Components\Select::make('admin')->label('Assigner à')
                    ->options(fn () => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Personne'),
            ])
            ->action(function (array $data) {
                app(AssignerTicket::class)->executer($this->record, auth()->user(),
                    isset($data['admin']) ? User::find($data['admin']) : null);
                Notification::make()->success()->title('Assignation mise à jour.')->send();
            });
    }

    private function actionRestreindre(): Actions\Action
    {
        return Actions\Action::make('restreindre')
            ->label(fn () => $this->record->is_security_restricted ? 'Lever la restriction' : 'Restreindre (sécurité)')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->visible(fn () => Gate::allows('support.security.view'))
            ->modalDescription(fn () => $this->record->is_security_restricted
                ? 'La demande redeviendra visible de toute l\'équipe support et de l\'école.'
                : 'La demande ne sera plus visible que des personnes habilitées à la sécurité, ni côté école.')
            ->form([
                Forms\Components\Textarea::make('motif')->label('Motif')->required()
                    ->minLength(TicketStateMachine::motifMin())->rows(3),
            ])
            ->action(fn (array $data) => $this->tenter(fn () => app(RestreindreTicket::class)->executer(
                $this->record, auth()->user(), ! $this->record->is_security_restricted, $data['motif'],
            ), 'Restriction mise à jour.'));
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Ce que l\'école a signalé')
                ->schema([
                    Infolists\Components\TextEntry::make('description')->label('')->columnSpanFull()->prose(),
                    Infolists\Components\TextEntry::make('customer_category')->label('Catégorie choisie')
                        ->formatStateUsing(fn ($state) => $state->libelle()),
                    Infolists\Components\TextEntry::make('status')->label('Statut')->badge()
                        ->formatStateUsing(fn ($state) => $state->libelle())->color(fn ($state) => $state->ton()),
                    Infolists\Components\TextEntry::make('tenant.name')->label('Instance')
                        ->url(fn ($record) => route('filament.admin.resources.tenants.view', $record->tenant_id)),
                    Infolists\Components\TextEntry::make('reporter_name_snapshot')->label('Signalé par')->placeholder('—')
                        ->helperText(fn ($record) => collect($record->reporter_roles_snapshot)->implode(', ')),
                    Infolists\Components\TextEntry::make('severity')->label('Sévérité')->badge()
                        ->formatStateUsing(fn ($state) => $state?->libelle())->color(fn ($state) => $state?->ton() ?? 'gray')->placeholder('Non qualifiée'),
                    Infolists\Components\TextEntry::make('internal_category')->label('Qualification')
                        ->formatStateUsing(fn ($state) => $state?->libelle())->placeholder('Non qualifiée'),
                    Infolists\Components\TextEntry::make('assignee.name')->label('Assignée à')->placeholder('Personne'),
                    Infolists\Components\TextEntry::make('created_at')->label('Reçue le')->dateTime('d/m/Y H:i'),
                ])->columns(4),

            Infolists\Components\Section::make('Contexte capté')
                ->collapsible()
                ->schema([
                    Infolists\Components\TextEntry::make('context.module')->label('Module')->placeholder('—'),
                    Infolists\Components\TextEntry::make('context.route_name')->label('Route')->fontFamily('mono')->placeholder('—'),
                    Infolists\Components\TextEntry::make('context.url_path')->label('Page')->fontFamily('mono')->placeholder('—'),
                    Infolists\Components\TextEntry::make('context.entity_type')->label('Élément concerné')->placeholder('—')
                        ->formatStateUsing(fn ($state, $record) => $state.' #'.$record->context?->entity_id),
                    Infolists\Components\TextEntry::make('context.app_commit_sha')->label('Version déployée')
                        ->formatStateUsing(fn ($state) => substr((string) $state, 0, 10))->fontFamily('mono')->copyable()
                        ->helperText(fn ($record) => $record->context?->git_branch)->placeholder('Inconnue'),
                    Infolists\Components\TextEntry::make('context.browser_family')->label('Navigateur')->placeholder('—')
                        ->formatStateUsing(fn ($state, $record) => trim($state.' '.$record->context?->browser_version.' · '.$record->context?->os_family.' · '.$record->context?->device_type, ' ·')),
                    Infolists\Components\TextEntry::make('context.viewport')->label('Écran')->placeholder('—'),
                    Infolists\Components\TextEntry::make('context.request_ids')->label('Identifiants de requête')
                        ->fontFamily('mono')->copyable()->placeholder('—')
                        ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state),
                ])->columns(4),

            Infolists\Components\Section::make('Conversation')
                ->schema([
                    Infolists\Components\ViewEntry::make('messages')->label('')
                        ->view('filament.care.conversation')
                        ->viewData(['visibles' => $this->visibilitesLisibles()]),
                ]),

            Infolists\Components\Section::make('Journal')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Infolists\Components\ViewEntry::make('events')->label('')->view('filament.care.journal'),
                ]),
        ]);
    }

    /** @return list<VisibiliteMessage> */
    private function visibilitesAutorisees(): array
    {
        return array_values(array_filter([
            Gate::allows('support.tickets.reply') ? VisibiliteMessage::PublicClient : null,
            Gate::allows('support.internal_notes') ? VisibiliteMessage::InterneSupport : null,
            Gate::allows('support.internal_notes') ? VisibiliteMessage::Technique : null,
            Gate::allows('support.security.view') ? VisibiliteMessage::Securite : null,
        ]));
    }

    /** @return list<string> */
    private function visibilitesLisibles(): array
    {
        return array_map(fn ($v) => $v->value, array_values(array_filter([
            VisibiliteMessage::PublicClient,
            Gate::allows('support.internal_notes') ? VisibiliteMessage::InterneSupport : null,
            Gate::allows('support.internal_notes') ? VisibiliteMessage::Technique : null,
            Gate::allows('support.security.view') ? VisibiliteMessage::Securite : null,
        ])));
    }

    private function tenter(callable $action, string $succes): void
    {
        try {
            $action();
            $this->record->refresh();
            Notification::make()->success()->title($succes)->send();
        } catch (TransitionRefusee $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
