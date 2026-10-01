<?php

namespace App\Filament\Resources;

use App\Domain\Care\Retours\Enums\AvisAssistant;
use App\Domain\Care\Retours\Models\RetourAssistant;
use App\Filament\Resources\RetourAssistantResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Les 👍 / 👎 donnés dans les écoles sur les réponses de Nanan.
 *
 * Lecture seule : les retours naissent dans les écoles. Les seuls gestes sont
 * « marquer traité » et « créer une demande », qui passent par le domaine.
 * Mêmes capacités que la file des demandes : lire = support.tickets.view,
 * agir = support.tickets.manage.
 */
class RetourAssistantResource extends Resource
{
    use \App\Filament\Concerns\LibelleAvecMajusculeInitiale;

    protected static ?string $model = RetourAssistant::class;

    protected static ?string $slug = 'retours-assistant';

    protected static ?string $navigationIcon = 'heroicon-o-hand-thumb-down';

    protected static ?string $navigationLabel = 'Retours Nanan';

    protected static ?string $modelLabel = 'retour Nanan';

    protected static ?string $pluralModelLabel = 'retours Nanan';

    protected static ?string $navigationGroup = 'Support';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Gate::allows('support.tickets.view');
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows('support.tickets.view');
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
        return parent::getEloquentQuery()->with(['tenant:id,code,name', 'traitePar:id,name', 'ticket:id,reference']);
    }

    /** Les 👎 non traités de la semaine : ce qui attend une lecture. */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = RetourAssistant::query()
            ->where('avis', AvisAssistant::PasUtile->value)
            ->nonTraites()
            ->where('created_at', '>=', now()->subDays((int) config('care.retours_assistant.badge_jours', 7)))
            ->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return '👎 non traités ces '.(int) config('care.retours_assistant.badge_jours', 7).' derniers jours';
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
                Tables\Columns\TextColumn::make('tenant.code')->label('École')->badge()->color('gray')->searchable(),
                Tables\Columns\TextColumn::make('avis')->label('Avis')->badge()
                    ->formatStateUsing(fn (AvisAssistant $state) => $state->pictogramme().' '.$state->libelle())
                    ->color(fn (AvisAssistant $state) => $state->ton()),
                Tables\Columns\TextColumn::make('raison')->label('Raison')->placeholder('—')
                    ->formatStateUsing(fn ($state, RetourAssistant $record) => $record->raisonLibelle()),
                Tables\Columns\TextColumn::make('question')->label('Question')->limit(70)
                    ->tooltip(fn (RetourAssistant $record) => mb_substr($record->question, 0, 300))->searchable()
                    ->description(fn (RetourAssistant $record) => $record->commentaire ? '💬 '.mb_strimwidth($record->commentaire, 0, 80, '…') : null),
                Tables\Columns\TextColumn::make('utilisateur_nom')->label('Donné par')->searchable()
                    ->description(fn (RetourAssistant $record) => $record->utilisateur_role),
                Tables\Columns\IconColumn::make('traite_le')->label('Traité')->boolean()
                    ->getStateUsing(fn (RetourAssistant $record) => $record->estTraite()),
                Tables\Columns\TextColumn::make('created_at')->label('Reçu')->since()->sortable()
                    ->tooltip(fn (RetourAssistant $record) => $record->created_at?->format('d/m/Y H:i')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tenant_id')->label('École')->searchable()
                    ->options(fn () => \App\Models\Tenant::orderBy('code')->pluck('code', 'id')),
                Tables\Filters\SelectFilter::make('avis')->label('Avis')
                    ->options(collect(AvisAssistant::cases())->mapWithKeys(fn ($a) => [$a->value => $a->pictogramme().' '.$a->libelle()])),
                Tables\Filters\SelectFilter::make('raison')->label('Raison')
                    ->options(fn () => (array) config('care.retours_assistant.raisons', [])),
                Tables\Filters\TernaryFilter::make('traite')->label('Traité')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('traite_le'),
                        false: fn (Builder $query) => $query->whereNull('traite_le'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Lire')])
            ->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Aucun retour')
            ->emptyStateDescription('Les 👍 / 👎 donnés dans les écoles sur les réponses de Nanan apparaîtront ici.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRetoursAssistant::route('/'),
            'view' => Pages\ViewRetourAssistant::route('/{record}'),
        ];
    }
}
