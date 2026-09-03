<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GroupResource\Pages;
use App\Filament\Resources\GroupResource\RelationManagers;
use App\Models\Group;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'Groupes';

    protected static ?string $modelLabel = 'groupe';

    protected static ?string $pluralModelLabel = 'groupes';

    protected static ?string $navigationGroup = 'Gestion SaaS';

    protected static ?int $navigationSort = 0;

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::active()->count() ?: null;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations du groupe')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom du groupe')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('ex: Groupe ROSTAN'),

                        Forms\Components\TextInput::make('code')
                            ->label('Code unique')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(50)
                            ->placeholder('ex: rostan'),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label('Téléphone')
                            ->tel()
                            ->maxLength(255),

                        Forms\Components\Select::make('status')
                            ->label('Statut')
                            ->options([
                                'active' => 'Actif',
                                'suspended' => 'Suspendu',
                            ])
                            ->default('active')
                            ->required(),

                        Forms\Components\TextInput::make('founded_year')
                            ->label('Année de fondation')
                            ->numeric()
                            ->minValue(1900)
                            ->maxValue(2100),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('address')
                            ->label('Adresse')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Identité visuelle du portail')
                    ->description('Ce que voient les membres du groupe en se connectant. Laissé vide, le portail porte la marque KLASSCI.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\FileUpload::make('logo_path')
                            ->label('Logo du groupe')
                            ->helperText('PNG ou SVG sur fond transparent, hauteur utile 80 px minimum. Remplace le logo KLASSCI dans la barre latérale et sur les documents exportés.')
                            ->image()
                            ->disk('public')
                            ->directory('group-logos')
                            ->visibility('public')
                            ->maxSize(2048)
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'])
                            ->imageEditor(),

                        Forms\Components\ColorPicker::make('metadata.branding.primary')
                            ->label('Couleur principale')
                            ->helperText('Teinte des bandeaux, des boutons et des accents du portail. Par défaut, le bleu KLASSCI #0453cb.')
                            // Le format hexadécimal est imposé ici parce que la
                            // couleur est injectée telle quelle en variable CSS :
                            // une valeur libre casserait la feuille de style de
                            // toutes les pages du portail.
                            ->regex('/^#[0-9a-fA-F]{6}$/')
                            ->validationMessages([
                                'regex' => 'Attendu : un code hexadécimal à six chiffres, par exemple #0453cb.',
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        default => 'danger',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Actif',
                        'suspended' => 'Suspendu',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('tenants_count')
                    ->label('Établissements')
                    ->counts('tenants')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('members_count')
                    ->label('Membres')
                    ->counts('members')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TenantsRelationManager::class,
            RelationManagers\MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
            'create' => Pages\CreateGroup::route('/create'),
            'view' => Pages\ViewGroup::route('/{record}'),
            'edit' => Pages\EditGroup::route('/{record}/edit'),
        ];
    }
}
