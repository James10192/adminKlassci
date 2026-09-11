<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Enums\GroupMemberRole;
use App\Models\GroupMember;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Membres du groupe';

    protected static ?string $modelLabel = 'membre';

    protected static ?string $pluralModelLabel = 'membres';

    protected static ?string $recordTitleAttribute = 'name';

    public function isReadOnly(): bool
    {
        return false;
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
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->helperText('Laissez vide si le membre n\'a pas d\'email — un nom d\'utilisateur sera généré automatiquement.'),

                Forms\Components\TextInput::make('username')
                    ->label('Nom d\'utilisateur')
                    ->unique(ignoreRecord: true)
                    ->maxLength(80)
                    ->alphaDash()
                    ->helperText('Généré automatiquement si vide et pas d\'email. Utilisé pour la connexion lorsqu\'il n\'y a pas d\'email.'),

                Forms\Components\TextInput::make('password')
                    ->label('Mot de passe')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(function (string $operation): string {
                        if ($operation === 'edit') {
                            return 'Laissez vide pour conserver le mot de passe actuel.';
                        }

                        return config('group_portal.invite_flow_enabled')
                            ? 'Laissez vide : un mot de passe temporaire sera généré et envoyé par email.'
                            : 'Laissez vide : un mot de passe temporaire sera généré et affiché une seule fois après la création.';
                    }),

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
                    // Sans envoi d'invitation, le mot de passe généré à
                    // l'insertion n'existe en clair que sur cette instance :
                    // c'est ici, et nulle part ailleurs, qu'on peut le montrer.
                    ->after(function (GroupMember $record): void {
                        if ($record->motDePasseTemporaire === null) {
                            return;
                        }

                        Notification::make()
                            ->warning()
                            ->persistent()
                            ->title('Mot de passe temporaire — affiché une seule fois')
                            ->body(new HtmlString(
                                '<code style="display:block;margin:.25rem 0 .5rem;padding:.35rem .5rem;'
                                . 'border-radius:.375rem;background:rgba(0,0,0,.06);font-size:.95em;'
                                . 'word-break:break-all;">' . e($record->motDePasseTemporaire) . '</code>'
                                . 'Transmettez-le à ' . e($record->name) . ' par un canal sûr. '
                                . 'Il devra le changer à sa première connexion.'
                            ))
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('resendInvitation')
                    ->label('Renvoyer l\'invitation')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Renvoyer l\'invitation')
                    ->modalDescription(fn ($record) => "Un nouveau mot de passe temporaire sera généré et envoyé à {$record->email}. L'ancien mot de passe sera invalidé.")
                    ->visible(fn ($record) => config('group_portal.invite_flow_enabled')
                        && ! empty($record->email))
                    ->action(function ($record) {
                        app(\App\Services\Group\GroupMemberInvitationService::class)->invite($record);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Invitation envoyée')
                            ->body("Nouveau lien d'activation envoyé à {$record->email}.")
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation(),
            ]);
    }
}
