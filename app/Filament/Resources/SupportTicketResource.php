<?php

namespace App\Filament\Resources;

use App\Domain\Care\Tickets\Enums\CategorieClient;
use App\Domain\Care\Tickets\Enums\CategorieInterne;
use App\Domain\Care\Tickets\Enums\Severite;
use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Filament\Resources\SupportTicketResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * La file des demandes de support (KLASSCI Care).
 *
 * Les demandes naissent dans les ecoles, jamais ici : pas de creation. Chaque
 * modification passe par une Action du domaine, qui ecrit le journal.
 */
class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationLabel = 'Demandes';

    protected static ?string $modelLabel = 'demande';

    protected static ?string $pluralModelLabel = 'Demandes de support';

    protected static ?string $navigationGroup = 'Support';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canViewAny(): bool
    {
        return Gate::allows('support.tickets.view');
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows('support.tickets.view')
            && (! $record->is_security_restricted || Gate::allows('support.security.view'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['tenant:id,code,name', 'assignee:id,name', 'context:id,ticket_id,module,route_name'])
            ->when(! Gate::allows('support.security.view'), fn ($q) => $q->where('is_security_restricted', false));
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = SupportTicket::where('status', StatutTicket::TriagePending->value)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'title', 'reporter_name_snapshot', 'tenant.code'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('reference')->label('Réf.')->searchable()->copyable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('tenant.code')->label('Instance')->badge()->color('gray')->searchable(),
                Tables\Columns\TextColumn::make('title')->label('Demande')->limit(60)->tooltip(fn ($record) => $record->title)->searchable()
                    ->description(fn ($record) => $record->customer_category->libelle()),
                Tables\Columns\TextColumn::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn (StatutTicket $state) => $state->libelle())
                    ->color(fn (StatutTicket $state) => $state->ton()),
                Tables\Columns\TextColumn::make('severity')->label('Sév.')->badge()
                    ->formatStateUsing(fn (?Severite $state) => $state?->value)
                    ->color(fn (?Severite $state) => $state?->ton() ?? 'gray')->placeholder('—'),
                Tables\Columns\TextColumn::make('context.module')->label('Module')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('reporter_name_snapshot')->label('Signalé par')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('assignee.name')->label('Assignée à')->placeholder('Personne')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Reçue')->since()
                    ->tooltip(fn ($record) => $record->created_at?->format('d/m/Y H:i'))->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tenant_id')->label('Instance')->searchable()
                    ->options(fn () => \App\Models\Tenant::orderBy('code')->pluck('code', 'id')),
                Tables\Filters\SelectFilter::make('status')->label('Statut')->multiple()
                    ->options(collect(StatutTicket::cases())->mapWithKeys(fn ($s) => [$s->value => $s->libelle()])),
                Tables\Filters\SelectFilter::make('customer_category')->label('Catégorie (école)')
                    ->options(collect(CategorieClient::cases())->mapWithKeys(fn ($c) => [$c->value => $c->libelle()])),
                Tables\Filters\SelectFilter::make('internal_category')->label('Qualification')
                    ->options(collect(CategorieInterne::cases())->mapWithKeys(fn ($c) => [$c->value => $c->libelle()])),
                Tables\Filters\SelectFilter::make('severity')->label('Sévérité')
                    ->options(collect(Severite::cases())->mapWithKeys(fn ($s) => [$s->value => $s->libelle()])),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Ouvrir')])
            ->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Aucune demande')
            ->emptyStateDescription('Les demandes signalées depuis les écoles apparaîtront ici.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportTickets::route('/'),
            'view' => Pages\ViewSupportTicket::route('/{record}'),
        ];
    }
}
