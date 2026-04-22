<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

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
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                Forms\Components\TextInput::make('password')
                    ->label('Mot de passe')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn () => config('group_portal.invite_flow_enabled')
                        ? 'Laissez vide — un mot de passe temporaire sera généré et envoyé par email.'
                        : 'Laisser vide pour ne pas modifier.'),

                Forms\Components\Select::make('role')
                    ->label('Rôle')
                    ->options([
                        'fondateur' => 'Fondateur',
                        'directeur_general' => 'Directeur Général',
                        'directeur_general_adjoint' => 'Directeur Général Adjoint',
                        'directeur_financier' => 'Directeur Financier',
                    ])
                    ->required()
                    ->default('fondateur'),

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
                    ->searchable(),

                Tables\Columns\TextColumn::make('role')
                    ->label('Rôle')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'fondateur' => 'success',
                        'directeur_general' => 'primary',
                        'directeur_general_adjoint' => 'info',
                        'directeur_financier' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'fondateur' => 'Fondateur',
                        'directeur_general' => 'Directeur Général',
                        'directeur_general_adjoint' => 'Directeur Général Adjoint',
                        'directeur_financier' => 'Directeur Financier',
                        default => $state,
                    }),

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
                    ->label('Ajouter un membre'),
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
