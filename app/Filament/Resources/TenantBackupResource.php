<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantBackupResource\Pages;
use App\Models\TenantBackup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Artisan;

class TenantBackupResource extends Resource
{
    protected static ?string $model = TenantBackup::class;
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';
    protected static ?string $navigationLabel = 'Backups';
    protected static ?string $modelLabel = 'Backup';
    protected static ?string $pluralModelLabel = 'Backups';
    protected static ?string $navigationGroup = 'Monitoring';
    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $failedCount = TenantBackup::where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return $failedCount > 0 ? (string) $failedCount : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informations Backup')->schema([
                Forms\Components\Select::make('tenant_id')
                    ->label('Établissement')
                    ->relationship('tenant', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('type')
                    ->label('Type')
                    ->required()
                    ->options(['full' => 'Complet', 'database_only' => 'Base de données', 'files_only' => 'Fichiers'])
                    ->default('full'),
                Forms\Components\TextInput::make('backup_path')
                    ->label('Chemin backup')
                    ->required()
                    ->maxLength(500),
                Forms\Components\TextInput::make('size_bytes')
                    ->label('Taille (bytes)')
                    ->numeric(),
                Forms\Components\Select::make('status')
                    ->label('Statut')
                    ->required()
                    ->options(['pending' => 'En attente', 'in_progress' => 'En cours', 'completed' => 'Terminé', 'failed' => 'Échoué'])
                    ->default('pending'),
                Forms\Components\DateTimePicker::make('expires_at')
                    ->label('Expire le')
                    ->seconds(false),
            ])->columns(2),
            Forms\Components\Section::make('Détails Techniques')->schema([
                Forms\Components\Textarea::make('database_backup_path')->label('Chemin DB backup')->rows(2)->columnSpanFull(),
                Forms\Components\Textarea::make('storage_backup_path')->label('Chemin storage backup')->rows(2)->columnSpanFull(),
                Forms\Components\Textarea::make('error_message')->label('Message d\'erreur')->rows(3)->columnSpanFull(),
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

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'full' => 'primary',
                        'database_only' => 'success',
                        'files_only' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'full' => 'Complet',
                        'database_only' => 'BDD',
                        'files_only' => 'Fichiers',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'in_progress' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'En attente',
                        'in_progress' => 'En cours',
                        'completed' => 'Terminé',
                        'failed' => 'Échoué',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('size_bytes')
                    ->label('Taille')
                    ->formatStateUsing(function ($state): string {
                        if (!$state) return '—';
                        if ($state >= 1073741824) return number_format($state / 1073741824, 2) . ' GB';
                        if ($state >= 1048576) return number_format($state / 1048576, 2) . ' MB';
                        return number_format($state / 1024, 1) . ' KB';
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expire le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->description(fn ($record) => $record->expires_at?->diffForHumans()),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tenant_id')
                    ->label('Établissement')
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options(['full' => 'Complet', 'database_only' => 'BDD', 'files_only' => 'Fichiers']),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options(['pending' => 'En attente', 'in_progress' => 'En cours', 'completed' => 'Terminé', 'failed' => 'Échoué']),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('backupAll')
                    ->label('Backup tous les tenants')
                    ->icon('heroicon-o-circle-stack')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Lancer un backup groupé')
                    ->modalDescription('Sélectionnez le type de backup à lancer sur tous les tenants actifs.')
                    ->form([
                        Forms\Components\Select::make('type')
                            ->label('Type de backup')
                            ->options([
                                'database_only' => 'Base de données uniquement (rapide)',
                                'full' => 'Backup complet (DB + fichiers)',
                            ])
                            ->default('database_only')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            Artisan::call('tenant:backup', ['--type' => $data['type']]);

                            Notification::make()
                                ->title('Backup lancé')
                                ->body('Le backup de tous les tenants actifs a été déclenché.')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Erreur')
                                ->body('Impossible de lancer le backup : ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('retry')
                    ->label('Re-faire')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Relancer ce backup')
                    ->modalDescription(fn ($record) => "Relancer un backup {$record->type} pour le tenant « {$record->tenant?->name} » ?")
                    ->action(function ($record): void {
                        try {
                            $args = ['tenant' => $record->tenant?->code, '--type' => $record->type];
                            Artisan::call('tenant:backup', $args);

                            Notification::make()
                                ->title('Backup relancé')
                                ->body("Le backup pour « {$record->tenant?->name} » a été déclenché.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Erreur')
                                ->body('Impossible de relancer le backup : ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn ($record) => in_array($record->status, ['failed', 'completed'])),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenantBackups::route('/'),
            'create' => Pages\CreateTenantBackup::route('/create'),
            'view' => Pages\ViewTenantBackup::route('/{record}'),
            'edit' => Pages\EditTenantBackup::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
