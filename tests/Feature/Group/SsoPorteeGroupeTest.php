<?php

use App\Filament\Group\Widgets\EstablishmentCardsWidget;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;

/**
 * Le jeton SSO ne doit jamais sortir du groupe de celui qui le demande.
 *
 * `getSsoUrl()` est PUBLIQUE sur un composant Livewire : elle est donc
 * appelable depuis la console du navigateur, et Livewire renvoie sa valeur de
 * retour au JS. Sans contrôle d'appartenance, un membre du groupe ROSTAN
 * pouvait exécuter `$wire.getSsoUrl('esbtp-abidjan')` et se connecter chez un
 * client qui n'est pas le sien.
 *
 * Le maître est la seule autorité sur l'appartenance à un groupe : le tenant
 * ne vérifie que la signature HMAC, l'expiration, le rate-limit et
 * l'open-redirect. Il n'a aucun moyen de rattraper ce contrôle.
 */
function tenantDuGroupe(?Group $group, string $code): Tenant
{
    return Tenant::create([
        'group_id' => $group?->id,
        'code' => $code,
        'name' => strtoupper($code),
        'subdomain' => $code,
        'database_name' => "klassci_{$code}",
        'database_credentials' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y'],
        'git_branch' => 'main',
        'status' => 'active',
        'plan' => 'elite',
    ]);
}

beforeEach(function () {
    config(['services.group_sso.secret' => str_repeat('a', 64)]);

    $this->groupe = Group::create(['name' => 'Groupe ROSTAN', 'code' => 'rostan', 'status' => 'active']);
    $this->autre = Group::create(['name' => 'Groupe ESBTP', 'code' => 'esbtp', 'status' => 'active']);

    $this->mien = tenantDuGroupe($this->groupe, 'rostan-yopougon');
    $this->sien = tenantDuGroupe($this->autre, 'esbtp-abidjan');
    $this->orphelin = tenantDuGroupe(null, 'hetec');

    $this->membre = GroupMember::create([
        'group_id' => $this->groupe->id,
        'email' => 'dg@rostan.test',
        'name' => 'Marcel',
        'role' => 'directeur_general',
        'password' => Hash::make('demo1234'),
        'is_active' => true,
    ]);

    $this->actingAs($this->membre, 'group');
});

it('délivre un jeton pour un établissement du groupe du membre', function () {
    $url = (new EstablishmentCardsWidget())->getSsoUrl('rostan-yopougon');

    expect($url)->not->toBeNull();
    expect($url)->toContain('/auth/sso-from-group?token=');
});

it('refuse un établissement appartenant à un AUTRE groupe', function () {
    // Le scénario de fuite : `$wire.getSsoUrl('esbtp-abidjan')` depuis la console.
    expect((new EstablishmentCardsWidget())->getSsoUrl('esbtp-abidjan'))->toBeNull();
});

it('refuse un établissement rattaché à aucun groupe', function () {
    expect((new EstablishmentCardsWidget())->getSsoUrl('hetec'))->toBeNull();
});

it('refuse une destination qui sort du site du tenant', function () {
    $widget = new EstablishmentCardsWidget();

    // Le maître SIGNE cette valeur : lui laisser passer n'importe quoi revient
    // à authentifier une destination qu'on n'a pas vérifiée.
    expect($widget->getSsoUrl('rostan-yopougon', 'https://evil.example/phish'))->toBeNull();
    expect($widget->getSsoUrl('rostan-yopougon', '//evil.example'))->toBeNull();
    expect($widget->getSsoUrl('rostan-yopougon', 'javascript:alert(1)'))->toBeNull();
    expect($widget->getSsoUrl('rostan-yopougon', ''))->toBeNull();

    // Un chemin interne reste accepté.
    expect($widget->getSsoUrl('rostan-yopougon', '/esbtp/etudiants'))->not->toBeNull();
});
