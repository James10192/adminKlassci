<?php

use App\Models\Group;
use App\Models\SaasProduct;
use App\Models\Tenant;
use App\Services\Saas\LegacyInventoryReport;
use App\Services\Saas\SaasMappingReview;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function mappingTenant(string $code, ?Group $group = null): Tenant
{
    return Tenant::create([
        'code' => $code,
        'name' => strtoupper($code),
        'subdomain' => $code,
        'database_name' => 'test_'.$code,
        'database_credentials' => ['password' => 'NE_JAMAIS_AFFICHER_MDP'],
        'api_token' => 'NE_JAMAIS_AFFICHER_TOKEN_'.$code,
        'git_branch' => 'presentation',
        'group_id' => $group?->id,
    ]);
}

function mappingProposal(string $code, ?string $group = null, string $type = 'universite_lmd'): array
{
    return [
        'tenant_code' => $code,
        'group_code' => $group,
        'kind' => 'school',
        'establishment_type' => $type,
        'evidence_reference' => 'validation-direction-lot1',
    ];
}

function mappingReview(array $items): array
{
    return app(SaasMappingReview::class)->review(
        ['schema_version' => 1, 'proposals' => $items],
        app(LegacyInventoryReport::class)->generate()
    );
}

it('signale que le groupe commercial USAT ROSTAN differe du groupe historique en base', function () {
    Group::create(['name' => 'Groupe Rostan', 'code' => 'ROSTAN']);
    mappingTenant('usat');
    SaasProduct::create([
        'code' => 'klassci-v2-lmd',
        'name' => 'Université LMD',
        'establishment_type' => 'universite_lmd',
        'adapter_key' => 'laravel-v2',
    ]);

    $report = mappingReview([mappingProposal('usat', 'ROSTAN')]);
    expect($report['can_apply'])->toBeFalse()
        ->and($report['blocked'])->toBe(1)
        ->and($report['proposals'][0]['issues'])->toContain('historical_group_disagreement')
        ->and($report['proposals'][0]['status'])->toBe('blocked')
        ->and(Tenant::where('code', 'usat')->first()->group_id)->toBeNull();
});

it('accepte le projet coherents ISLG ROSTAN sans valider ni importer le tenant', function () {
    $group = Group::create(['name' => 'Groupe Rostan', 'code' => 'ROSTAN']);
    mappingTenant('islg', $group);
    SaasProduct::create([
        'code' => 'klassci-v2-bts',
        'name' => 'Grande école BTS',
        'establishment_type' => 'grande_ecole_bts',
        'adapter_key' => 'laravel-v2',
    ]);

    $result = mappingReview([mappingProposal('islg', 'ROSTAN', 'grande_ecole_bts')]);
    expect($result['ok'])->toBeTrue()
        ->and($result['blocked'])->toBe(0)
        ->and($result['can_apply'])->toBeFalse()
        ->and($result['proposals'][0]['status'])->toBe('pending_human_approval');
});

it('bloque tenant archive et propositions dupliquees', function () {
    $legacy = mappingTenant('ancien');
    $legacy->delete();

    $report = mappingReview([
        mappingProposal('ancien'),
        mappingProposal('ANCIEN'),
    ]);
    expect($report['blocked'])->toBe(2)
        ->and($report['proposals'][0]['issues'])->toContain('archived_legacy_tenant')
        ->and($report['proposals'][1]['issues'])->toContain('duplicate_proposal');
});

it('ne determine pas les produits par le code ou le moteur lorsque catalogue vide', function () {
    mappingTenant('usat');
    $report = mappingReview([mappingProposal('usat')]);

    expect($report['proposals'][0]['issues'])->toContain('product_catalogue_not_ready')
        ->and($report['can_apply'])->toBeFalse();
});

it('n exclut une instance de demonstration que sur declaration explicite documentee', function () {
    mappingTenant('presentation');
    $valid = mappingReview([[
        'tenant_code' => 'presentation',
        'kind' => 'demonstration',
        'evidence_reference' => 'validation-technique-interne',
    ]]);
    $invalid = mappingReview([[
        'tenant_code' => 'presentation',
        'kind' => 'technical',
    ]]);

    expect($valid['proposals'][0]['status'])->toBe('pending_human_approval')
        ->and($invalid['proposals'][0]['issues'])->toContain('evidence_reference_required');
});

it('commande CLI affiche seulement la revue et n execute aucune ecriture SQL', function () {
    $group = Group::create(['name' => 'Groupe Rostan', 'code' => 'ROSTAN']);
    mappingTenant('islg', $group);
    SaasProduct::create([
        'code' => 'klassci-v2-bts',
        'name' => 'Grande école BTS',
        'establishment_type' => 'grande_ecole_bts',
        'adapter_key' => 'laravel-v2',
    ]);
    $tmp = tempnam(sys_get_temp_dir(), 'mapping-test-');
    file_put_contents($tmp, json_encode([
        'schema_version' => 1,
        'proposals' => [mappingProposal('islg', 'ROSTAN', 'grande_ecole_bts')],
    ]));
    try {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $exit = Artisan::call('saas:mapping-review', ['manifest' => $tmp, '--format' => 'json']);
        $output = Artisan::output();
        $json = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($json['mode'])->toBe('read_only_review')
            ->and($json['can_apply'])->toBeFalse()
            ->and($output)->not->toContain('NE_JAMAIS_AFFICHER_TOKEN')
            ->and($output)->not->toContain('NE_JAMAIS_AFFICHER_MDP')
            ->and($queries)->not->toBe([]);

        foreach ($queries as $sql) {
            expect($sql)->toMatch('/^\s*(select|pragma)\b/i');
        }
    } finally {
        unlink($tmp);
    }
});

it('rejette un manifeste incorrect sans SQL ni fuite de chemin', function () {
    $exit = Artisan::call('saas:mapping-review', [
        'manifest' => '/chemin/inexistant/manuel.json',
        '--format' => 'json',
    ]);
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result['error_code'])->toBe('invalid_manifest_file');
});


it('bloque les champs JSON de type tableau sans warning ou conversion implicite', function () {
    $result = app(SaasMappingReview::class)->review(
        [
            'schema_version' => 1,
            'proposals' => [[
                'tenant_code' => ['islg'],
                'kind' => 'school',
                'group_code' => ['ROSTAN'],
                'establishment_type' => ['grande_ecole_bts'],
                'evidence_reference' => ['fichier-prive'],
            ]],
        ],
        app(LegacyInventoryReport::class)->generate()
    );

    expect($result['blocked'])->toBe(1)
        ->and($result['can_apply'])->toBeFalse()
        ->and($result['proposals'][0]['issues'])->toContain(
            'invalid_tenant_code',
            'invalid_group_code',
            'evidence_reference_required'
        );
});
