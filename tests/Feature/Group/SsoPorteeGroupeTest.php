<?php

use App\Filament\Group\Widgets\EstablishmentCardsWidget;
use App\Services\Group\GroupSsoUrlBuilder;
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

it('refuse une barre oblique encodée, un saut de ligne, et un membre sans groupe', function () {
    // Le garde a quitte le widget pour le service : les deux surfaces qui
    // ouvrent un etablissement le partagent maintenant, au lieu que la seconde
    // l'oublie.
    $autorisee = fn (string $d): bool => GroupSsoUrlBuilder::destinationAutorisee($d);

    // Ce qui doit passer.
    foreach (['/', '/esbtp/etudiants', '/esbtp/paiements?statut=valide'] as $ok) {
        expect($autorisee($ok))->toBeTrue("destination refusée à tort : {$ok}");
    }

    // Ce qui ne doit pas passer. Les deux premières étaient déjà bloquées ; les
    // deux suivantes passaient : « % » laissait encoder une barre oblique, et
    // « $ » accepte un saut de ligne final.
    foreach ([
        '//evil.com',
        '/\\evil.com',
        '/%2f%2fevil.com',
        "/a\n",
        'https://evil.com',
        '',
    ] as $ko) {
        expect($autorisee($ko))->toBeFalse('destination acceptée à tort : ' . json_encode($ko));
    }
});

it('ne signe pas de jeton vers un établissement suspendu', function () {
    // Les cartes n'affichent que les écoles actives, mais la méthode est
    // publique : `$wire.getSsoUrl('ecole-suspendue')` rendait un jeton valide
    // vers une école que le groupe a suspendue — typiquement pour impayé.
    $suspendu = tenantDuGroupe($this->groupe, 'rostan-suspendu');
    $suspendu->update(['status' => 'suspended']);

    $this->actingAs($this->membre, 'group');

    expect((new EstablishmentCardsWidget())->getSsoUrl('rostan-suspendu'))->toBeNull();

    // Contre-épreuve : une école active du même groupe s'ouvre toujours.
    expect((new EstablishmentCardsWidget())->getSsoUrl($this->mien->code))->not->toBeNull();
});

it('la fiche d\'un établissement connecte, comme les cartes du tableau de bord', function () {
    // Les deux boutons portent le MEME libelle. Celui de la fiche n'etait qu'un
    // lien vers le site : le directeur arrivait sur un ecran de connexion depuis
    // un endroit, et connecte depuis l'autre.
    $html = view('filament.group.partials.establishment-view-hero', [
        'tenant' => $this->mien,
        'kpis' => [],
    ])->render();

    expect($html)->toContain("Ouvrir l'établissement");
    expect($html)->toContain('/auth/sso-from-group?token=');
    expect($html)->not->toContain('https://rostan-yopougon.klassci.com"');
});

it('n\'offre pas d\'ouvrir une école suspendue depuis sa fiche', function () {
    $this->mien->update(['status' => 'suspended']);

    $html = view('filament.group.partials.establishment-view-hero', [
        'tenant' => $this->mien->fresh(),
        'kpis' => [],
    ])->render();

    expect($html)->not->toContain("Ouvrir l'établissement");
});

it('lit le domaine des établissements dans la configuration', function () {
    // Il etait ecrit en dur. KLASSCI est multi-instance : une seule valeur
    // fausse servirait une adresse fausse a tout le monde, sans une erreur.
    config(['group_portal.tenant_domain' => 'exemple.ci']);

    expect(app(GroupSsoUrlBuilder::class)->urlDeBase($this->mien))
        ->toBe('https://rostan-yopougon.exemple.ci');
});
