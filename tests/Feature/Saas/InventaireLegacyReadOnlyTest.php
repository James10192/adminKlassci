<?php

use App\Models\Group;
use App\Models\SaasEstablishment;
use App\Models\SaasOrganization;
use App\Models\SaasProduct;
use App\Models\SaasProductInstance;
use App\Models\Tenant;
use App\Services\Saas\LegacyInventoryReport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function saasLegacyTenant(string $code, ?Group $group = null): Tenant
{
    return Tenant::create([
        'code' => $code,
        'name' => strtoupper($code),
        'subdomain' => $code,
        'database_name' => 'test_'.$code,
        'database_credentials' => [
            'host' => 'localhost',
            'username' => 'sensitive-user',
            'password' => 'NE_JAMAIS_AFFICHER_MDP',
        ],
        'git_branch' => 'presentation',
        'group_id' => $group?->id,
        'api_token' => 'NE_JAMAIS_AFFICHER_TOKEN',
    ]);
}

function saasInventoryProduct(string $code, string $type, string $adapter): SaasProduct
{
    return SaasProduct::create([
        'code' => $code,
        'name' => $type,
        'establishment_type' => $type,
        'adapter_key' => $adapter,
    ]);
}

it('inventorie le groupe Rostan et les trois etablissements sans deviner LMD et BTS', function () {
    $group = Group::create(['name' => 'Groupe Rostan', 'code' => 'rostan']);
    $org = SaasOrganization::create(['name' => 'Groupe Rostan', 'legacy_group_id' => $group->id]);

    $lmd = saasInventoryProduct('klassci-v2-lmd', 'universite_lmd', 'laravel-v2');
    $bts = saasInventoryProduct('klassci-v2-bts', 'grande_ecole_bts', 'laravel-v2');
    $college = saasInventoryProduct('klassci-college', 'college', 'fastapi-college');

    $usat = saasLegacyTenant('usat', $group);
    $islg = saasLegacyTenant('islg', $group);

    foreach ([
        ['school' => 'USAT', 'slug' => 'usat', 'product' => $lmd, 'legacy' => $usat],
        ['school' => 'ISLG', 'slug' => 'islg', 'product' => $bts, 'legacy' => $islg],
        ['school' => 'Rostan College', 'slug' => 'rostan-college', 'product' => $college, 'legacy' => null],
    ] as $entry) {
        $school = SaasEstablishment::create([
            'organization_id' => $org->id,
            'product_id' => $entry['product']->id,
            'name' => $entry['school'],
            'slug' => $entry['slug'],
        ]);
        SaasProductInstance::create([
            'establishment_id' => $school->id,
            'product_id' => $entry['product']->id,
            'external_tenant_code' => $entry['slug'],
            'legacy_tenant_id' => $entry['legacy']?->id,
        ]);
    }

    $report = app(LegacyInventoryReport::class)->generate();
    $rows = collect($report['legacy_tenants'])->keyBy('legacy_code');
    $schools = collect($report['saas_establishments'])->keyBy('slug');

    expect($report['mode'])->toBe('read_only')
        ->and($report['summary']['legacy_groups'])->toBe(1)
        ->and($report['summary']['legacy_tenants'])->toBe(2)
        ->and($report['summary']['tenants_explicitly_mapped'])->toBe(2)
        ->and($report['summary']['saas_establishments'])->toBe(3)
        ->and($rows->get('usat')['establishment_type'])->toBe('universite_lmd')
        ->and($rows->get('islg')['establishment_type'])->toBe('grande_ecole_bts')
        ->and($rows->get('usat')['review_reasons'])->toBe([])
        ->and($schools->get('rostan-college')['legacy_tenant_id'])->toBeNull()
        ->and($schools->get('rostan-college')['establishment_type'])->toBe('college');
});

it('refuse de deduire la categorie scolaire du code ou du groupe', function () {
    $group = Group::create(['name' => 'Groupe Rostan', 'code' => 'rostan']);
    saasLegacyTenant('usat', $group);
    saasLegacyTenant('islg', $group);

    $report = app(LegacyInventoryReport::class)->generate();
    foreach ($report['legacy_tenants'] as $row) {
        expect($row['establishment_type'])->toBeNull()
            ->and($row['review_reasons'])->toContain('categorie_a_confirmer')
            ->and($row['review_reasons'])->toContain('instance_saas_non_rattachee');
    }

    expect($report['groups'][0]['link_status'])->toBe('a_valider');
});

it('signale un rattachement entre groupes contradictoires', function () {
    $legacyA = Group::create(['name' => 'Groupe A', 'code' => 'group-a']);
    $legacyB = Group::create(['name' => 'Groupe B', 'code' => 'group-b']);
    SaasOrganization::create(['name' => 'Org A', 'legacy_group_id' => $legacyA->id]);
    $orgB = SaasOrganization::create(['name' => 'Org B', 'legacy_group_id' => $legacyB->id]);
    $tenant = saasLegacyTenant('tenant-a', $legacyA);
    $product = saasInventoryProduct('klassci-v2-lmd', 'universite_lmd', 'laravel-v2');
    $school = SaasEstablishment::create([
        'organization_id' => $orgB->id,
        'product_id' => $product->id,
        'name' => 'School B',
        'slug' => 'school-b',
    ]);
    SaasProductInstance::create([
        'establishment_id' => $school->id,
        'product_id' => $product->id,
        'external_tenant_code' => 'tenant-a',
        'legacy_tenant_id' => $tenant->id,
    ]);

    $row = app(LegacyInventoryReport::class)->generate()['legacy_tenants'][0];
    expect($row['review_reasons'])->toContain('conflit_appartenance_groupe');
});

it('rend un JSON sans fuite de credentials, sans ecriture SQL et sans fichier', function () {
    $group = Group::create(['name' => 'Groupe Test', 'code' => 'groupe-test']);
    saasLegacyTenant('ecole-test', $group);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $exit = Artisan::call('saas:inventory', ['--format' => 'json']);
    $output = Artisan::output();
    $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($decoded['schema_version'])->toBe(1)
        ->and($decoded['source'])->toBe('master_database_only')
        ->and($decoded['summary']['legacy_tenants'])->toBe(1)
        ->and($output)->not->toContain('NE_JAMAIS_AFFICHER_MDP')
        ->and($output)->not->toContain('NE_JAMAIS_AFFICHER_TOKEN')
        ->and($output)->not->toContain('sensitive-user')
        ->and($queries)->not->toBe([]);

    foreach ($queries as $query) {
        expect($query)->toMatch('/^\s*(select|pragma)\b/i');
    }

    expect(Tenant::count())->toBe(1)
        ->and(SaasOrganization::count())->toBe(0);
});

it('rejette un format non reconnu sans faire de requete a la base', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    expect(Artisan::call('saas:inventory', ['--format' => 'csv']))->toBe(1)
        ->and($queries)->toBe([]);
});

it('signale une ecole historique archivee sans la restaurer', function () {
    $tenant = saasLegacyTenant('archivee');
    $tenant->delete();

    $report = app(LegacyInventoryReport::class)->generate();
    expect($report['legacy_tenants'][0]['archived'])->toBeTrue()
        ->and($report['legacy_tenants'][0]['review_reasons'])->toContain('tenant_historique_archive')
        ->and(Tenant::count())->toBe(0);
});
