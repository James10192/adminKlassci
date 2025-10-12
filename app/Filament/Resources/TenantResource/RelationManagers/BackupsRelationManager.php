<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BackupsRelationManager extends RelationManager
{
    protected static string $relationship = 'backups';

    public function form(Form $form): Form
    {
        // Les backups sont créés automatiquement par la commande tenant:backup
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
            ->recordTitleAttribute('file_name')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'full' => 'success',
                        'database_only' => 'info',
                        'files_only' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'full' => 'Full Backup',
                        'database_only' => 'Database Only',
                        'files_only' => 'Files Only',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('size_bytes')
                    ->label('Taille')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 1024 / 1024, 2) . ' MB' : 'N/A')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'in_progress' => 'info',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'completed' => 'Completed',
                        'in_progress' => 'In Progress',
                        'failed' => 'Failed',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->since(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expire le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'full' => 'Full Backup',
                        'database_only' => 'Database Only',
                        'files_only' => 'Files Only',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'completed' => 'Completed',
                        'in_progress' => 'In Progress',
                        'failed' => 'Failed',
                    ]),
                Tables\Filters\TrashedFilter::make()
            ])
            ->headerActions([
                Tables\Actions\Action::make('create_backup')
                    ->label('Créer un Backup')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Créer un Backup')
                    ->modalDescription('Cette action va créer une sauvegarde complète de ce tenant (base de données + fichiers).')
                    ->action(function ($livewire) {
                        $tenant = $livewire->ownerRecord;

                        try {
                            $exitCode = \Artisan::call('tenant:backup', [
                                'tenant' => $tenant->code,
                            ]);

                            $output = \Artisan::output();

                            if ($exitCode !== 0 || str_contains($output, '❌') || str_contains($output, 'Erreur')) {
                                \Filament\Notifications\Notification::make()
                                    ->danger()
                                    ->title('Backup échoué')
                                    ->body('La sauvegarde a échoué. Consultez les logs pour plus de détails.')
                                    ->persistent()
                                    ->send();
                            } else {
                                \Filament\Notifications\Notification::make()
                                    ->success()
                                    ->title('Backup créé')
                                    ->body('La sauvegarde a été créée avec succès.')
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Erreur')
                                ->body('Impossible de créer le backup: ' . $e->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }
}
