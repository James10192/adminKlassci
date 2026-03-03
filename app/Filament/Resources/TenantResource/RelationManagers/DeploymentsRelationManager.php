<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DeploymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'deployments';

    public function form(Form $form): Form
    {
        // Les deployments sont créés automatiquement par la commande tenant:deploy
        // Pas de formulaire de création/édition manuelle
        return $form->schema([]);
    }

    public function isReadOnly(): bool
    {
        return false; // On garde false pour permettre la suppression
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Informations générales')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('git_commit_hash')
                        ->label('Commit Hash')
                        ->copyable()
                        ->copyMessage('Hash copié!')
                        ->fontFamily('mono'),

                    Infolists\Components\TextEntry::make('git_branch')
                        ->label('Branche')
                        ->badge()
                        ->color('info'),

                    Infolists\Components\TextEntry::make('status')
                        ->label('Statut')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'success', 'completed' => 'success',
                            'in_progress'           => 'info',
                            'failed'                => 'danger',
                            'rolled_back'           => 'warning',
                            default                 => 'gray',
                        }),

                    Infolists\Components\TextEntry::make('duration_seconds')
                        ->label('Durée')
                        ->formatStateUsing(fn ($state) => $state ? $state . ' secondes' : 'N/A'),

                    Infolists\Components\TextEntry::make('started_at')
                        ->label('Démarré le')
                        ->dateTime('d/m/Y H:i:s'),

                    Infolists\Components\TextEntry::make('completed_at')
                        ->label('Terminé le')
                        ->dateTime('d/m/Y H:i:s'),
                ]),

            Infolists\Components\Section::make('Résultat')
                ->schema([
                    Infolists\Components\TextEntry::make('error_message')
                        ->label('Message d\'erreur')
                        ->color('danger')
                        ->visible(fn ($record) => !empty($record->error_message))
                        ->columnSpanFull(),

                    Infolists\Components\RepeatableEntry::make('deployment_log')
                        ->label('Étapes du déploiement')
                        ->schema([
                            Infolists\Components\TextEntry::make('step')
                                ->label('Étape')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'error'  => 'danger',
                                    default  => 'gray',
                                }),

                            Infolists\Components\TextEntry::make('status')
                                ->label('Statut')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'ok'     => 'success',
                                    'failed' => 'danger',
                                    default  => 'gray',
                                }),

                            Infolists\Components\TextEntry::make('duration_ms')
                                ->label('Durée')
                                ->formatStateUsing(fn ($state) => $state ? $state . ' ms' : 'N/A'),

                            Infolists\Components\TextEntry::make('output')
                                ->label('Output')
                                ->columnSpanFull()
                                ->fontFamily('mono')
                                ->limit(500),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('git_commit_hash')
            ->columns([
                Tables\Columns\TextColumn::make('git_commit_hash')
                    ->label('Commit Hash')
                    ->copyable()
                    ->copyMessage('Hash copié!')
                    ->limit(10)
                    ->tooltip(fn ($record) => $record->git_commit_hash),

                Tables\Columns\TextColumn::make('git_branch')
                    ->label('Branche')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success', 'completed' => 'success',
                        'in_progress'           => 'info',
                        'failed'                => 'danger',
                        'rolled_back'           => 'warning',
                        default                 => 'gray',
                    }),

                Tables\Columns\TextColumn::make('duration_seconds')
                    ->label('Durée')
                    ->formatStateUsing(fn ($state) => $state ? $state . 's' : 'N/A')
                    ->sortable(),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Démarré le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->since(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Terminé le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'completed' => 'Completed',
                        'in_progress' => 'In Progress',
                        'failed' => 'Failed',
                    ]),
                Tables\Filters\SelectFilter::make('git_branch')
                    ->options([
                        'main' => 'main',
                        'presentation' => 'presentation',
                        'production' => 'production',
                    ]),
                Tables\Filters\TrashedFilter::make()
            ])
            ->headerActions([
                Tables\Actions\Action::make('deploy')
                    ->label('Déployer')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Déployer le tenant')
                    ->modalDescription('Cette action va déployer la dernière version du code depuis Git vers ce tenant. Cette opération peut prendre plusieurs minutes.')
                    ->action(function ($livewire) {
                        $tenant = $livewire->ownerRecord;

                        try {
                            // Lancer le déploiement en arrière-plan
                            \Artisan::call('tenant:deploy', [
                                'tenant' => $tenant->code,
                            ]);

                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Déploiement démarré')
                                ->body('Le déploiement du tenant a été démarré. Consultez l\'onglet Deployments pour suivre la progression.')
                                ->send();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Erreur de déploiement')
                                ->body('Impossible de démarrer le déploiement: ' . $e->getMessage())
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
            ->defaultSort('started_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }
}
