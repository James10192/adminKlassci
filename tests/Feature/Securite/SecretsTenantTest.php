<?php

use App\Filament\Resources\TenantResource\Pages\EditTenant;
use App\Filament\Resources\TenantResource\Pages\ViewTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantConnectionManager;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/**
 * Les secrets d'un tenant ne sortent ni dans le journal, ni dans le JSON.
 *
 * TenantConnectionManager journalisait le tableau database_credentials entier
 * au niveau debug — mot de passe compris — et .env.example livre LOG_LEVEL=debug.
 * Le modele Tenant, sans $hidden, exposait api_token et database_credentials a
 * toute serialisation.
 */
function tenantAvecSecrets(): Tenant
{
    return Tenant::create([
        'code' => 'secret-test',
        'name' => 'Secret Test',
        'subdomain' => 'secret-test',
        'database_name' => 'klassci_secret_test',
        'database_credentials' => [
            'host' => 'db.example.test',
            'port' => 3306,
            'username' => 'utilisateur-sensible',
            'password' => 'mot-de-passe-sensible',
        ],
        'git_branch' => 'main',
        'status' => 'active',
        'plan' => 'elite',
        'api_token' => 'jeton-api-sensible',
    ]);
}

it('n expose ni api_token ni database_credentials a la serialisation', function () {
    $tenant = tenantAvecSecrets()->fresh();

    expect($tenant->toArray())
        ->not->toHaveKey('api_token')
        ->not->toHaveKey('database_credentials');

    expect($tenant->toJson())
        ->not->toContain('mot-de-passe-sensible')
        ->not->toContain('jeton-api-sensible');

    // L'acces direct, lui, reste intact : la connexion en depend.
    expect($tenant->database_credentials['password'])->toBe('mot-de-passe-sensible');
    expect($tenant->api_token)->toBe('jeton-api-sensible');
});

it('ne journalise ni le mot de passe ni l utilisateur de la base', function () {
    // Log::spy() plutot qu'un ecouteur MessageLogged : Laravel n'emet pas
    // l'evenement pour un niveau que le canal ne traite pas. Avec LOG_LEVEL=info,
    // la ligne debug visee ne serait jamais captee et le test passerait sur
    // l'ancien code.
    Log::spy();

    app(TenantConnectionManager::class)->createConnection(tenantAvecSecrets());

    // On vise la ligne debug elle-meme, pas « un journal quelconque » : le
    // Log::info de connexion porte aussi le code et l'hote.
    Log::shouldHaveReceived('debug')->once()->withArgs(function ($message, $contexte = []) {
        $tout = $message.' '.json_encode($contexte);

        return str_contains($message, 'Checking credentials')
            && str_contains($tout, 'secret-test')
            && str_contains($tout, 'db.example.test')
            && ! str_contains($tout, 'mot-de-passe-sensible')
            && ! str_contains($tout, 'utilisateur-sensible');
    });
});

it('garde les secrets dans les formulaires Filament malgre $hidden', function (string $page) {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@example.test',
        'password' => bcrypt('x'),
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $tenant = tenantAvecSecrets();

    $etat = Livewire::actingAs($admin)
        ->test($page, ['record' => $tenant->getRouteKey()])
        ->get('data');

    expect($etat['api_token'])->toBe('jeton-api-sensible');
    expect($etat['database_credentials'])->toContain('mot-de-passe-sensible');
})->with([
    'edition' => [EditTenant::class],
    'detail' => [ViewTenant::class],
]);
