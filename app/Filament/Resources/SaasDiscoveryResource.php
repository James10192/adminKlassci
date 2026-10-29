<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Saas\ReconciliationDraftFields;
use App\Filament\Resources\SaasDiscoveryResource\Pages;
use App\Models\Tenant;
use App\Services\Saas\SaasCapabilities;
use App\Services\Saas\SaasMappingWorkflow;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Inventaire graphique des instances historiques, sans jamais charger leurs
 * secrets techniques ni requalifier automatiquement les codes.
 */
final class SaasDiscoveryResource extends Resource
{
    protected static ?string $model = Tenant::class;
    protected static ?string $navigationGroup = 'Gestion SaaS';
    protected static ?string $navigationLabel = 'Instances à qualifier';
    protected static ?string $modelLabel = 'instance historique';
    protected static ?string $pluralModelLabel = 'instances historiques';
    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';
    protected static ?int $navigationSort = 3;
    protected static ?string $slug = 'instances-a-qualifier';

    public static function canViewAny(): bool
    {
        return Gate::allows(SaasCapabilities::VIEW)
            && Schema::hasTable('saas_mapping_reviews');
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows(SaasCapabilities::VIEW);
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

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $pending = Tenant::query()->whereDoesntHave('mappingReview')->count();

        return $pending ? (string) $pending : null;
    }

    public static function getEloquentQuery(): Builder
    {
        // Whitelist des champs : ni mot de passe, ni token, ni PII, ni couts.
        return Tenant::withTrashed()
            ->select(['id', 'code', 'name', 'group_id', 'status', 'deleted_at'])
            ->with([
                'group:id,code,name',
                'mappingReview:id,legacy_tenant_id,state',
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->emptyStateHeading('Aucune instance dans cette sélection')
            ->emptyStateDescription('Toutes les instances existantes sont consultables ici. Aucun tenant n’est créé automatiquement.')
            ->emptyStateIcon('heroicon-o-building-office-2')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Instance')->searchable()->sortable()->weight('semibold')
                    ->description(fn (Tenant $record) => $record->name),
                Tables\Columns\TextColumn::make('group.name')
                    ->label('Groupe historique')
                    ->placeholder('Aucun')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('mappingReview.state')
                    ->label('Qualification')
                    ->badge()
                    ->placeholder('À qualifier')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'draft' => 'Brouillon',
                        'submitted' => 'À vérifier',
                        'approved' => 'Qualification approuvée',
                        'rejected' => 'À compléter',
                        default => 'À qualifier',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'submitted' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label('État technique')
                    ->badge()->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('deleted_at')
                    ->label('Archivée')
                    ->boolean()
                    ->getStateUsing(fn (Tenant $record) => $record->trashed())
                    ->trueColor('danger')->falseColor('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group_id')
                    ->label('Groupe historique')
                    ->relationship('group', 'name')
                    ->searchable()->preload(),
            ])
            ->actions([
                Tables\Actions\Action::make('qualify')
                    ->label('Qualifier')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('primary')
                    ->visible(fn (Tenant $record) =>
                        Gate::allows(SaasCapabilities::PROPOSE)
                        && ! $record->trashed()
                        && $record->mappingReview === null)
                    ->modalHeading(fn (Tenant $record) => 'Qualifier '.$record->code)
                    ->modalDescription("Dossier de classification sans modification de l'instance, de son groupe historique ni de ses abonnements.")
                    ->modalWidth('lg')
                    ->form(ReconciliationDraftFields::make(false))
                    ->action(function (Tenant $record, array $data): void {
                        Gate::authorize(SaasCapabilities::PROPOSE);
                        $data['legacy_tenant_id'] = $record->id;
                        app(SaasMappingWorkflow::class)->saveDraft(auth()->user(), $data);
                        Notification::make()
                            ->title('Dossier enregistré')
                            ->body('Consultez « Rapprochement des écoles » pour compléter et soumettre la proposition.')
                            ->success()->send();
                    }),
                Tables\Actions\Action::make('open_review')
                    ->label('Voir le dossier')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (Tenant $record) => $record->mappingReview !== null)
                    ->url(fn (): string => SaasMappingReviewResource::getUrl('index')),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSaasDiscovery::route('/')];
    }
}
