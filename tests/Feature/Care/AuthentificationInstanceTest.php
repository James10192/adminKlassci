<?php

use App\Domain\Care\Acces\Models\TenantApiCredential;
use Tests\Feature\Care\Support;

/*
 * L'API KLASSCI Care n'accepte qu'un identifiant dedie, porte, transmis en
 * en-tete. Ces tests couvrent chaque maniere de se voir refuser, et verifient
 * que l'instance est bien celle de l'identifiant, jamais celle que la
 * requete pretend etre.
 */

beforeEach(function () {
    $this->ecole = Support::instance('presentation');
});

it('refuse une requete sans identifiant', function () {
    $this->getJson('/api/v1/support/bootstrap')->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
});

it('refuse un jeton passe dans l URL, meme valide', function () {
    $jeton = Support::jeton($this->ecole);

    $this->getJson('/api/v1/support/bootstrap?token='.$jeton)->assertStatus(400)->assertJsonPath('error', 'token_in_query');
});

it('refuse l ancien api_token des instances', function () {
    $this->ecole->update(['api_token' => str_repeat('z', 64)]);

    $this->withToken(str_repeat('z', 64))->getJson('/api/v1/support/bootstrap')->assertStatus(401);
});

it('refuse un secret faux sur un key_id existant', function () {
    [$credential] = TenantApiCredential::emettre($this->ecole, ['support:read']);

    $this->withToken('kc_'.$credential->key_id.'_'.str_repeat('A', 40))
        ->getJson('/api/v1/support/bootstrap')->assertStatus(401);
});

it('refuse un identifiant revoque ou expire', function () {
    [$revoque, $j1] = TenantApiCredential::emettre($this->ecole, ['support:read']);
    $revoque->revoquer();
    [, $j2] = TenantApiCredential::emettre($this->ecole, ['support:read'], expiresAt: now()->subMinute());

    $this->withToken($j1)->getJson('/api/v1/support/bootstrap')->assertStatus(401);
    $this->withToken($j2)->getJson('/api/v1/support/bootstrap')->assertStatus(401);
});

it('refuse une portee manquante', function () {
    $jeton = Support::jeton($this->ecole, ['support:read']);

    $this->withToken($jeton)->withHeader('Idempotency-Key', 'cle-12345678')
        ->postJson('/api/v1/support/tickets', Support::soumission())
        ->assertStatus(403)->assertJsonPath('error', 'insufficient_scope');
});

it('refuse une instance resiliee', function () {
    $jeton = Support::jeton($this->ecole);
    $this->ecole->update(['status' => 'cancelled']);

    $this->withToken($jeton)->getJson('/api/v1/support/bootstrap')->assertStatus(403);
});

it('ne stocke jamais le secret en clair', function () {
    [$credential, $jeton] = TenantApiCredential::emettre($this->ecole, ['support:read']);

    expect($credential->fresh()->secret_hash)->not->toContain(substr($jeton, -40))
        ->and($credential->toArray())->not->toHaveKey('secret_hash');
});

it('refuse d emettre une portee inconnue', function () {
    TenantApiCredential::emettre($this->ecole, ['support:*']);
})->throws(InvalidArgumentException::class);

it('sert les fonctionnalites de l instance, fermees par defaut', function () {
    $this->ecole->features()->create(['feature_key' => 'support_widget', 'is_enabled' => true]);

    $this->withToken(Support::jeton($this->ecole))->getJson('/api/v1/support/bootstrap')
        ->assertOk()
        ->assertJsonPath('instance', 'presentation')
        ->assertJsonPath('fonctionnalites.support_widget', true)
        ->assertJsonPath('fonctionnalites.support_customer_portal', false)
        ->assertHeader('X-Request-ID');
});

it('reprend un X-Request-ID bien forme et remplace un mal forme', function () {
    $jeton = Support::jeton($this->ecole);

    $this->withToken($jeton)->withHeader('X-Request-ID', '01J8ZQ4Y5K3M2N1P0QRSTVWXYZ')
        ->getJson('/api/v1/support/bootstrap')->assertHeader('X-Request-ID', '01J8ZQ4Y5K3M2N1P0QRSTVWXYZ');

    $id = $this->withToken($jeton)->withHeader('X-Request-ID', "x\nforged")
        ->getJson('/api/v1/support/bootstrap')->headers->get('X-Request-ID');
    expect($id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('limite le debit par instance, pas globalement', function () {
    config(['care.limites.lectures_par_minute' => 2]);
    $autre = Support::instance('hetec');
    $a = Support::jeton($this->ecole);
    $b = Support::jeton($autre);

    $this->withToken($a)->getJson('/api/v1/support/bootstrap')->assertOk();
    $this->withToken($a)->getJson('/api/v1/support/bootstrap')->assertOk();
    $this->withToken($a)->getJson('/api/v1/support/bootstrap')->assertStatus(429);
    $this->withToken($b)->getJson('/api/v1/support/bootstrap')->assertOk();
});

it('emet un identifiant par la commande, affiche une seule fois, et le revoque', function () {
    $this->artisan('care:identifiant presentation --portees=support:create,support:read')
        ->expectsOutputToContain('MASTER_SUPPORT_TOKEN=kc_')
        ->assertSuccessful();

    $credential = TenantApiCredential::firstOrFail();
    expect($credential->scopes)->toBe(['support:create', 'support:read']);

    $this->artisan("care:identifiant presentation --revoquer={$credential->key_id}")->assertSuccessful();
    expect($credential->fresh()->revoked_at)->not->toBeNull();
});

it('emet un identifiant a duree limitee depuis la commande', function () {
    $this->artisan('care:identifiant', ['tenant' => $this->ecole->code, '--expire' => 30])->assertSuccessful();
    $this->artisan('care:identifiant', ['tenant' => $this->ecole->code, '--expire' => 'zero'])->assertFailed();

    $emis = TenantApiCredential::where('tenant_id', $this->ecole->id)->latest('id')->first();
    expect($emis->expires_at->isBetween(now()->addDays(29), now()->addDays(31)))->toBeTrue();
});
