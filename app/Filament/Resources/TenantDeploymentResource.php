<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantDeploymentResource\Pages;
use App\Models\Tenant;
use App\Models\TenantDeployment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;

class TenantDeploymentResource extends Resource
{
    protected static ?string $model = TenantDeployment::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Déploiements';

    protected static ?string $modelLabel = 'déploiement';

    protected static ?string $pluralModelLabel = 'déploiements';

    protected static ?string $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $inProgressCount = TenantDeployment::where('status', 'in_progress')->count();
        return $inProgressCount > 0 ? (string) $inProgressCount : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->columns([
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('git_branch')
                    ->label('Branche')
                    ->badge()
                    ->color('info')
                    ->searchable(),

                Tables\Columns\TextColumn::make('git_commit_hash')
                    ->label('Commit')
                    ->formatStateUsing(fn ($state) => $state ? substr($state, 0, 8) : '—')
                    ->copyable()
                    ->copyMessage('Hash copié!')
                    ->copyMessageDuration(1500)
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('commit_message')
                    ->label('Message commit')
                    ->getStateUsing(function ($record): string {
                        $log = $record->deployment_log;
                        if (!is_array($log)) return '—';
                        $commitStep = collect($log)->firstWhere('step', 'commit_info');
                        return $commitStep['commit']['message'] ?? '—';
                    })
                    ->wrap()
                    ->limit(60)
                    ->tooltip(function ($record): ?string {
                        $log = $record->deployment_log;
                        if (!is_array($log)) return null;
                        $commitStep = collect($log)->firstWhere('step', 'commit_info');
                        return $commitStep['commit']['message'] ?? null;
                    }),

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
                        'in_progress' => '⏳ En cours',
                        'success' => '✅ Réussi',
                        'failed' => '❌ Échoué',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Démarré')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('duration_seconds')
                    ->label('Durée')
                    ->formatStateUsing(fn ($state) => $state ? "{$state}s" : '—')
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
            ->headerActions([
                Tables\Actions\Action::make('deployNow')
                    ->label('Déployer un tenant')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('primary')
                    ->form([
                        Forms\Components\Select::make('tenant_code')
                            ->label('Tenant')
                            ->options(fn () => Tenant::active()->pluck('name', 'code')->toArray())
                            ->searchable()
                            ->required()
                            ->placeholder('Sélectionner un tenant...'),

                        Forms\Components\TextInput::make('branch')
                            ->label('Branche Git (optionnel)')
                            ->placeholder('Laisser vide = branche configurée du tenant')
                            ->helperText('Ex: main, presentation, esbtp-abidjan'),

                        Forms\Components\Toggle::make('skip_backup')
                            ->label('Passer le backup pré-déploiement')
                            ->default(false),

                        Forms\Components\Toggle::make('skip_migrations')
                            ->label('Passer les migrations')
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        $args = ['tenant' => $data['tenant_code']];
                        if (!empty($data['branch'])) {
                            $args['--branch'] = $data['branch'];
                        }
                        if ($data['skip_backup'] ?? false) {
                            $args['--skip-backup'] = true;
                        }
                        if ($data['skip_migrations'] ?? false) {
                            $args['--skip-migrations'] = true;
                        }

                        try {
                            Artisan::queue('tenant:deploy', $args);

                            Notification::make()
                                ->title('Déploiement lancé')
                                ->body("Le déploiement de « {$data['tenant_code']} » a été mis en file d'attente.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Erreur')
                                ->body('Impossible de lancer le déploiement : ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Voir détails'),

                Tables\Actions\Action::make('redeploy')
                    ->label('Re-déployer')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Re-déployer ce tenant')
                    ->modalDescription(fn ($record) => "Relancer le déploiement de « {$record->tenant?->name} » sur la branche « {$record->git_branch} » ?")
                    ->action(function ($record): void {
                        $args = [
                            'tenant' => $record->tenant?->code,
                            '--branch' => $record->git_branch,
                        ];

                        try {
                            Artisan::queue('tenant:deploy', $args);

                            Notification::make()
                                ->title('Re-déploiement lancé')
                                ->body("Le déploiement de « {$record->tenant?->name} » a été mis en file d'attente.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Erreur')
                                ->body('Impossible de lancer le déploiement : ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn ($record) => in_array($record->status, ['success', 'failed'])),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [];
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
        return false;
    }
}
