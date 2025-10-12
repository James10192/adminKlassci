<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Filament\Resources\TenantResource\RelationManagers;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    // Navigation customization
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Établissements';

    protected static ?string $modelLabel = 'établissement';

    protected static ?string $pluralModelLabel = 'établissements';

    protected static ?string $navigationGroup = 'Gestion SaaS';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'active')->count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'success';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Informations')
                    ->tabs([
                        // Onglet 1: Informations Générales
                        Forms\Components\Tabs\Tab::make('Informations Générales')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Forms\Components\Section::make()
                                    ->schema([
                                        Forms\Components\TextInput::make('code')
                                            ->label('Code Unique')
                                            ->required()
                                            ->unique(ignoreRecord: true)
                                            ->maxLength(50)
                                            ->placeholder('ex: lycee-yop')
                                            ->helperText('Code unique pour identifier le tenant (utilisé dans les URLs)'),

                                        Forms\Components\TextInput::make('name')
                                            ->label('Nom de l\'établissement')
                                            ->required()
                                            ->maxLength(255)
                                            ->placeholder('ex: Lycée de Yopougon'),

                                        Forms\Components\TextInput::make('subdomain')
                                            ->label('Sous-domaine')
                                            ->required()
                                            ->unique(ignoreRecord: true)
                                            ->maxLength(100)
                                            ->prefix('https://')
                                            ->suffix('.klassci.com')
                                            ->placeholder('lycee-yop'),
                                    ])->columns(2),
                            ]),

                        // Onglet 2: Configuration Technique
                        Forms\Components\Tabs\Tab::make('Configuration Technique')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema([
                                Forms\Components\Section::make('Base de Données')
                                    ->schema([
                                        Forms\Components\TextInput::make('database_name')
                                            ->label('Nom de la base de données')
                                            ->required()
                                            ->maxLength(100)
                                            ->placeholder('c2569688c_lycee_yop'),

                                        Forms\Components\Textarea::make('database_credentials')
                                            ->label('Credentials (JSON)')
                                            ->required()
                                            ->rows(4)
                                            ->placeholder('{"host":"localhost","port":3306,"username":"...","password":"..."}')
                                            ->helperText('Format JSON avec host, port, username, password')
                                            ->dehydrateStateUsing(function ($state) {
                                                // Convertir le JSON string en array pour éviter double encoding
                                                if (is_string($state)) {
                                                    $decoded = json_decode($state, true);
                                                    return $decoded ?? $state;
                                                }
                                                return $state;
                                            })
                                            ->formatStateUsing(function ($state) {
                                                // Afficher le JSON formaté lors de l'édition
                                                if (is_array($state)) {
                                                    return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                                }
                                                return $state;
                                            }),
                                    ])->columns(1),

                                Forms\Components\Section::make('Git & Déploiement')
                                    ->schema([
                                        Forms\Components\TextInput::make('git_branch')
                                            ->label('Branche Git')
                                            ->required()
                                            ->maxLength(100)
                                            ->default('main'),

                                        Forms\Components\TextInput::make('git_commit_hash')
                                            ->label('Dernier Commit Hash')
                                            ->maxLength(40)
                                            ->disabled()
                                            ->placeholder('Sera rempli automatiquement'),

                                        Forms\Components\DateTimePicker::make('last_deployed_at')
                                            ->label('Dernier Déploiement')
                                            ->disabled(),
                                    ])->columns(3),
                            ]),

                        // Onglet 3: Abonnement & Plan
                        Forms\Components\Tabs\Tab::make('Abonnement')
                            ->icon('heroicon-o-credit-card')
                            ->schema([
                                Forms\Components\Section::make()
                                    ->schema([
                                        Forms\Components\Select::make('status')
                                            ->label('Statut')
                                            ->required()
                                            ->options([
                                                'active' => 'Actif',
                                                'suspended' => 'Suspendu',
                                                'inactive' => 'Inactif',
                                            ])
                                            ->default('active'),

                                        Forms\Components\Select::make('plan')
                                            ->label('Plan Tarifaire')
                                            ->required()
                                            ->options([
                                                'free' => 'Free',
                                                'essentiel' => 'Essentiel',
                                                'professional' => 'Professional',
                                                'elite' => 'Elite',
                                            ])
                                            ->default('free')
                                            ->live()
                                            ->afterStateUpdated(function ($state, callable $set) {
                                                $plans = [
                                                    'free' => ['fee' => 0, 'users' => 5, 'inscriptions' => 50, 'storage' => 512],
                                                    'essentiel' => ['fee' => 100000, 'users' => 20, 'inscriptions' => 700, 'storage' => 2048],
                                                    'professional' => ['fee' => 200000, 'users' => 30, 'inscriptions' => 3000, 'storage' => 5120],
                                                    'elite' => ['fee' => 400000, 'users' => 999999, 'inscriptions' => 999999, 'storage' => 20480],
                                                ];
                                                if (isset($plans[$state])) {
                                                    $set('monthly_fee', $plans[$state]['fee']);
                                                    $set('max_users', $plans[$state]['users']);
                                                    $set('max_inscriptions_per_year', $plans[$state]['inscriptions']);
                                                    $set('max_storage_mb', $plans[$state]['storage']);
                                                }
                                            }),

                                        Forms\Components\TextInput::make('monthly_fee')
                                            ->label('Frais Mensuels (FCFA)')
                                            ->required()
                                            ->numeric()
                                            ->prefix('FCFA')
                                            ->default(0),
                                    ])->columns(3),

                                Forms\Components\Section::make('Période d\'Abonnement')
                                    ->schema([
                                        Forms\Components\DatePicker::make('subscription_start_date')
                                            ->label('Début de l\'abonnement')
                                            ->default(now()),

                                        Forms\Components\DatePicker::make('subscription_end_date')
                                            ->label('Fin de l\'abonnement')
                                            ->after('subscription_start_date'),
                                    ])->columns(2),
                            ]),

                        // Onglet 4: Limites & Quotas
                        Forms\Components\Tabs\Tab::make('Limites')
                            ->icon('heroicon-o-chart-bar')
                            ->badge(fn ($record) => $record && $record->isOverQuota() ? '!' : null)
                            ->badgeColor('danger')
                            ->schema([
                                Forms\Components\Section::make('Limites Autorisées')
                                    ->schema([
                                        Forms\Components\TextInput::make('max_users')
                                            ->label('Max Utilisateurs')
                                            ->required()
                                            ->numeric()
                                            ->default(5)
                                            ->minValue(1),

                                        Forms\Components\TextInput::make('max_staff')
                                            ->label('Max Personnel')
                                            ->required()
                                            ->numeric()
                                            ->default(5)
                                            ->minValue(1),

                                        Forms\Components\TextInput::make('max_students')
                                            ->label('Max Étudiants')
                                            ->required()
                                            ->numeric()
                                            ->default(50)
                                            ->minValue(1),

                                        Forms\Components\TextInput::make('max_inscriptions_per_year')
                                            ->label('Max Inscriptions/An')
                                            ->required()
                                            ->numeric()
                                            ->default(50)
                                            ->minValue(1),

                                        Forms\Components\TextInput::make('max_storage_mb')
                                            ->label('Stockage Max (MB)')
                                            ->required()
                                            ->numeric()
                                            ->suffix('MB')
                                            ->default(512)
                                            ->minValue(1),
                                    ])->columns(3),

                                Forms\Components\Section::make('Utilisation Actuelle (Auto-calculé)')
                                    ->schema([
                                        Forms\Components\TextInput::make('current_users')
                                            ->label('Utilisateurs Actuels')
                                            ->numeric()
                                            ->default(0)
                                            ->disabled(),

                                        Forms\Components\TextInput::make('current_staff')
                                            ->label('Personnel Actuel')
                                            ->numeric()
                                            ->default(0)
                                            ->disabled(),

                                        Forms\Components\TextInput::make('current_students')
                                            ->label('Étudiants Actuels (avec compte)')
                                            ->numeric()
                                            ->default(0)
                                            ->disabled()
                                            ->helperText('Étudiants ayant un compte utilisateur'),

                                        Forms\Components\TextInput::make('current_inscriptions_per_year')
                                            ->label('Inscriptions Année Courante')
                                            ->numeric()
                                            ->default(0)
                                            ->disabled()
                                            ->helperText('Inscriptions actives pour l\'année universitaire en cours'),

                                        Forms\Components\TextInput::make('current_storage_mb')
                                            ->label('Stockage Utilisé (MB)')
                                            ->numeric()
                                            ->suffix('MB')
                                            ->default(0)
                                            ->disabled(),
                                    ])->columns(3),
                            ]),

                        // Onglet 5: Contacts & Infos
                        Forms\Components\Tabs\Tab::make('Contacts')
                            ->icon('heroicon-o-user-group')
                            ->schema([
                                Forms\Components\Section::make()
                                    ->schema([
                                        Forms\Components\TextInput::make('admin_name')
                                            ->label('Nom de l\'administrateur')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('admin_email')
                                            ->label('Email Administrateur')
                                            ->email()
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('support_email')
                                            ->label('Email Support')
                                            ->email()
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('phone')
                                            ->label('Téléphone')
                                            ->tel()
                                            ->maxLength(255),

                                        Forms\Components\Textarea::make('address')
                                            ->label('Adresse Complète')
                                            ->rows(3)
                                            ->columnSpanFull(),

                                        Forms\Components\Textarea::make('metadata')
                                            ->label('Métadonnées (JSON)')
                                            ->rows(3)
                                            ->columnSpanFull()
                                            ->helperText('Données supplémentaires au format JSON')
                                            ->dehydrateStateUsing(function ($state) {
                                                // Convertir le JSON string en array pour éviter double encoding
                                                if (is_string($state) && !empty($state)) {
                                                    $decoded = json_decode($state, true);
                                                    return $decoded ?? $state;
                                                }
                                                return $state;
                                            })
                                            ->formatStateUsing(function ($state) {
                                                // Afficher le JSON formaté lors de l'édition
                                                if (is_array($state)) {
                                                    return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                                }
                                                return $state;
                                            }),
                                    ])->columns(2),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Code copié!')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('subdomain')
                    ->label('URL')
                    ->searchable()
                    ->url(fn ($record) => "https://{$record->subdomain}.klassci.com", true)
                    ->color('primary')
                    ->icon('heroicon-o-link'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        'inactive' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Actif',
                        'suspended' => 'Suspendu',
                        'inactive' => 'Inactif',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('plan')
                    ->label('Plan')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'elite' => 'success',
                        'professional' => 'info',
                        'essentiel' => 'warning',
                        'free' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('current_students')
                    ->label('Étudiants')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->description('(avec compte)')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('current_inscriptions_per_year')
                    ->label('Inscriptions')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->description('(année courante)'),

                Tables\Columns\TextColumn::make('current_staff')
                    ->label('Personnel')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_deployed_at')
                    ->label('Dernier Déploiement')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable()
                    ->since(),

                Tables\Columns\TextColumn::make('subscription_end_date')
                    ->label('Expiration')
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn ($record) => $record->subscription_end_date && $record->subscription_end_date->isPast() ? 'danger' : null),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Actif',
                        'suspended' => 'Suspendu',
                        'inactive' => 'Inactif',
                    ]),

                Tables\Filters\SelectFilter::make('plan')
                    ->label('Plan')
                    ->options([
                        'free' => 'Free',
                        'essentiel' => 'Essentiel',
                        'professional' => 'Professional',
                        'elite' => 'Elite',
                    ]),

                Tables\Filters\Filter::make('subscription_expired')
                    ->label('Abonnement expiré')
                    ->query(fn (Builder $query): Builder => $query->where('subscription_end_date', '<', now())),
            ])
            ->actions([
                Tables\Actions\Action::make('update_stats')
                    ->label('Mettre à jour les stats')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => "Mettre à jour les statistiques de {$record->name}")
                    ->modalDescription('Cette action va recalculer les statistiques d\'utilisation (utilisateurs, étudiants, personnel, stockage) depuis la base de données du tenant.')
                    ->modalSubmitActionLabel('Mettre à jour')
                    ->action(function ($record) {
                        try {
                            // Exécuter la commande tenant:update-stats
                            $exitCode = \Artisan::call('tenant:update-stats', [
                                'tenant' => $record->code,
                            ]);

                            // Récupérer l'output de la commande
                            $output = \Artisan::output();

                            // Vérifier si la commande a échoué
                            if ($exitCode !== 0 || str_contains($output, '❌') || str_contains($output, 'Erreur')) {
                                \Filament\Notifications\Notification::make()
                                    ->danger()
                                    ->title('Erreur de mise à jour')
                                    ->body("La mise à jour a échoué. Vérifiez les credentials de la base de données.")
                                    ->persistent()
                                    ->send();
                                return;
                            }

                            // Rafraîchir le record depuis la BDD pour voir les nouvelles valeurs
                            $record->refresh();

                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Statistiques mises à jour')
                                ->body("Les statistiques de {$record->name} ont été mises à jour avec succès.")
                                ->send();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Erreur de mise à jour')
                                ->body("Erreur : {$e->getMessage()}")
                                ->persistent()
                                ->send();
                        }
                    })
                    ->visible(fn ($record) => $record->status === 'active'),

                Tables\Actions\Action::make('deploy')
                    ->label('Déployer')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => "Déployer {$record->name}")
                    ->modalDescription('Cette action va déployer les dernières mises à jour depuis GitHub vers le serveur de production.')
                    ->modalSubmitActionLabel('Déployer')
                    ->action(function ($record) {
                        try {
                            // Exécuter la commande tenant:deploy
                            \Artisan::call('tenant:deploy', [
                                'tenant' => $record->code,
                            ]);

                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Déploiement démarré')
                                ->body("Le déploiement de {$record->name} a été lancé avec succès.")
                                ->send();

                            // Rediriger vers la page de déploiements pour voir le statut
                            return redirect()->route('filament.admin.resources.tenant-deployments.index');
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Erreur de déploiement')
                                ->body("Erreur : {$e->getMessage()}")
                                ->persistent()
                                ->send();
                        }
                    })
                    ->visible(fn ($record) => $record->status === 'active'),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DeploymentsRelationManager::class,
            RelationManagers\HealthChecksRelationManager::class,
            RelationManagers\BackupsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
