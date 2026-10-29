<?php

use App\Models\Group;
use App\Models\SaasOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('rattache explicitement une seule organisation SaaS a un groupe existant', function () {
    $legacy = Group::create(['name' => 'Groupe Rostan', 'code' => 'rostan']);
    $org = SaasOrganization::create([
        'name' => 'Groupe Rostan',
        'country_code' => 'CI',
        'legacy_group_id' => $legacy->id,
    ]);

    expect($legacy->fresh()->saasOrganization->id)->toBe($org->id)
        ->and($org->legacyGroup->code)->toBe('rostan')
        ->and(Group::count())->toBe(1)
        ->and(SaasOrganization::count())->toBe(1);
});

it('ne rattache jamais deux organisations commerciales au meme groupe historique', function () {
    $legacy = Group::create(['name' => 'Groupe Rostan', 'code' => 'rostan']);
    SaasOrganization::create(['name' => 'Organisation A', 'legacy_group_id' => $legacy->id]);

    expect(fn () => SaasOrganization::create([
        'name' => 'Organisation B', 'legacy_group_id' => $legacy->id,
    ]))->toThrow(QueryException::class);
});

it('preserve les organismes independants sans groupe historique', function () {
    $org = SaasOrganization::create(['name' => 'College autonome']);

    expect($org->legacy_group_id)->toBeNull()
        ->and($org->legacyGroup)->toBeNull()
        ->and(Group::count())->toBe(0);
});

it('ne modifie ni la table groupes ni le rattachement historique des tenants', function () {
    expect(Schema::hasColumn('saas_organizations', 'legacy_group_id'))->toBeTrue()
        ->and(Schema::hasColumn('tenants', 'group_id'))->toBeTrue()
        ->and(Schema::hasColumn('groups', 'code'))->toBeTrue();
});
