<?php

use App\Models\SaasEstablishment;
use App\Models\SaasOrganization;
use App\Models\SaasProduct;
use App\Models\SaasProductInstance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

function produitsRostan(): array
{
    return [
        'college' => SaasProduct::create([
            'code' => 'klassci-college',
            'name' => 'KLASSCI College',
            'establishment_type' => 'college',
            'adapter_key' => 'fastapi-college',
        ]),
        'lmd' => SaasProduct::create([
            'code' => 'klassci-v2-lmd',
            'name' => 'KLASSCI Universite LMD',
            'establishment_type' => 'universite_lmd',
            'adapter_key' => 'laravel-v2',
        ]),
        'bts' => SaasProduct::create([
            'code' => 'klassci-v2-bts',
            'name' => 'KLASSCI Grande ecole BTS',
            'establishment_type' => 'grande_ecole_bts',
            'adapter_key' => 'laravel-v2',
        ]),
    ];
}

it('regroupe Rostan College USAT LMD et ISLG BTS sans melanger leurs etablissements', function () {
    $groupe = SaasOrganization::create(['name' => 'Groupe Rostan', 'country_code' => 'CI']);
    $produits = produitsRostan();
    $etablissements = [];

    foreach ([
        ['nom' => 'Rostan College', 'slug' => 'rostan-college', 'produit' => 'college'],
        ['nom' => 'USAT', 'slug' => 'usat', 'produit' => 'lmd'],
        ['nom' => 'ISLG', 'slug' => 'islg', 'produit' => 'bts'],
    ] as $ligne) {
        $school = SaasEstablishment::create([
            'organization_id' => $groupe->id,
            'product_id' => $produits[$ligne['produit']]->id,
            'name' => $ligne['nom'],
            'slug' => $ligne['slug'],
        ]);
        SaasProductInstance::create([
            'establishment_id' => $school->id,
            'product_id' => $school->product_id,
            'external_tenant_code' => $ligne['slug'],
        ]);
        $etablissements[$ligne['produit']] = $school;
    }

    expect($groupe->establishments()->count())->toBe(3)
        ->and($etablissements['college']->product->establishment_type)->toBe('college')
        ->and($etablissements['lmd']->product->establishment_type)->toBe('universite_lmd')
        ->and($etablissements['bts']->product->establishment_type)->toBe('grande_ecole_bts')
        ->and($etablissements['lmd']->product->adapter_key)->toBe('laravel-v2')
        ->and($etablissements['bts']->product->adapter_key)->toBe('laravel-v2')
        ->and($etablissements['college']->productInstance->external_tenant_code)->toBe('rostan-college')
        ->and($etablissements['lmd']->productInstance->external_tenant_code)->toBe('usat')
        ->and($etablissements['bts']->productInstance->external_tenant_code)->toBe('islg');
});

it('refuse deux produits dans le meme etablissement, meme si les codes tenant sont differents', function () {
    $groupe = SaasOrganization::create(['name' => 'Groupe Rostan']);
    $p = produitsRostan();
    $school = SaasEstablishment::create([
        'organization_id' => $groupe->id,
        'product_id' => $p['college']->id,
        'name' => 'Rostan College',
        'slug' => 'rostan-college',
    ]);

    SaasProductInstance::create([
        'establishment_id' => $school->id,
        'product_id' => $p['college']->id,
        'external_tenant_code' => 'rostan-college',
    ]);

    // La FK composite interdit egalement de rattacher un produit LMD
    // a un etablissement declare College, sans faire confiance a l'UI.
    expect(fn () => SaasProductInstance::create([
        'establishment_id' => $school->id,
        'product_id' => $p['lmd']->id,
        'external_tenant_code' => 'mauvaise-categorie',
    ]))->toThrow(QueryException::class);

    expect(fn () => SaasProductInstance::create([
        'establishment_id' => $school->id,
        'product_id' => $p['college']->id,
        'external_tenant_code' => 'doublon',
    ]))->toThrow(QueryException::class);
});

it('autorise un etablissement independant sans groupe multi-etablissements', function () {
    $independant = SaasOrganization::create(['name' => 'College Independant']);
    $produit = SaasProduct::create([
        'code' => 'klassci-college',
        'name' => 'KLASSCI College',
        'establishment_type' => 'college',
        'adapter_key' => 'fastapi-college',
    ]);
    $school = SaasEstablishment::create([
        'organization_id' => $independant->id,
        'product_id' => $produit->id,
        'name' => 'College Independant',
        'slug' => 'college-independant',
    ]);

    expect($independant->establishments()->count())->toBe(1)
        ->and($school->product->establishment_type)->toBe('college');
});

it('ne deduit jamais le produit de la seule plateforme technique', function () {
    $p = produitsRostan();
    expect($p['lmd']->adapter_key)->toBe('laravel-v2')
        ->and($p['bts']->adapter_key)->toBe('laravel-v2')
        ->and($p['lmd']->establishment_type)->not->toBe($p['bts']->establishment_type);
});

it('ajoute uniquement des tables sans changer les tenants et contrats historiques', function () {
    expect(Schema::hasTable('tenants'))->toBeTrue()
        ->and(Schema::hasTable('saas_product_instances'))->toBeTrue()
        ->and(Schema::hasColumn('tenants', 'git_branch'))->toBeTrue()
        ->and(Schema::hasColumn('tenants', 'database_credentials'))->toBeTrue();
});

it('desactive les produits par defaut avant ouverture commerciale', function () {
    $product = SaasProduct::create([
        'code' => 'klassci-college',
        'name' => 'KLASSCI College',
        'establishment_type' => 'college',
        'adapter_key' => 'fastapi-college',
    ]);

    expect($product->fresh()->is_active)->toBeFalse();
});
