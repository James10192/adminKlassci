<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaasAdminResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SaasAdminResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Équipe Admin';

    protected static ?string $modelLabel = 'administrateur';

    protected static ?string $pluralModelLabel = 'administrateurs';

    protected static ?string $navigationGroup = 'Gestion SaaS';

    protected static ?int $navigationSort = 5;

    // Accessible uniquement aux super admins
    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identité')
                    ->icon('heroicon-o-user')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom complet')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label('Adresse e-mail')
                            ->email()
                            ->required()
                            ->unique(User::class, 'email', ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label('Téléphone')
                            ->tel()
                            ->maxLength(50),
                    ])->columns(3),

                Forms\Components\Section::make('Accès & Rôle')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        Forms\Components\Select::make('role')
                            ->label('Rôle')
                            ->required()
                            ->options([
                                'super_admin' => 'Super Admin — Accès complet',
                                'support' => 'Support — Lecture + actions opérationnelles',
                                'billing' => 'Billing — Gestion abonnements & factures',
                            ])
                            ->default('support')
                            ->native(false),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Compte actif')
                            ->default(true)
                            ->helperText('Un compte inactif ne peut pas se connecter.'),
                    ])->columns(2),

                Forms\Components\Section::make('Mot de passe')
                    ->icon('heroicon-o-lock-closed')
                    ->schema([
                        Forms\Components\TextInput::make('password')
                            ->label('Mot de passe')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->minLength(8)
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText('Minimum 8 caractères. Laissez vide pour ne pas changer.'),

                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('Confirmer le mot de passe')
                            ->password()
                            ->revealable()
                            ->same('password')
                            ->dehydrated(false)
                            ->required(fn (string $operation): bool => $operation === 'create'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Email copié'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Téléphone')
                    ->default('—'),

                Tables\Columns\TextColumn::make('role')
                    ->label('Rôle')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'support' => 'info',
                        'billing' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'super_admin' => 'Super Admin',
                        'support' => 'Support',
                        'billing' => 'Billing',
                        default => $state,
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('Rôle')
                    ->options([
                        'super_admin' => 'Super Admin',
                        'support' => 'Support',
                        'billing' => 'Billing',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut')
                    ->trueLabel('Actifs')
                    ->falseLabel('Inactifs'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('toggle_active')
                    ->label(fn (User $record): string => $record->is_active ? 'Désactiver' : 'Activer')
                    ->icon(fn (User $record): string => $record->is_active ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (User $record): string => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->hidden(fn (User $record): bool => $record->id === auth()->id()) // Can't deactivate self
                    ->action(fn (User $record) => $record->update(['is_active' => !$record->is_active])),
                Tables\Actions\DeleteAction::make()
                    ->hidden(fn (User $record): bool => $record->id === auth()->id()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading("Aucun administrateur")
            ->emptyStateDescription("Commencez par créer le premier membre de l'équipe.")
            ->emptyStateIcon('heroicon-o-users');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSaasAdmins::route('/'),
            'create' => Pages\CreateSaasAdmin::route('/create'),
            'edit' => Pages\EditSaasAdmin::route('/{record}/edit'),
        ];
    }
}
