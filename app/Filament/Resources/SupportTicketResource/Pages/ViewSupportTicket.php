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
use Illuminate\Support\Facades\DB;
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
            ->requiresConfirmation()
            ->modalDescription("La demande sera assignée à votre compte. Lorsqu'elle est prête à être traitée, elle passera aussi « En cours » ; l'école verra ce changement dans son suivi.")
            ->modalSubmitActionLabel('Confirmer la prise en charge')
            ->visible(fn () => Gate::allows('support.tickets.manage') && $this->record->assigned_admin_id !== auth()->id())
            ->action(fn () => $this->tenter(
                fn () => $this->prendreEnCharge(),
                "Prise en charge enregistrée. L'école verra le statut lors de la synchronisation."));
    }

    /**
     * Un clic sur « Prendre en charge » doit modifier le suivi, pas seulement
     * une affectation interne invisible pour l'école. Transition conditionnelle
     * et atomique : ne jamais effacer une attente d'action de l'école.
     */
    private function prendreEnCharge(): void
    {
        DB::transaction(function () {
            app(AssignerTicket::class)->executer($this->record, auth()->user(), auth()->user());

            if (in_array($this->record->status, [
                StatutTicket::TriagePending,
                StatutTicket::Triaged,
                StatutTicket::WaitingSupport,
                StatutTicket::Confirmed,
            ], true) && app(TicketStateMachine::class)->peut($this->record->status, StatutTicket::InProgress)) {
                app(TicketStateMachine::class)->franchir(
                    $this->record,
                    StatutTicket::InProgress,
                    Acteur::personnel(auth()->user()),
                );
            }
        });
    }

    private function actionRepondre(): Actions\Action
    {
        return Actions\Action::make('repondre')
            ->label('Écrire un message')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->modalHeading('Choisir le destinataire du message')
            ->modalDescription("Répondez au demandeur (étudiant ou personnel), sollicitez l'administration autorisée, ou rédigez une note interne. Les destinataires ne sont pas interchangeables.")
            ->modalSubmitActionLabel('Valider le message')
            ->color('primary')
            ->visible(fn () => Gate::allows('support.tickets.reply') || Gate::allows('support.internal_notes'))
            ->form([
                // Ne jamais colorer une option non selectionnee comme si elle etait
                // deja validee : le bouton vert donnait un faux signal d'envoi
                // public, alors que Livewire gardait visibilite = null.
                // Les radios natifs rendent l'etat choisi explicite, au clavier
                // comme au toucher, et conservent la permission effective.
                Forms\Components\Radio::make('visibilite')
                    ->label('Qui verra ce message ?')
                    ->required()
                    ->options(fn () => collect($this->visibilitesAutorisees())->mapWithKeys(fn ($v) => [$v->value => $v->libelle()]))
                    ->descriptions([
                        VisibiliteMessage::PublicClient->value => 'Visible par le demandeur du ticket, y compris un étudiant.',
                        VisibiliteMessage::Administration->value => "Réservée au personnel pouvant consulter les demandes de l'établissement.",
                        VisibiliteMessage::InterneSupport->value => 'Réservée au personnel support habilité.',
                        VisibiliteMessage::Technique->value => 'Réservée au personnel technique habilité.',
                        VisibiliteMessage::Securite->value => 'Réservée aux personnes habilitées à la sécurité.',
                    ])
                    ->columns(['default' => 1, 'md' => 2])
                    ->validationMessages([
                        'required' => 'Sélectionnez qui pourra lire le message avant de continuer.',
                    ])
                    ->helperText("Aucun destinataire n'est choisi par défaut. Une note interne ne part ni au demandeur, ni à l'administration.")
                    ->live(),
                Forms\Components\Placeholder::make('destination')
                    ->label('Conséquence de cet envoi')
                    ->content(fn (Forms\Get $get) => match ($get('visibilite')) {
                        VisibiliteMessage::PublicClient->value => 'Le demandeur initial verra cette réponse et sera notifié si son suivi est actif. Cela ne sollicite PAS la scolarité.',
                        VisibiliteMessage::Administration->value => "Seul le personnel de l'établissement habilité à consulter les demandes pourra lire ce message et recevoir une alerte dans KLASSCI. Le demandeur ne le verra pas.",
                        VisibiliteMessage::InterneSupport->value => "Cette note restera interne au support et ne sera pas visible par l'école.",
                        VisibiliteMessage::Technique->value => "Cette note sera réservée au personnel technique habilité et ne sera pas visible par l'école.",
                        VisibiliteMessage::Securite->value => "Cette note sera réservée aux personnes habilitées à la sécurité et ne sera pas visible par l'école.",
                        default => "Choisissez d'abord le destinataire : rien ne sera envoyé tant qu'aucune option n'est sélectionnée.",
                    }),
                Forms\Components\Textarea::make('corps')->label('Message')->required()->rows(6)->maxLength(5000),
                // Une question posee a l'ecole : la demande passe en « Action requise » chez elle,
                // et sa reponse la ramene d'elle-meme en attente support.
                // Propose seulement si le dossier peut attendre l'ecole. Livewire relit le dossier
                // a chaque requete : si son etat change entre l'ouverture et l'envoi, le champ se
                // masque, et sans dehydratedWhenHidden() le choix de l'agent disparaitrait en
                // silence (le message partirait seul). La machine a etats tranche alors sous
                // verrou, et un refus garde le texte saisi.
                Forms\Components\Toggle::make('attendre_ecole')
                    ->label(fn (Forms\Get $get) => $get('visibilite') === VisibiliteMessage::Administration->value
                        ? "Demander une action à l'administration" : 'Attendre une réponse du demandeur')
                    ->helperText(fn (Forms\Get $get) => $get('visibilite') === VisibiliteMessage::Administration->value
                        ? "Le dossier attendra le personnel habilité de l'établissement, pas l'étudiant. Une réponse de ce personnel le rendra au support."
                        : 'À activer seulement si la personne qui a ouvert ce ticket doit fournir une réponse. Sinon, laissez désactivé.')
                    ->dehydratedWhenHidden()
                    ->visible(fn (Forms\Get $get) => in_array($get('visibilite'), [VisibiliteMessage::PublicClient->value, VisibiliteMessage::Administration->value], true)
                        && Gate::allows('support.tickets.manage')
                        && app(TicketStateMachine::class)->peut($this->record->status, StatutTicket::WaitingCustomer)),
            ])
            ->action(function (array $data, Actions\Action $action) {
                $visibilite = VisibiliteMessage::from($data['visibilite']);
                abort_unless(in_array($visibilite, $this->visibilitesAutorisees(), true), 403);
                $attendre = in_array($visibilite, [VisibiliteMessage::PublicClient, VisibiliteMessage::Administration], true) && ! empty($data['attendre_ecole'])
                    && Gate::allows('support.tickets.manage');
                $this->tenter(fn () => DB::transaction(function () use ($data, $visibilite, $attendre) {
                    app(RepondreTicket::class)->executer($this->record, auth()->user(), $data['corps'], $visibilite);
                    if ($attendre) {
                        app(TicketStateMachine::class)->franchir($this->record, StatutTicket::WaitingCustomer, Acteur::personnel(auth()->user()), destinataire: $visibilite === VisibiliteMessage::Administration ? 'school_staff' : 'reporter');
                    }
                }), match ($visibilite) {
                    VisibiliteMessage::PublicClient => 'Réponse publiée pour le demandeur.',
                    VisibiliteMessage::Administration => "Message destiné à l'administration de l'établissement enregistré.",
                    default => 'Note interne enregistrée (non visible par le demandeur).',
                }, garder: $action);
            });
    }

    private function actionStatut(): Actions\Action
    {
        return Actions\Action::make('statut')
            ->label('Changer le statut')
            ->icon('heroicon-o-arrow-path')
            ->modalDescription("Le nouveau statut sera affiché dans l'espace support de l'école. Les changements importants déclenchent une notification sur l'instance si son suivi est actif.")
            ->modalSubmitActionLabel('Appliquer le statut')
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
            ), "Statut mis à jour. L'école verra sa nouvelle situation après synchronisation."));
    }

    private function actionQualifier(): Actions\Action
    {
        return Actions\Action::make('qualifier')
            ->label('Qualifier')
            ->icon('heroicon-o-tag')
            ->modalDescription("Classement interne pour prioriser le traitement. Il ne publie aucun message à l'école et ne lui envoie pas de notification.")
            ->modalSubmitActionLabel('Enregistrer la qualification')
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
            ->modalDescription("Affecte un membre de l'équipe à la demande. Cette affectation interne n'envoie pas de notification à l'école.")
            ->modalSubmitActionLabel('Confirmer l’assignation')
            ->color('gray')
            ->visible(fn () => Gate::allows('support.tickets.manage'))
            ->form([
                Forms\Components\Select::make('admin')->label('Assigner à')
                    ->options(fn () => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Personne'),
            ])
            ->action(fn (array $data) => $this->tenter(
                fn () => app(AssignerTicket::class)->executer($this->record, auth()->user(),
                    isset($data['admin']) ? User::find($data['admin']) : null),
                'Assignation mise à jour.'));
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
                        ->formatStateUsing(fn ($state, $record) => $state === StatutTicket::WaitingCustomer
                            ? ($record->waiting_on === 'school_staff' ? "Action scolarité attendue" : "Réponse du demandeur attendue")
                            : $state->libelle())->color(fn ($state) => $state->ton()),
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
            Gate::allows('support.tickets.reply') ? VisibiliteMessage::Administration : null,
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
            Gate::allows('support.tickets.reply') ? VisibiliteMessage::Administration : null,
            Gate::allows('support.internal_notes') ? VisibiliteMessage::InterneSupport : null,
            Gate::allows('support.internal_notes') ? VisibiliteMessage::Technique : null,
            Gate::allows('support.security.view') ? VisibiliteMessage::Securite : null,
        ])));
    }

    /**
     * Avec $garder, un refus laisse la fenetre ouverte : le dossier a change
     * d'etat entre l'ouverture et l'envoi, et le texte saisi ne doit pas etre perdu.
     */
    private function tenter(callable $action, string $succes, ?Actions\Action $garder = null): void
    {
        try {
            $action();
            // refresh() recharge aussi les relations deja lues (conversation,
            // assignee) : sans lui, le dossier montre l'etat d'avant l'action.
            $this->record->refresh();
            Notification::make()->success()->title($succes)->send();
        } catch (TransitionRefusee $e) {
            $this->record->refresh();
            Notification::make()->danger()->title($e->getMessage())
                ->body($garder ? "Le dossier a changé d'état entre-temps : votre texte est conservé." : null)->send();
            $garder?->halt();
        }
    }
}
