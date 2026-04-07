<?php

namespace App\Filament\Group\Pages;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

class EditProfile extends BaseEditProfile
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label('Nom complet')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Adresse e-mail')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                $this->getPasswordFormComponent()
                    ->label('Nouveau mot de passe'),
                $this->getPasswordConfirmationFormComponent()
                    ->label('Confirmer le mot de passe'),
            ]);
    }
}
