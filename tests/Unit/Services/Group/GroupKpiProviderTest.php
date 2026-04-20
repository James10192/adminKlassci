<?php

use App\Contracts\Group\GroupKpiProviderInterface;
use App\Models\Tenant;
use App\Services\Group\GroupKpiProvider;

it('binds the interface to the concrete provider via GroupServiceProvider', function () {
    $resolved = app(GroupKpiProviderInterface::class);

    expect($resolved)->toBeInstanceOf(GroupKpiProvider::class);
});

it('emptyKpis returns a stable structure with error flag set', function () {
    $tenant = new Tenant([
        'id' => 42,
        'code' => 'foo',
        'name' => 'Foo Academy',
        'status' => 'active',
        'plan' => 'essentiel',
    ]);
    $tenant->id = 42;

    $provider = app(GroupKpiProvider::class);
    $empty = $provider->emptyKpis($tenant);

    expect($empty)
        ->toHaveKey('tenant_id', 42)
        ->toHaveKey('tenant_code', 'foo')
        ->toHaveKey('tenant_name', 'Foo Academy')
        ->toHaveKey('students', 0)
        ->toHaveKey('inscriptions', 0)
        ->toHaveKey('revenue_expected', 0)
        ->toHaveKey('revenue_collected', 0)
        ->toHaveKey('collection_rate', 0)
        ->toHaveKey('staff', 0)
        ->toHaveKey('attendance_rate', 0)
        ->toHaveKey('academic_year', 'N/A')
        ->toHaveKey('status', 'active')
        ->toHaveKey('plan', 'essentiel')
        ->toHaveKey('error', true);
});

it('implements GroupKpiProviderInterface', function () {
    expect(app(GroupKpiProvider::class))
        ->toBeInstanceOf(GroupKpiProviderInterface::class);
});
