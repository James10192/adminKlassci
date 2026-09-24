<?php

use App\Models\TenantActivityLog;
use Tests\Feature\Care\Support;

/*
| care:fonctionnalites : la seule facon d'activer KLASSCI Care sur une ecole
| sans ecrire en base a la main. Le bootstrap lu par l'instance doit refleter
| exactement ce que la commande a pose.
*/

beforeEach(function () {
    $this->ecole = Support::instance('presentation');
    $this->jeton = Support::jeton($this->ecole);
});

function fonctionnalitesVues(string $jeton): array
{
    return test()->withHeader('Authorization', "Bearer {$jeton}")
        ->getJson('/api/v1/support/bootstrap')
        ->assertOk()
        ->json('fonctionnalites');
}

it('laisse tout desactive tant que rien n est active', function () {
    $this->artisan('care:fonctionnalites presentation')->assertSuccessful();

    expect(fonctionnalitesVues($this->jeton))->toBe([
        'support_widget' => false,
        'support_customer_portal' => false,
        'support_screenshot' => false,
    ]);
});

it('active tout, et l instance le lit au bootstrap', function () {
    $this->artisan('care:fonctionnalites presentation --activer=tout')->assertSuccessful();

    expect(array_values(array_unique(fonctionnalitesVues($this->jeton))))->toBe([true]);
    expect(TenantActivityLog::where('tenant_id', $this->ecole->id)->where('action', 'care_features_changed')->exists())->toBeTrue();
});

it('desactive une seule fonctionnalite sans toucher aux autres, et se rejoue sans doublon', function () {
    $this->artisan('care:fonctionnalites presentation --activer=tout')->assertSuccessful();
    $this->artisan('care:fonctionnalites presentation --activer=tout')->assertSuccessful();
    $this->artisan('care:fonctionnalites presentation --desactiver=support_screenshot')->assertSuccessful();

    expect(fonctionnalitesVues($this->jeton))->toBe([
        'support_widget' => true,
        'support_customer_portal' => true,
        'support_screenshot' => false,
    ]);
});

it('previent quand la capture est active sans le portail', function () {
    $this->artisan('care:fonctionnalites presentation --activer=support_widget,support_screenshot')
        ->expectsOutputToContain('la capture ne s\'affichera pas')
        ->assertSuccessful();
});

it('refuse une fonctionnalite inconnue sans rien ecrire', function () {
    $this->artisan('care:fonctionnalites presentation --activer=support_widget,widgett')
        ->expectsOutputToContain('Fonctionnalite inconnue : widgett')
        ->assertFailed();

    expect(array_values(array_unique(fonctionnalitesVues($this->jeton))))->toBe([false]);
});

it('refuse une meme fonctionnalite activee et desactivee', function () {
    $this->artisan('care:fonctionnalites presentation --activer=support_widget --desactiver=support_widget')->assertFailed();
});

it('refuse une instance inconnue', function () {
    $this->artisan('care:fonctionnalites rostan')->expectsOutputToContain('Instance introuvable')->assertFailed();
});
