<?php

use App\Filament\Group\Pages\Auth\GroupLogin;

it('maps an email-shaped identifier to the email column', function () {
    $login = new class extends GroupLogin {
        public function callCredentials(array $data): array
        {
            return $this->getCredentialsFromFormData($data);
        }
    };

    $creds = $login->callCredentials(['email' => 'marcel@klassci.com', 'password' => 'secret']);

    expect($creds)->toBe([
        'email' => 'marcel@klassci.com',
        'password' => 'secret',
    ]);
});

it('maps a username-shaped identifier to the username column', function () {
    $login = new class extends GroupLogin {
        public function callCredentials(array $data): array
        {
            return $this->getCredentialsFromFormData($data);
        }
    };

    $creds = $login->callCredentials(['email' => 'jean.diomande', 'password' => 'secret']);

    expect($creds)->toBe([
        'username' => 'jean.diomande',
        'password' => 'secret',
    ]);
});

it('trims surrounding whitespace before classifying', function () {
    $login = new class extends GroupLogin {
        public function callCredentials(array $data): array
        {
            return $this->getCredentialsFromFormData($data);
        }
    };

    $creds = $login->callCredentials(['email' => '  marcel@klassci.com  ', 'password' => 'x']);

    expect($creds['email'])->toBe('marcel@klassci.com')
        ->and(array_key_exists('username', $creds))->toBeFalse();
});
