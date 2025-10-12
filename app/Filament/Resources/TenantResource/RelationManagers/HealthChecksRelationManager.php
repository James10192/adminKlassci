<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class HealthChecksRelationManager extends RelationManager
{
    protected static string $relationship = 'healthChecks';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('label')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('check_type')
            ->columns([
                Tables\Columns\TextColumn::make('check_type')
                    ->label('Type de Check')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'http_status' => 'info',
                        'database_connection' => 'primary',
                        'disk_space' => 'warning',
                        'ssl_certificate' => 'success',
                        'application_errors' => 'danger',
                        'queue_workers' => 'secondary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'http_status' => 'HTTP Status',
                        'database_connection' => 'Database',
                        'disk_space' => 'Disk Space',
                        'ssl_certificate' => 'SSL Certificate',
                        'application_errors' => 'App Errors',
                        'queue_workers' => 'Queue Workers',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'critical' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'healthy' => 'Healthy',
                        'warning' => 'Warning',
                        'critical' => 'Critical',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('response_time_ms')
                    ->label('Temps de Réponse')
                    ->suffix(' ms')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('checked_at')
                    ->label('Vérifié le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->since(),

                Tables\Columns\TextColumn::make('details')
                    ->label('Détails')
                    ->limit(50)
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'healthy' => 'Healthy',
                        'warning' => 'Warning',
                        'critical' => 'Critical',
                    ]),
                Tables\Filters\SelectFilter::make('check_type')
                    ->options([
                        'http_status' => 'HTTP Status',
                        'database_connection' => 'Database',
                        'disk_space' => 'Disk Space',
                        'ssl_certificate' => 'SSL Certificate',
                        'application_errors' => 'App Errors',
                        'queue_workers' => 'Queue Workers',
                    ]),
                Tables\Filters\TrashedFilter::make()
            ])
            ->headerActions([
                // Health checks sont créés automatiquement par la commande tenant:health-check
                // Pas de création manuelle
            ])
            ->actions([
                Tables\Columns\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('checked_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }
}
