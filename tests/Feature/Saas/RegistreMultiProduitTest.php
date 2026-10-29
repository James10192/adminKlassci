<?php

use App\Models\SaasEstablishment;
use App\Models\SaasOrganization;
use App\Models\SaasProduct;
use App\Models\SaasProductInstance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('permet a un etablissement de relier college et universite sans melanger les tenants', function () {
    $org = SaasOrganization::create(['name' => 'Groupe Demo', 'country_code' => 'CI']);
    $ecole = SaasEstablishment::create([
        'organization_id' => $org->id,
        'name' => 'Campus Demo',
        'slug' => 'campus-demo',
    ]);

    $universite = SaasProduct::create(['code' => 'klassci-v2', 'name' => 'Universite', 'adapter_key' => 'laravel-v2']);
    $college = SaasProduct::create(['code' => 'klassci-college', 'name' => 'College', 'adapter_key' => 'fastapi-college']);

    $uni = SaasProductInstance::create([
        'establishment_id' => $ecole->id, 'product_id' => $universite->id,
        'external_tenant_code' => 'campus-demo', 'status' => 'registered',
    ]);
    $coll = SaasProductInstance::create([
        'establishment_id' => $ecole->id, 'product_id' => $college->id,
        'external_tenant_code' => 'campus-demo', 'status' => 'registered',
    ]);

    expect($ecole->fresh()->productInstances)->toHaveCount(2)
        ->and($org->establishments()->count())->toBe(1)
        ->and($uni->legacy_tenant_id)->toBeNull()
        ->and($coll->product->code)->toBe('klassci-college')
        ->and($uni->product->code)->toBe('klassci-v2');
});

it('rejette une seconde instance du meme produit pour le meme etablissement', function () {
    $org = SaasOrganization::create(['name' => 'Groupe A']);
    $ecole = SaasEstablishment::create(['organization_id' => $org->id, 'name' => 'Ecole A', 'slug' => 'ecole-a']);
    $produit = SaasProduct::create(['code' => 'klassci-college', 'name' => 'College', 'adapter_key' => 'fastapi-college']);

    SaasProductInstance::create([
        'establishment_id' => $ecole->id, 'product_id' => $produit->id,
        'external_tenant_code' => 'ecole-a',
    ]);

    expect(fn () => SaasProductInstance::create([
        'establishment_id' => $ecole->id, 'product_id' => $produit->id,
        'external_tenant_code' => 'ecole-a-doublon',
    ]))->toThrow(QueryException::class);
});

it('ajoute uniquement des tables sans changer le schema historique des tenants', function () {
    expect(Schema::hasTable('tenants'))->toBeTrue()
        ->and(Schema::hasTable('saas_product_instances'))->toBeTrue()
        ->and(Schema::hasColumn('tenants', 'git_branch'))->toBeTrue()
        ->and(Schema::hasColumn('tenants', 'database_credentials'))->toBeTrue();
});

it('desactive les produits par defaut avant ouverture commerciale', function () {
    $product = SaasProduct::create([
        'code' => 'college-test', 'name' => 'College', 'adapter_key' => 'fastapi-college',
    ]);

    expect($product->fresh()->is_active)->toBeFalse();
});
