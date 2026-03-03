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
                            ->placeholder('ex: Professional'),

                        Forms\Components\TextInput::make('slug')
                            ->label('Identifiant unique')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(50)
                            ->placeholder('ex: professional')
                            ->helperText('Utilisé en interne. Lettres minuscules, tirets uniquement.'),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Tarification')
                    ->schema([
                        Forms\Components\TextInput::make('monthly_fee')
                            ->label('Tarif mensuel')
                            ->required()
                            ->numeric()
                            ->prefix('FCFA')
                            ->default(0)
                            ->minValue(0),

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
                                'inscriptions'       => 'Inscriptions',
                                'notes'              => 'Notes & Évaluations',
                                'bulletins'          => 'Bulletins PDF',
                                'paiements'          => 'Gestion des paiements',
                                'notifications'      => 'Notifications multi-canal',
                                'api'                => 'Accès API REST',
                                'exports'            => 'Exports Excel/PDF',
                                'emploi_temps'       => 'Emploi du temps',
                                'chatbot'            => 'Chatbot IA Gemini',
                                'support_prioritaire'=> 'Support prioritaire',
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

                Tables\Columns\TextColumn::make('monthly_fee')
                    ->label('Tarif mensuel')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ') . ' FCFA')
                    ->sortable(),

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
