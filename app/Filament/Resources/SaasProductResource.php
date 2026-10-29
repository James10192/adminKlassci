<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaasProductResource\Pages;
use App\Models\SaasProduct;
use App\Services\Saas\SaasCapabilities;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Catalogue commercial des categories, distinct du moteur technique et des
 * plans de facturation. Activation = selectable pour classification seulement.
 */
final class SaasProductResource extends Resource
{
    protected static ?string $model = SaasProduct::class;
    protected static ?string $navigationGroup = 'Gestion SaaS';
    protected static ?string $navigationLabel = 'Catalogue des produits';
    protected static ?string $modelLabel = 'produit scolaire';
    protected static ?string $pluralModelLabel = 'produits scolaires';
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Gate::allows(SaasCapabilities::VIEW);
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows(SaasCapabilities::VIEW);
    }

    public static function canCreate(): bool
    {
        return Gate::allows(SaasCapabilities::MANAGE_CATALOG);
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows(SaasCapabilities::MANAGE_CATALOG);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identité commerciale')
                ->description("Un établissement appartient à un seul produit. Un groupe peut réunir plusieurs produits, sans transformer ses écoles en établissement mixte.")
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Code stable du produit')
                        ->required()->maxLength(64)
                        ->regex('/^[a-z][a-z0-9_-]*$/')
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->helperText('Identifiant technique permanent, par exemple college ou universite_lmd.'),
                    Forms\Components\TextInput::make('name')
                        ->label('Nom affiché')->required()->maxLength(255),
                    Forms\Components\TextInput::make('establishment_type')
                        ->label("Catégorie d'établissement")
                        ->required()->maxLength(64)
                        ->regex('/^[a-z][a-z0-9_]*$/')
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->helperText('Catégorie unique, distincte du moteur Laravel / FastAPI.'),
                    Forms\Components\Select::make('adapter_key')
                        ->label('Moteur applicatif')
                        ->options(fn () => config('saas.adapter_keys', []))
                        ->required()
                        ->disabledOn('edit')
                        ->helperText("LMD et BTS peuvent partager KLASSCIv2. Le Collège utilise son moteur séparé."),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Disponible pour les qualifications')
                        ->default(false)
                        ->helperText("Ne déploie aucun serveur, n’active aucune facture et n’accorde aucun droit d’accès.")
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->emptyStateHeading('Le catalogue scolaire est vide')
            ->emptyStateDescription('Créez les catégories commerciales avant de soumettre une qualification. Aucun tarif ou établissement ne sera créé automatiquement.')
            ->emptyStateIcon('heroicon-o-squares-plus')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Produit')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('establishment_type')->label('Catégorie')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('adapter_key')->label('Moteur')
                    ->formatStateUsing(fn (string $state) => config('saas.adapter_keys.'.$state, $state)),
                Tables\Columns\IconColumn::make('is_active')->label('Qualification')->boolean()
                    ->trueColor('success')->falseColor('gray'),
                Tables\Columns\TextColumn::make('establishments_count')->counts('establishments')
                    ->label('Écoles SaaS')->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn () => Gate::allows(SaasCapabilities::MANAGE_CATALOG)),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSaasProducts::route('/'),
            'create' => Pages\CreateSaasProduct::route('/create'),
            'edit' => Pages\EditSaasProduct::route('/{record}/edit'),
        ];
    }
}
