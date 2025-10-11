<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantDeploymentResource\Pages;
use App\Models\TenantDeployment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Colors\Color;

class TenantDeploymentResource extends Resource
{
    protected static ?string $model = TenantDeployment::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Déploiements';

    protected static ?string $modelLabel = 'déploiement';

    protected static ?string $pluralModelLabel = 'déploiements';

    protected static ?string $navigationGroup = 'Gestion Tenants';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('tenant_id')
                    ->relationship('tenant', 'name')
                    ->required()
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\TextInput::make('git_branch')
                    ->label('Branche Git')
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\TextInput::make('git_commit_hash')
                    ->label('Commit Hash')
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\Select::make('status')
                    ->label('Statut')
                    ->options([
                        'in_progress' => 'En cours',
                        'success' => 'Réussi',
                        'failed' => 'Échoué',
                    ])
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\Textarea::make('error_message')
                    ->label('Message d\'erreur')
                    ->rows(3)
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn ($record) => $record && $record->status === 'failed'),

                Forms\Components\DateTimePicker::make('started_at')
                    ->label('Démarré le')
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\DateTimePicker::make('completed_at')
                    ->label('Terminé le')
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\TextInput::make('duration_seconds')
                    ->label('Durée (secondes)')
                    ->disabled()
                    ->dehydrated(false)
                    ->suffix('s'),

                Forms\Components\Textarea::make('deployment_log')
                    ->label('Logs de déploiement')
                    ->rows(10)
                    ->disabled()
                    ->dehydrated(false)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('git_branch')
                    ->label('Branche')
                    ->badge()
                    ->color('info')
                    ->searchable(),

                Tables\Columns\TextColumn::make('git_commit_hash')
                    ->label('Commit')
                    ->formatStateUsing(fn ($state) => substr($state ?? 'N/A', 0, 8))
                    ->copyable()
                    ->copyMessage('Commit hash copié!')
                    ->copyMessageDuration(1500),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'in_progress' => 'warning',
                        'success' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'in_progress' => 'En cours',
                        'success' => 'Réussi',
                        'failed' => 'Échoué',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Démarré')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('duration_seconds')
                    ->label('Durée')
                    ->formatStateUsing(fn ($state) => $state ? "{$state}s" : 'N/A')
                    ->sortable(),

                Tables\Columns\TextColumn::make('deployedBy.name')
                    ->label('Déployé par')
                    ->default('CLI')
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Terminé')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'in_progress' => 'En cours',
                        'success' => 'Réussi',
                        'failed' => 'Échoué',
                    ]),

                Tables\Filters\SelectFilter::make('tenant')
                    ->label('Tenant')
                    ->relationship('tenant', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Voir détails'),
            ])
            ->bulkActions([
                //
            ]);
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
            'view' => Pages\ViewTenantDeployment::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // Pas de création manuelle
    }
}
