<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaasMappingReviewResource\Pages;
use App\Models\Group;
use App\Models\SaasMappingReviewRecord;
use App\Models\SaasProduct;
use App\Models\Tenant;
use App\Services\Saas\SaasCapabilities;
use App\Services\Saas\SaasMappingWorkflow;
use Filament\Forms;
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
 * Parcours humain : propositions avec preuves -> contre-verification ->
 * approbation de classification. AUCUN import, deploy, SSO ou facturation.
 */
final class SaasMappingReviewResource extends Resource
{
    protected static ?string $model = SaasMappingReviewRecord::class;
    protected static ?string $navigationGroup = 'Gestion SaaS';
    protected static ?string $navigationLabel = 'Rapprochement des écoles';
    protected static ?string $modelLabel = 'dossier de rapprochement';
    protected static ?string $pluralModelLabel = 'Dossiers de rapprochement';
    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?int $navigationSort = 3;
    protected static ?string $slug = 'rapprochement-ecoles';

    public static function canViewAny(): bool
    {
        return Gate::allows(SaasCapabilities::VIEW) && Schema::hasTable('saas_mapping_reviews');
    }

    public static function canCreate(): bool
    {
        return false; // Toute creation passe par SaasMappingWorkflow.
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

        $n = SaasMappingReviewRecord::query()->where('state', 'submitted')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'legacyTenant:id,code,name,group_id,deleted_at',
            'proposedGroup:id,code,name,deleted_at',
            'product:id,name,code,is_active',
            'creator:id,name', 'reviewer:id,name',
        ]);
    }

    public static function form(Form $form): Form
    {
        // Les actions Filament utilisent les formulaires ci-dessous, et non
        // les ecritures CRUD directes d'Eloquent.
        return $form->schema([]);
    }

    /** @return array<int, \Filament\Forms\Components\Component> */
    public static function draftFields(bool $creating = false): array
    {
        return [
            Forms\Components\Select::make('legacy_tenant_id')
                ->label('Instance historique à examiner')
                ->options(fn () => Tenant::query()->orderBy('code')->limit(500)->pluck('code', 'id')->all())
                ->searchable()
                ->required($creating)
                ->disabled(! $creating)
                ->dehydrated($creating)
                ->helperText("Les instances déjà rapprochées et archivées seront bloquées par les contrôles."),
            Forms\Components\Select::make('kind')
                ->label('Nature de l’instance')
                ->options([
                    'school' => 'Établissement scolaire',
                    'technical' => 'Instance technique (non facturable)',
                    'demonstration' => 'Environnement de démonstration',
                ])
                ->required()
                ->live()
                ->helperText('Choix humain explicite : aucune déduction automatique depuis le nom.'),
            Forms\Components\Select::make('product_id')
                ->label('Produit scolaire')
                ->options(fn () => SaasProduct::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (Forms\Get $get) => $get('kind') === 'school')
                ->helperText("Catalogue vide ? Configurez d'abord les produits, sans toucher aux contrats."),
            Forms\Components\Select::make('proposed_group_id')
                ->label('Groupe commercial proposé')
                ->options(fn () => Group::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->placeholder('Aucun — établissement indépendant')
                ->visible(fn (Forms\Get $get) => $get('kind') === 'school')
                ->helperText("Un écart avec le groupe historique sera signalé au second valideur ; aucune modification SSO."),
            Forms\Components\Textarea::make('evidence_reference')
                ->label('Justificatif / référence de décision')
                ->required()
                ->maxLength(255)
                ->rows(2)
                ->helperText('Référence interne de la validation contractuelle ; ne pas copier un contrat, un secret ou des données d’élève.'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('Aucun dossier de rapprochement')
            ->emptyStateDescription('Commencez par examiner une instance existante ; son exploitation et son abonnement ne seront pas modifiés.')
            ->emptyStateIcon('heroicon-o-building-office')
            ->columns([
                Tables\Columns\TextColumn::make('legacyTenant.code')
                    ->label('Instance source')->badge()->color('gray')
                    ->searchable()->sortable()
                    ->description(fn (SaasMappingReviewRecord $record) =>
                        $record->legacyTenant?->trashed() ? 'Archivée — non activable' : $record->legacyTenant?->name),
                Tables\Columns\TextColumn::make('kind')->label('Qualification')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'school' => 'Établissement',
                        'technical' => 'Technique',
                        'demonstration' => 'Démonstration',
                        default => 'À qualifier',
                    }),
                Tables\Columns\TextColumn::make('product.name')->label('Produit')->placeholder('Non choisi')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('proposedGroup.name')->label('Groupe proposé')
                    ->placeholder('Indépendant')->toggleable(),
                Tables\Columns\IconColumn::make('group_discrepancy')
                    ->label('Écart groupe')
                    ->boolean()
                    ->getStateUsing(fn (SaasMappingReviewRecord $record) =>
                        app(SaasMappingWorkflow::class)->hasGroupDiscrepancy($record))
                    ->trueColor('warning')->falseColor('gray'),
                Tables\Columns\TextColumn::make('state')->label('Avancement')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'draft' => 'Brouillon',
                        'submitted' => 'À vérifier',
                        'approved' => 'Qualification approuvée',
                        'rejected' => 'À corriger',
                        default => 'Inconnu',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'submitted' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('creator.name')->label('Proposé par')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('reviewer.name')->label('Vérifié par')
                    ->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->label('Mis à jour')
                    ->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('state')->label('Étape')
                    ->options([
                        'draft' => 'Brouillon',
                        'submitted' => 'À vérifier',
                        'approved' => 'Approuvé',
                        'rejected' => 'À corriger',
                    ]),
                Tables\Filters\SelectFilter::make('kind')->label('Nature')
                    ->options([
                        'school' => 'Établissement',
                        'technical' => 'Technique',
                        'demonstration' => 'Démonstration',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('new_review')
                    ->label('Examiner une instance')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->visible(fn () => Gate::allows(SaasCapabilities::PROPOSE))
                    ->form(static::draftFields(true))
                    ->modalHeading('Ouvrir un dossier de rapprochement')
                    ->modalDescription('Une proposition traçable, sans modifier le groupe, le produit actif, le contrat ou les données de l’école.')
                    ->action(function (array $data): void {
                        abort_unless(Gate::allows(SaasCapabilities::PROPOSE), 403);
                        app(SaasMappingWorkflow::class)->saveDraft(auth()->user(), $data);
                        Notification::make()->title('Brouillon enregistré')
                            ->body('Aucun changement effectué sur l’instance ou son contrat.')
                            ->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('edit_draft')
                    ->label('Compléter')->icon('heroicon-o-pencil-square')
                    ->visible(fn (SaasMappingReviewRecord $record) =>
                        Gate::allows(SaasCapabilities::PROPOSE)
                        && (int) $record->created_by === (int) auth()->id()
                        && in_array($record->state, ['draft', 'rejected'], true))
                    ->fillForm(fn (SaasMappingReviewRecord $record) => [
                        'kind' => $record->kind,
                        'product_id' => $record->product_id,
                        'proposed_group_id' => $record->proposed_group_id,
                        'evidence_reference' => $record->evidence_reference,
                    ])
                    ->form(static::draftFields())
                    ->action(function (SaasMappingReviewRecord $record, array $data): void {
                        abort_unless(Gate::allows(SaasCapabilities::PROPOSE), 403);
                        $data['legacy_tenant_id'] = $record->legacy_tenant_id;
                        app(SaasMappingWorkflow::class)->saveDraft(auth()->user(), $data, $record->id);
                        Notification::make()->title('Dossier mis à jour')->success()->send();
                    }),
                Tables\Actions\Action::make('submit')
                    ->label('Soumettre')->icon('heroicon-o-paper-airplane')
                    ->color('primary')->requiresConfirmation()
                    ->modalDescription("Un autre membre habilité devra vérifier. Aucune école n'est importée.")
                    ->visible(fn (SaasMappingReviewRecord $record) =>
                        Gate::allows(SaasCapabilities::PROPOSE)
                        && (int) $record->created_by === (int) auth()->id()
                        && $record->state === 'draft')
                    ->action(function (SaasMappingReviewRecord $record): void {
                        app(SaasMappingWorkflow::class)->submit(auth()->user(), $record->id);
                        Notification::make()->title('Transmis pour contre-vérification')->success()->send();
                    }),
                Tables\Actions\Action::make('decision')
                    ->label('Contrôler')->icon('heroicon-o-shield-check')
                    ->color('warning')
                    ->visible(fn (SaasMappingReviewRecord $record) =>
                        Gate::allows(SaasCapabilities::REVIEW)
                        && (int) $record->created_by !== (int) auth()->id()
                        && $record->state === 'submitted')
                    ->form([
                        Forms\Components\Select::make('decision')
                            ->label('Décision')->options([
                                'approve' => 'Approuver la qualification (sans import)',
                                'reject' => 'Renvoyer pour correction',
                            ])->required()->live(),
                        Forms\Components\Toggle::make('ack_group_gap')
                            ->label('Écart avec le groupe historique vérifié et documenté')
                            ->helperText("N’attribue aucun droit SSO au groupe. Une réconciliation technique distincte restera nécessaire.")
                            ->visible(fn (Forms\Get $get, SaasMappingReviewRecord $record) =>
                                $get('decision') === 'approve'
                                && app(SaasMappingWorkflow::class)->hasGroupDiscrepancy($record)),
                        Forms\Components\Textarea::make('review_note')
                            ->label('Décision motivée')->required()->minLength(10)
                            ->maxLength(1000)->rows(3),
                    ])
                    ->action(function (SaasMappingReviewRecord $record, array $data): void {
                        app(SaasMappingWorkflow::class)->decide(
                            auth()->user(),
                            $record->id,
                            ($data['decision'] ?? '') === 'approve',
                            $data['review_note'] ?? '',
                            (bool) ($data['ack_group_gap'] ?? false)
                        );
                        Notification::make()->title('Décision tracée dans le dossier')
                            ->body('Aucun import, droit SSO ou contrat n’a été modifié.')
                            ->success()->send();
                    }),
                Tables\Actions\Action::make('history')
                    ->label('Historique')->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading('Journal du dossier')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (SaasMappingReviewRecord $record) =>
                        view('filament.saas.mapping-history', [
                            'events' => $record->events()->with('actor:id,name')->get(),
                        ])),
            ])
            ->bulkActions([]); // Pas d'opérations de masse sur des décisions.
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSaasMappingReviews::route('/')];
    }
}
