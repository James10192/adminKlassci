<?php

namespace App\Filament\Group\Pages\Auth;

use Filament\Facades\Filament;
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
