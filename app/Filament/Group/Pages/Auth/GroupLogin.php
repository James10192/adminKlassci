<?php

namespace App\Filament\Group\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login;
use Illuminate\Contracts\Support\Htmlable;

class GroupLogin extends Login
{
    public function getHeading(): string|Htmlable
    {
        return 'Portail Fondateurs';
    }

    public function getSubHeading(): string|Htmlable
    {
        return 'Connectez-vous à votre espace de direction';
    }

    /**
     * Relabel the email input — it accepts both an email and a username,
     * the canonical identifier a DGA hired from a subsidiary may be issued
     * without company email. Validation tightened to a loose string so the
     * built-in `email` rule doesn't reject a username like `jean.diomande`.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email ou nom d\'utilisateur')
            ->required()
            ->autocomplete()
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * Map the single form input to the right auth column — email when it
     * looks like an email, username otherwise. Keeps Filament's default
     * password matching intact.
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $identifier = trim((string) ($data['email'] ?? ''));
        $column = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return [
            $column => $identifier,
            'password' => $data['password'],
        ];
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response) {
            $user = Filament::auth()->user();
            if ($user) {
                $user->update(['last_login_at' => now()]);
            }
        }

        return $response;
    }
}
