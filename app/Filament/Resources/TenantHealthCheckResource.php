<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantHealthCheckResource\Pages;
use App\Filament\Resources\TenantHealthCheckResource\RelationManagers;
use App\Models\TenantHealthCheck;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TenantHealthCheckResource extends Resource
{
    protected static ?string $model = TenantHealthCheck::class;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationLabel = 'Health Checks';

    protected static ?string $modelLabel = 'Health Check';

    protected static ?string $pluralModelLabel = 'Health Checks';

    protected static ?string $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations Check')
                    ->schema([
                        Forms\Components\Select::make('tenant_id')
                            ->label('Établissement')
                            ->relationship('tenant', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('check_type')
                            ->label('Type de Check')
                            ->required()
                            ->options([
                                'ping' => 'Ping (Availability)',
                                'database' => 'Database Connection',
                                'storage' => 'Storage Access',
                                'api' => 'API Endpoints',
                                'ssl' => 'SSL Certificate',
                                'dns' => 'DNS Resolution',
                                'performance' => 'Performance',
                            ])
                            ->default('ping'),

                        Forms\Components\Select::make('status')
                            ->label('Statut')
                            ->required()
                            ->options([
                                'healthy' => 'Sain',
                                'degraded' => 'Dégradé',
                                'unhealthy' => 'Défaillant',
                                'unknown' => 'Inconnu',
                            ])
                            ->default('unknown'),

                        Forms\Components\TextInput::make('response_time_ms')
                            ->label('Temps de réponse (ms)')
                            ->numeric()
                            ->suffix('ms')
                            ->default(null),

                        Forms\Components\DateTimePicker::make('checked_at')
                            ->label('Vérifié le')
                            ->required()
                            ->default(now())
                            ->seconds(false),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Détails & Métadonnées')
                    ->schema([
                        Forms\Components\Textarea::make('details')
                            ->label('Détails')
                            ->rows(5)
                            ->columnSpanFull()
                            ->helperText('Message d\'erreur ou information complémentaire'),

                        Forms\Components\KeyValue::make('metadata')
                            ->label('Métadonnées (JSON)')
                            ->columnSpanFull()
                            ->helperText('Données supplémentaires au format clé-valeur'),
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

                Tables\Columns\BadgeColumn::make('check_type')
                    ->label('Type')
                    ->colors([
                        'primary' => 'ping',
                        'success' => 'database',
                        'warning' => 'storage',
                        'info' => 'api',
                        'secondary' => 'ssl',
                        'gray' => 'dns',
                        'danger' => 'performance',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'ping' => 'Ping',
                        'database' => 'Database',
                        'storage' => 'Storage',
                        'api' => 'API',
                        'ssl' => 'SSL',
                        'dns' => 'DNS',
                        'performance' => 'Performance',
                        default => $state,
                    }),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Statut')
                    ->colors([
                        'success' => 'healthy',
                        'warning' => 'degraded',
                        'danger' => 'unhealthy',
                        'secondary' => 'unknown',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'healthy' => '✓ Sain',
                        'degraded' => '⚠ Dégradé',
                        'unhealthy' => '✗ Défaillant',
                        'unknown' => '? Inconnu',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('response_time_ms')
                    ->label('Temps (ms)')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state ? $state . ' ms' : '-')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state < 500 ? 'success' : ($state < 1000 ? 'warning' : 'danger'))),

                Tables\Columns\TextColumn::make('checked_at')
                    ->label('Vérifié le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn ($record) => $record->checked_at?->diffForHumans()),

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

                Tables\Filters\SelectFilter::make('check_type')
                    ->label('Type de Check')
                    ->options([
                        'ping' => 'Ping',
                        'database' => 'Database',
                        'storage' => 'Storage',
                        'api' => 'API',
                        'ssl' => 'SSL',
                        'dns' => 'DNS',
                        'performance' => 'Performance',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'healthy' => 'Sain',
                        'degraded' => 'Dégradé',
                        'unhealthy' => 'Défaillant',
                        'unknown' => 'Inconnu',
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
            ->defaultSort('checked_at', 'desc');
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
            'index' => Pages\ListTenantHealthChecks::route('/'),
            'create' => Pages\CreateTenantHealthCheck::route('/create'),
            'view' => Pages\ViewTenantHealthCheck::route('/{record}'),
            'edit' => Pages\EditTenantHealthCheck::route('/{record}/edit'),
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
