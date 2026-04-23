<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Models\SubscriptionPlan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Plans d\'abonnement';

    protected static ?string $modelLabel = 'plan';

    protected static ?string $pluralModelLabel = 'plans d\'abonnement';

    protected static ?string $navigationGroup = 'Gestion SaaS';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations du plan')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom du plan')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('ex: PRO'),

                        Forms\Components\TextInput::make('slug')
                            ->label('Identifiant unique')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(50)
                            ->placeholder('ex: pro')
                            ->helperText('Utilisé en interne. Lettres minuscules, tirets uniquement.'),

                        Forms\Components\TextInput::make('target_segment')
                            ->label('Segment cible')
                            ->maxLength(100)
                            ->placeholder('ex: Supérieur émergent (500 élèves max)')
                            ->helperText('À qui s\'adresse ce plan ? Utile pour la page pricing web.'),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Tarification — Formule Signature')
                    ->description('Grille 2026 : 1ère année (setup + abonnement) / récurrent annuel / mensuel (+15% sur annuel).')
                    ->schema([
                        Forms\Components\TextInput::make('first_year_fee')
                            ->label('Prix 1ère année')
                            ->required()
                            ->numeric()
                            ->prefix('FCFA')
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Setup + abonnement année 1 (ex: 988 000 pour Essentiel)'),

                        Forms\Components\TextInput::make('annual_fee')
                            ->label('Prix annuel récurrent')
                            ->required()
                            ->numeric()
                            ->prefix('FCFA')
                            ->default(0)
                            ->minValue(0)
                            ->helperText('À partir de la 2ème année (ex: 700 000 pour Essentiel)'),

                        Forms\Components\TextInput::make('monthly_fee')
                            ->label('Tarif mensuel')
                            ->required()
                            ->numeric()
                            ->prefix('FCFA')
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Recommandé : annuel × 1.15 / 12 (+15% premium mensuel)'),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Ordre d\'affichage')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Les plans sont triés par ordre croissant.'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Plan actif')
                            ->default(true)
                            ->helperText('Les plans inactifs ne peuvent pas être assignés à de nouveaux tenants.'),
                    ])->columns(3),

                Forms\Components\Section::make('Service & SLA')
                    ->schema([
                        Forms\Components\TextInput::make('whatsapp_types')
                            ->label('Types de notifications WhatsApp')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(6)
                            ->suffix('types')
                            ->helperText('Essentiel=3, PRO=5, ELITE=6'),

                        Forms\Components\TextInput::make('sla_response_hours')
                            ->label('SLA réponse support (heures)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(72)
                            ->suffix('heures')
                            ->placeholder('24 = J+1')
                            ->helperText('Essentiel=24 (J+1), PRO=4, ELITE=2'),
                    ])->columns(2),

                Forms\Components\Section::make('Limites & Quotas')
                    ->description('Définissez les limites incluses dans ce plan. Utilisez 999999 pour "illimité".')
                    ->schema([
                        Forms\Components\TextInput::make('max_users')
                            ->label('Max utilisateurs')
                            ->required()
                            ->numeric()
                            ->default(5)
                            ->minValue(1)
                            ->suffix('comptes'),

                        Forms\Components\TextInput::make('max_staff')
                            ->label('Max personnel')
                            ->required()
                            ->numeric()
                            ->default(5)
                            ->minValue(1)
                            ->suffix('comptes'),

                        Forms\Components\TextInput::make('max_students')
                            ->label('Max étudiants')
                            ->required()
                            ->numeric()
                            ->default(50)
                            ->minValue(1)
                            ->suffix('étudiants'),

                        Forms\Components\TextInput::make('max_inscriptions_per_year')
                            ->label('Max inscriptions/an')
                            ->required()
                            ->numeric()
                            ->default(50)
                            ->minValue(1)
                            ->suffix('inscriptions'),

                        Forms\Components\TextInput::make('max_storage_mb')
                            ->label('Stockage max')
                            ->required()
                            ->numeric()
                            ->default(512)
                            ->minValue(1)
                            ->suffix('MB'),
                    ])->columns(3),

                Forms\Components\Section::make('Fonctionnalités incluses')
                    ->description('Cochez les fonctionnalités disponibles dans ce plan.')
                    ->schema([
                        Forms\Components\CheckboxList::make('features')
                            ->label('')
                            ->options([
                                'inscriptions'                      => 'Inscriptions',
                                'notes'                             => 'Notes & Évaluations',
                                'bulletins'                         => 'Bulletins PDF',
                                'paiements'                         => 'Gestion des paiements',
                                'emploi_temps'                      => 'Emploi du temps',
                                'presences'                         => 'Présences & assiduité',
                                'notifications_mail'                => 'Notifications mail',
                                'whatsapp_3_types'                  => 'WhatsApp 3 types',
                                'whatsapp_5_types'                  => 'WhatsApp 5 types',
                                'whatsapp_6_types'                  => 'WhatsApp 6 types',
                                'whatsapp_5000_app'                 => 'WhatsApp 5000 app',
                                'plateforme_cours_en_ligne'         => 'Plateforme cours en ligne',
                                'chatbot'                           => 'Chatbot IA Gemini',
                                'api'                               => 'Accès API REST',
                                'exports'                           => 'Exports Excel/PDF',
                                'personnalisation_design'           => 'Personnalisation du design',
                                'personnalisation_avancee'          => 'Personnalisation avancée',
                                'personnalisation_continue'         => 'Personnalisation continue',
                                'maintenance_annuelle'              => 'Maintenance à l\'année',
                                'mise_a_jour_ergonomie'             => 'Mise à jour de l\'ergonomie',
                                'formation_prise_en_main'           => 'Formation prise en main',
                                'nouvelles_features'                => 'Nouvelles fonctionnalités incluses',
                                'acces_gratuit_nouvelles_features'  => 'Accès gratuit nouvelles fonctionnalités',
                                'support_prioritaire'               => 'Support prioritaire',
                                'csm_dedie'                         => 'CSM dédié',
                            ])
                            ->columns(3)
                            ->gridDirection('row'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->width(40),

                Tables\Columns\TextColumn::make('name')
                    ->label('Plan')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('first_year_fee')
                    ->label('1ère année')
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format($state, 0, ',', ' ') . ' FCFA' : '—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('annual_fee')
                    ->label('Annuel')
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format($state, 0, ',', ' ') . ' FCFA' : '—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('monthly_fee')
                    ->label('Mensuel')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ') . ' FCFA')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('sla_label')
                    ->label('SLA')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('whatsapp_types')
                    ->label('WhatsApp')
                    ->formatStateUsing(fn ($state) => $state > 0 ? "{$state} types" : '—')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('max_users')
                    ->label('Utilisateurs')
                    ->formatStateUsing(fn ($state) => $state >= 999999 ? '∞' : $state)
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('max_inscriptions_per_year')
                    ->label('Inscriptions/an')
                    ->formatStateUsing(fn ($state) => $state >= 999999 ? '∞' : number_format($state, 0, ',', ' '))
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('max_storage_mb')
                    ->label('Stockage')
                    ->formatStateUsing(function ($state) {
                        if ($state >= 999999) return '∞';
                        if ($state >= 1024) return round($state / 1024, 1) . ' GB';
                        return $state . ' MB';
                    })
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('tenants_count')
                    ->label('Tenants affiliés')
                    ->counts('tenants')
                    ->badge()
                    ->color('primary')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\Action::make('toggle_active')
                    ->label(fn (SubscriptionPlan $record) => $record->is_active ? 'Désactiver' : 'Activer')
                    ->icon(fn (SubscriptionPlan $record) => $record->is_active ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color(fn (SubscriptionPlan $record) => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(function (SubscriptionPlan $record) {
                        $record->update(['is_active' => ! $record->is_active]);
                        Notification::make()
                            ->title($record->is_active ? 'Plan activé' : 'Plan désactivé')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (SubscriptionPlan $record) {
                        if ($record->tenants()->count() > 0) {
                            Notification::make()
                                ->title('Suppression impossible')
                                ->body('Ce plan est affilié à ' . $record->tenants()->count() . ' tenant(s). Réaffectez-les avant de supprimer.')
                                ->danger()
                                ->send();
                            $this->halt();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit'   => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}
