<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Filament\Resources\TenantResource\RelationManagers;
use App\Models\SubscriptionPlan;
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
                                            ->helperText('Code unique pour identifier le tenant (utilisé dans les URLs)')
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\TextInput::make('name')
                                            ->label('Nom de l\'établissement')
                                            ->required()
                                            ->maxLength(255)
                                            ->placeholder('ex: Lycée de Yopougon')
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\TextInput::make('subdomain')
                                            ->label('Sous-domaine')
                                            ->required()
                                            ->unique(ignoreRecord: true)
                                            ->maxLength(100)
                                            ->prefix('https://')
                                            ->suffix('.klassci.com')
                                            ->placeholder('lycee-yop')
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),
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
                                            ->placeholder('c2569688c_lycee_yop')
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\Textarea::make('database_credentials')
                                            ->label('Credentials (JSON)')
                                            ->required()
                                            ->rows(4)
                                            ->placeholder('{"host":"localhost","port":3306,"username":"...","password":"..."}')
                                            ->helperText('Format JSON avec host, port, username, password')
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing)
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

                                Forms\Components\Section::make('Token API Master')
                                    ->description('Token permettant au tenant d\'appeler l\'API Master. Générez-le depuis la page de détail (bouton "Générer le token API"), puis copiez le bloc .env fourni.')
                                    ->schema([
                                        Forms\Components\TextInput::make('api_token')
                                            ->label('Token API actuel')
                                            ->disabled()
                                            ->placeholder('Aucun token généré — utilisez le bouton sur la page de détail')
                                            ->helperText(fn ($record) => $record?->api_token_created_at
                                                ? 'Généré le ' . $record->api_token_created_at->format('d/m/Y à H:i')
                                                : null)
                                            ->suffixIcon(fn ($record) => $record?->api_token ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                                            ->suffixIconColor(fn ($record) => $record?->api_token ? 'success' : 'danger'),

                                        Forms\Components\Placeholder::make('env_config')
                                            ->label('Bloc .env à copier dans le tenant')
                                            ->content(function ($record) {
                                                if (!$record?->api_token) {
                                                    return '(Générez d\'abord un token)';
                                                }
                                                $appUrl = config('app.url');
                                                return implode("\n", [
                                                    "MASTER_API_URL={$appUrl}/api",
                                                    "MASTER_API_TOKEN={$record->api_token}",
                                                    "TENANT_CODE={$record->code}",
                                                ]);
                                            })
                                            ->helperText('Copiez ce bloc dans le fichier .env du tenant puis faites php artisan config:clear'),
                                    ])->columns(1),

                                Forms\Components\Section::make('Git & Déploiement')
                                    ->extraAttributes(['style' => 'overflow: visible;'])
                                    ->schema([
                                        Forms\Components\Select::make('git_branch')
                                            ->label('Branche Git')
                                            ->required()
                                            ->default('presentation')
                                            ->searchable()
                                            ->getSearchResultsUsing(function (string $search) {
                                                $branches = self::fetchGithubBranches();
                                                return collect($branches)
                                                    ->filter(fn ($b) => str_contains(strtolower($b), strtolower($search)))
                                                    ->mapWithKeys(fn ($b) => [$b => $b])
                                                    ->toArray();
                                            })
                                            ->getOptionLabelUsing(fn ($value) => $value)
                                            ->options(fn () => collect(self::fetchGithubBranches())
                                                ->mapWithKeys(fn ($b) => [$b => $b])
                                                ->toArray())
                                            ->helperText('Branches chargées depuis GitHub · KLASSCIv2')
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

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
                                            ->default('active')
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\Select::make('subscription_plan_id')
                                            ->label('Plan Tarifaire')
                                            ->relationship('subscriptionPlan', 'name')
                                            ->options(
                                                SubscriptionPlan::active()
                                                    ->ordered()
                                                    ->get()
                                                    ->mapWithKeys(fn ($p) => [
                                                        $p->id => $p->name . ' — ' . number_format($p->monthly_fee, 0, ',', ' ') . ' FCFA/mois',
                                                    ])
                                            )
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->afterStateUpdated(function ($state, callable $set) {
                                                if (! $state) return;
                                                $plan = SubscriptionPlan::find($state);
                                                if (! $plan) return;
                                                $set('plan', $plan->slug);
                                                $set('monthly_fee', $plan->monthly_fee);
                                                $set('max_users', $plan->max_users);
                                                $set('max_staff', $plan->max_staff);
                                                $set('max_students', $plan->max_students);
                                                $set('max_inscriptions_per_year', $plan->max_inscriptions_per_year);
                                                $set('max_storage_mb', $plan->max_storage_mb);
                                            })
                                            ->helperText('Sélectionnez un plan pour remplir automatiquement les limites.')
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\Hidden::make('plan')
                                            ->default('free'),

                                        Forms\Components\TextInput::make('monthly_fee')
                                            ->label('Frais Mensuels (FCFA)')
                                            ->required()
                                            ->numeric()
                                            ->prefix('FCFA')
                                            ->default(0)
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),
                                    ])->columns(3),

                                Forms\Components\Section::make('Période d\'Abonnement')
                                    ->schema([
                                        Forms\Components\DatePicker::make('subscription_start_date')
                                            ->label('Début de l\'abonnement')
                                            ->default(now())
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\DatePicker::make('subscription_end_date')
                                            ->label('Fin de l\'abonnement')
                                            ->after('subscription_start_date')
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),
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
                                            ->minValue(1)
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\TextInput::make('max_staff')
                                            ->label('Max Personnel')
                                            ->required()
                                            ->numeric()
                                            ->default(5)
                                            ->minValue(1)
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\TextInput::make('max_students')
                                            ->label('Max Étudiants')
                                            ->required()
                                            ->numeric()
                                            ->default(50)
                                            ->minValue(1)
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\TextInput::make('max_inscriptions_per_year')
                                            ->label('Max Inscriptions/An')
                                            ->required()
                                            ->numeric()
                                            ->default(50)
                                            ->minValue(1)
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\TextInput::make('max_storage_mb')
                                            ->label('Stockage Max (MB)')
                                            ->required()
                                            ->numeric()
                                            ->suffix('MB')
                                            ->default(512)
                                            ->minValue(1)
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),
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
                                            ->maxLength(255)
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\TextInput::make('admin_email')
                                            ->label('Email Administrateur')
                                            ->email()
                                            ->maxLength(255)
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\TextInput::make('support_email')
                                            ->label('Email Support')
                                            ->email()
                                            ->maxLength(255)
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\TextInput::make('phone')
                                            ->label('Téléphone')
                                            ->tel()
                                            ->maxLength(255)
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\Textarea::make('address')
                                            ->label('Adresse Complète')
                                            ->rows(3)
                                            ->columnSpanFull()
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing),

                                        Forms\Components\Textarea::make('metadata')
                                            ->label('Métadonnées (JSON)')
                                            ->rows(3)
                                            ->columnSpanFull()
                                            ->helperText('Données supplémentaires au format JSON')
                                            ->disabled(fn ($livewire) => property_exists($livewire, 'isEditing') && ! $livewire->isEditing)
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

                Tables\Columns\TextColumn::make('current_inscriptions_per_year')
                    ->label('Inscrits / Max')
                    ->formatStateUsing(fn (Tenant $record): string =>
                        number_format($record->current_inscriptions_per_year) . ' / ' . number_format($record->max_inscriptions_per_year)
                    )
                    ->badge()
                    ->color(fn (Tenant $record): string =>
                        $record->current_inscriptions_per_year > $record->max_inscriptions_per_year ? 'danger' : 'success'
                    )
                    ->sortable(),

                Tables\Columns\IconColumn::make('quota_ok')
                    ->label('Quota')
                    ->getStateUsing(fn (Tenant $record): bool => !$record->isOverQuota())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-exclamation-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->tooltip(fn (Tenant $record): string => $record->isOverQuota() ? 'Quota dépassé' : 'Quota OK'),

                Tables\Columns\TextColumn::make('subscription_end_date')
                    ->label('Expiration')
                    ->date('d/m/Y')
                    ->sortable()
                    ->badge()
                    ->color(fn ($record) => match(true) {
                        !$record->subscription_end_date => 'gray',
                        $record->subscription_end_date->isPast() => 'danger',
                        now()->diffInDays($record->subscription_end_date, false) <= 30 => 'warning',
                        default => 'success',
                    })
                    ->formatStateUsing(function ($state, $record) {
                        if (!$state) return '—';
                        $days = now()->diffInDays($state, false);
                        if ($days < 0) return 'Expiré';
                        if ($days <= 30) return "Dans {$days}j";
                        return $state->format('d/m/Y');
                    }),

                Tables\Columns\TextColumn::make('last_deployed_at')
                    ->label('Déployé')
                    ->since()
                    ->sortable()
                    ->toggleable()
                    ->color('gray'),

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
                Tables\Actions\ViewAction::make(),
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
            'view' => Pages\ViewTenant::route('/{record}'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }

    /**
     * Charge les branches distantes depuis l'API GitHub publique.
     * Cache 5 minutes pour éviter les appels répétés.
     * Branches de production en tête, puis les autres triées alphabétiquement.
     */
    public static function fetchGithubBranches(): array
    {
        return \Cache::remember('github_klassci_branches', 300, function () {
            try {
                $response = \Http::timeout(5)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get('https://api.github.com/repos/James10192/KLASSCIv2/branches', [
                        'per_page' => 100,
                    ]);

                if (!$response->successful()) {
                    return self::fallbackBranches();
                }

                $allBranches = collect($response->json())
                    ->pluck('name')
                    ->filter()
                    ->values();

                // Branches de production en tête (dans cet ordre)
                $productionBranches = ['presentation', 'hetec', 'esbtp-abidjan', 'esbtp-yakro', 'rostan', 'imertel', 'IFRAN'];

                $sorted = collect($productionBranches)
                    ->filter(fn ($b) => $allBranches->contains($b))
                    ->merge(
                        $allBranches
                            ->reject(fn ($b) => in_array($b, $productionBranches))
                            ->sort()
                            ->values()
                    )
                    ->toArray();

                return $sorted;

            } catch (\Exception $e) {
                return self::fallbackBranches();
            }
        });
    }

    private static function fallbackBranches(): array
    {
        return ['presentation', 'hetec', 'esbtp-abidjan', 'esbtp-yakro', 'rostan', 'imertel', 'IFRAN', 'main'];
    }
}
