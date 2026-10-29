<?php

namespace App\Filament\Forms\Group;

use App\Enums\GroupMemberRole;
use Filament\Forms;

/** Single source for member creation and edition (never hydrate password on edit). */
final class GroupMemberFields
{
    /** @return array<int, \Filament\Forms\Components\Component> */
    public static function make(bool $quickCreate = false): array
    {
        return [
                Forms\Components\TextInput::make('name')
                    ->label('Nom complet')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->live(onBlur: true)
                    ->unique(table: \App\Models\GroupMember::class, ignoreRecord: ! $quickCreate)
                    ->maxLength(255)
                    ->helperText('Laissez vide si le membre n\'a pas d\'email — un nom d\'utilisateur sera généré automatiquement.'),

                Forms\Components\TextInput::make('username')
                    ->label('Nom d\'utilisateur')
                    ->unique(table: \App\Models\GroupMember::class, ignoreRecord: ! $quickCreate)
                    ->maxLength(80)
                    ->regex(\App\Support\Group\MemberUsernameRules::PATTERN)
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
                        ($quickCreate || $operation === 'create')
                        && (! config('group_portal.invite_flow_enabled') || blank($get('email'))))
                    ->required(fn (string $operation, Forms\Get $get): bool =>
                        ($quickCreate || $operation === 'create')
                        && (! config('group_portal.invite_flow_enabled') || blank($get('email'))))
                    ->dehydrated(fn (string $operation, $state): bool =>
                        ($quickCreate || $operation === 'create') && filled($state))
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
        ];
    }
}
