<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantDeploymentResource\Pages;
use App\Filament\Resources\TenantDeploymentResource\RelationManagers;
use App\Models\TenantDeployment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TenantDeploymentResource extends Resource
{
    protected static ?string $model = TenantDeployment::class;

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationLabel = 'Déploiements';

    protected static ?string $modelLabel = 'Déploiement';

    protected static ?string $pluralModelLabel = 'Déploiements';

    protected static ?string $navigationGroup = 'Gestion Tenants';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations Déploiement')
                    ->schema([
                        Forms\Components\Select::make('tenant_id')
                            ->label('Établissement')
                            ->relationship('tenant', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\TextInput::make('git_branch')
                            ->label('Branche Git')
                            ->required()
                            ->maxLength(100)
                            ->default('main'),

                        Forms\Components\TextInput::make('git_commit_hash')
                            ->label('Commit Hash')
                            ->required()
                            ->maxLength(40)
                            ->placeholder('SHA-1 du commit'),

                        Forms\Components\Select::make('status')
                            ->label('Statut')
                            ->required()
                            ->options([
                                'pending' => 'En attente',
                                'in_progress' => 'En cours',
                                'completed' => 'Terminé',
                                'failed' => 'Échoué',
                                'rolled_back' => 'Annulé (Rollback)',
                            ])
                            ->default('pending'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Suivi Temporel')
                    ->schema([
                        Forms\Components\DateTimePicker::make('started_at')
                            ->label('Début')
                            ->seconds(false),

                        Forms\Components\DateTimePicker::make('completed_at')
                            ->label('Fin')
                            ->seconds(false),

                        Forms\Components\TextInput::make('duration_seconds')
                            ->label('Durée (secondes)')
                            ->numeric()
                            ->disabled()
                            ->default(null),

                        Forms\Components\Select::make('deployed_by_user_id')
                            ->label('Déployé par')
                            ->relationship('deployedBy', 'name')
                            ->searchable()
                            ->preload()
                            ->default(null),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Logs & Erreurs')
                    ->schema([
                        Forms\Components\Textarea::make('deployment_log')
                            ->label('Log de déploiement')
                            ->rows(10)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('error_message')
                            ->label('Message d\'erreur')
                            ->rows(3)
                            ->columnSpanFull()
                            ->visible(fn ($get) => $get('status') === 'failed'),

                        Forms\Components\Textarea::make('error_details')
                            ->label('Détails erreur (JSON)')
                            ->rows(10)
                            ->columnSpanFull()
                            ->visible(fn ($get) => $get('status') === 'failed'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Établissement')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('tenant.code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Statut')
                    ->colors([
                        'secondary' => 'pending',
                        'warning' => 'in_progress',
                        'success' => 'completed',
                        'danger' => 'failed',
                        'gray' => 'rolled_back',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'En attente',
                        'in_progress' => 'En cours',
                        'completed' => 'Terminé',
                        'failed' => 'Échoué',
                        'rolled_back' => 'Annulé',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('git_branch')
                    ->label('Branche')
                    ->searchable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('git_commit_hash')
                    ->label('Commit')
                    ->searchable()
                    ->limit(8)
                    ->tooltip(fn ($record) => $record->git_commit_hash),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Démarré')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Terminé')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('duration_seconds')
                    ->label('Durée')
                    ->formatStateUsing(fn ($state) => $state ? gmdate('i:s', $state) . 'min' : '-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('deployedBy.name')
                    ->label('Déployé par')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tenant_id')
                    ->label('Établissement')
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'pending' => 'En attente',
                        'in_progress' => 'En cours',
                        'completed' => 'Terminé',
                        'failed' => 'Échoué',
                        'rolled_back' => 'Annulé',
                    ]),

                Tables\Filters\SelectFilter::make('git_branch')
                    ->label('Branche')
                    ->options([
                        'main' => 'main',
                        'develop' => 'develop',
                        'staging' => 'staging',
                    ]),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenantDeployments::route('/'),
            'create' => Pages\CreateTenantDeployment::route('/create'),
            'view' => Pages\ViewTenantDeployment::route('/{record}'),
            'edit' => Pages\EditTenantDeployment::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
