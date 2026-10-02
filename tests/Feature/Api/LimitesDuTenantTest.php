<?php

use App\Models\Tenant;

/**
 * GET /api/tenants/{code}/limits — ce que le paywall d'une instance affiche
 * a son service technique. Les trois champs ajoutes (plan_label, monthly_fee,
 * admin_url) remplacent les anciens reglages locaux de l'instance.
 */
function tenantLimites(array $champs = []): Tenant
{
    return Tenant::create(array_merge([
        'code' => 'ecole-test', 'name' => 'Ecole Test', 'subdomain' => 'ecole-test',
        'database_name' => 'klassci_ecole_test',
        'database_credentials' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'u', 'password' => 'p'],
        'git_branch' => 'presentation', 'status' => 'active', 'plan' => 'elite',
        'monthly_fee' => 400000,
        'api_token' => str_repeat('a', 64),
        'subscription_end_date' => now()->addDays(40),
        'max_users' => 30, 'current_users' => 12,
    ], $champs));
}

it('rend le libelle du plan, le tarif et la fiche du tenant', function () {
    $tenant = tenantLimites();

    $reponse = $this->withToken(str_repeat('a', 64))
        ->getJson('/api/tenants/ecole-test/limits')
        ->assertOk();

    expect($reponse->json('plan_label'))->toBe('Elite')
        ->and($reponse->json('monthly_fee'))->toBe(400000)
        ->and($reponse->json('admin_url'))->toEndWith('/admin/tenants/' . $tenant->getKey());
});

it('deduit le libelle du code de plan quand aucun plan n est rattache', function () {
    tenantLimites(['plan' => 'essentiel', 'monthly_fee' => 0]);

    $this->withToken(str_repeat('a', 64))
        ->getJson('/api/tenants/ecole-test/limits')
        ->assertOk()
        ->assertJsonPath('plan_label', 'Essentiel')
        ->assertJsonPath('monthly_fee', 0);
});
