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
        // Les health checks sont générés automatiquement par la commande
        // Pas de formulaire de création/édition manuelle
        return $form->schema([]);
    }

    public function isReadOnly(): bool
    {
        return false; // On garde false pour permettre la suppression
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
                Tables\Actions\Action::make('run_health_check')
                    ->label('Exécuter Health Check')
                    ->icon('heroicon-o-heart')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Exécuter un Health Check')
                    ->modalDescription('Cette action va exécuter toutes les vérifications de santé pour ce tenant.')
                    ->action(function ($livewire) {
                        $tenant = $livewire->ownerRecord;

                        try {
                            $exitCode = \Artisan::call('tenant:health-check', [
                                'tenant' => $tenant->code,
                            ]);

                            $output = \Artisan::output();

                            if ($exitCode !== 0 || str_contains($output, '❌')) {
                                \Filament\Notifications\Notification::make()
                                    ->danger()
                                    ->title('Health Check échoué')
                                    ->body('Certaines vérifications ont échoué. Consultez les détails ci-dessous.')
                                    ->send();
                            } else {
                                \Filament\Notifications\Notification::make()
                                    ->success()
                                    ->title('Health Check exécuté')
                                    ->body('Toutes les vérifications ont été effectuées avec succès.')
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Erreur')
                                ->body('Impossible d\'exécuter le health check: ' . $e->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalHeading('Détails du Health Check')
                    ->modalContent(fn ($record) => view('filament.resources.tenant-resource.health-check-details', ['record' => $record])),
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
