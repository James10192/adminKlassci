<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Enums\GroupMemberRole;
use App\Models\GroupMember;
use App\Services\Group\GroupMemberAccessReset;
use App\Services\Group\TemporaryPasswordGenerator;
use App\Services\Saas\SaasCapabilities;
use Illuminate\Support\Facades\Gate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Membres du groupe';

    protected static ?string $recordTitleAttribute = 'name';

    public function isReadOnly(): bool
    {
        return ! Gate::allows(SaasCapabilities::MANAGE_GROUP_MEMBERS);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nom complet')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->live(onBlur: true)
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->helperText('Laissez vide si le membre n\'a pas d\'email — un nom d\'utilisateur sera généré automatiquement.'),

                Forms\Components\TextInput::make('username')
                    ->label('Nom d\'utilisateur')
                    ->unique(ignoreRecord: true)
                    ->maxLength(80)
                    ->alphaDash()
                    ->helperText('Généré automatiquement si vide et pas d\'email. Utilisé pour la connexion lorsqu\'il n\'y a pas d\'email.'),

                // Jamais de mot de passe ni hash dans la modale d'édition.
                // La création manuelle demeure disponible uniquement quand
                // l'invitation par email n'est pas possible.
                Forms\Components\TextInput::make('password')
                    ->label('Mot de passe initial')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->visible(fn (string $operation, Forms\Get $get): bool =>
                        $operation === 'create'
                        && (! config('group_portal.invite_flow_enabled') || blank($get('email'))))
                    ->required(fn (string $operation, Forms\Get $get): bool =>
                        $operation === 'create'
                        && (! config('group_portal.invite_flow_enabled') || blank($get('email'))))
                    ->dehydrated(fn (string $operation, $state): bool =>
                        $operation === 'create' && filled($state))
                    ->minLength(8)
                    ->helperText("À communiquer uniquement par un canal vérifié. Le membre devra personnaliser son mot de passe à la connexion."),

                Forms\Components\Select::make('role')
                    ->label('Rôle')
                    ->options(GroupMemberRole::options())
                    ->required()
                    ->default(GroupMemberRole::Fondateur->value),

                Forms\Components\TextInput::make('phone')
                    ->label('Téléphone')
                    ->tel()
                    ->maxLength(255),

                Forms\Components\Toggle::make('is_active')
                    ->label('Actif')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn ($record) => $record->username ? '@' . $record->username : null),

                Tables\Columns\TextColumn::make('role')
                    ->label('Rôle')
                    ->badge()
                    ->color(fn (string $state): string => GroupMemberRole::tryFrom($state)?->badgeColor() ?? 'gray')
                    ->formatStateUsing(fn (string $state): string => GroupMemberRole::tryFrom($state)?->label() ?? $state),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Dernière connexion')
                    ->since()
                    ->placeholder('Jamais'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Ajouter un membre')
                    ->visible(fn (): bool => Gate::allows(SaasCapabilities::MANAGE_GROUP_MEMBERS))
                    ->mutateFormDataUsing(function (array $data): array {
                        // NOT NULL en base. L'observer remplace ensuite le
                        // secret provisoire par une invitation si activée.
                        if (blank($data['password'] ?? null)) {
                            $data['password'] = app(TemporaryPasswordGenerator::class)->generate();
                        }

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('resetAccess')
                    ->label('Réinitialiser l’accès')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Réinitialiser l’accès du membre ?')
                    ->modalDescription(
                        "Un lien sécurisé à usage unique sera envoyé à l’adresse e-mail du membre. Son mot de passe actuel reste utilisable jusqu’à sa confirmation."
                    )
                    ->modalSubmitActionLabel('Confirmer la réinitialisation')
                    ->visible(fn (GroupMember $record): bool =>
                        Gate::allows(SaasCapabilities::MANAGE_GROUP_MEMBERS)
                        && config('group_portal.invite_flow_enabled', false)
                        && $record->is_active
                        && filled($record->email))
                    ->action(function (GroupMember $record): void {
                        Gate::authorize(SaasCapabilities::MANAGE_GROUP_MEMBERS);

                        app(GroupMemberAccessReset::class)->reset(
                            auth()->user(),
                            (int) $this->getOwnerRecord()->getKey(),
                            (int) $record->getKey(),
                        );

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Réinitialisation prise en compte')
                            ->body("Le lien sécurisé a été mis en file d’envoi. Sa livraison n’est pas encore confirmée.")
                            ->send();
                    }),
                Tables\Actions\EditAction::make()
                    ->visible(fn (): bool => Gate::allows(SaasCapabilities::MANAGE_GROUP_MEMBERS)),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (): bool => Gate::allows(SaasCapabilities::MANAGE_GROUP_MEMBERS))
                    ->requiresConfirmation(),
            ]);
    }
}
