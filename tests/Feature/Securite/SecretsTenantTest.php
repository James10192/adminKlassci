<?php

use App\Filament\Resources\TenantResource\Pages\EditTenant;
use App\Filament\Resources\TenantResource\Pages\ViewTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantConnectionManager;
use Filament\Facades\Filament;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
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
    // On ecoute ce qui part reellement vers les canaux, quel que soit le niveau
    // configure : MessageLogged est emis pour chaque appel, meme filtre ensuite.
    $journalise = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$journalise) {
        $journalise[] = $e->message.' '.json_encode($e->context);
    });

    app(TenantConnectionManager::class)->createConnection(tenantAvecSecrets());

    $journalise = implode("\n", $journalise);

    // Le journal doit avoir ete ecrit : un test qui ne capte rien ne prouve rien.
    expect($journalise)->toContain('secret-test')->toContain('db.example.test');

    expect($journalise)
        ->not->toContain('mot-de-passe-sensible')
        ->not->toContain('utilisateur-sensible');
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
