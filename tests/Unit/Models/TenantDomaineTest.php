<?php

use App\Models\Tenant;

// Le domaine se lit dans la configuration : ce test a besoin de l'application
// bootee, comme QuotaHealthTest.
uses(Tests\TestCase::class);

/**
 * L'adresse d'un site d'établissement, écrite une seule fois.
 *
 * Le domaine `klassci.com` était en dur à onze endroits : le modèle, quatre
 * écrans Filament, la commande de provisionnement (jusque dans le `.env`
 * généré pour la nouvelle école), la commande de découverte et les trois sondes
 * de surveillance. KLASSCI est multi-instance : une seule de ces copies laissée
 * derrière sert une adresse fausse, sans lever la moindre erreur.
 */
function tenantNu(array $attributs = []): Tenant
{
    $tenant = new Tenant();
    $tenant->forceFill(array_merge(['subdomain' => 'rostan-bouake', 'code' => 'rostan-bouake'], $attributs));

    return $tenant;
}

it('compose l\'hôte à partir du domaine configuré', function () {
    config(['group_portal.tenant_domain' => 'exemple.ci']);

    expect(tenantNu()->hote)->toBe('rostan-bouake.exemple.ci');
    expect(tenantNu()->full_url)->toBe('https://rostan-bouake.exemple.ci');
});

it('retombe sur klassci.com quand rien n\'est configuré', function () {
    config(['group_portal.tenant_domain' => null]);

    expect(tenantNu()->hote)->toBe('rostan-bouake.klassci.com');
});

it('utilise le code quand le sous-domaine manque', function () {
    config(['group_portal.tenant_domain' => 'klassci.com']);

    expect(tenantNu(['subdomain' => null])->hote)->toBe('rostan-bouake.klassci.com');
});

it('tolère un domaine configuré avec une barre oblique finale', function () {
    config(['group_portal.tenant_domain' => 'exemple.ci/']);

    expect(tenantNu()->full_url)->toBe('https://rostan-bouake.exemple.ci');
});
