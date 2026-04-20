<?php

use App\Contracts\Group\GroupFinancialsProviderInterface;
use App\Models\Tenant;
use App\Services\Group\GroupFinancialsProvider;

it('binds the interface to the concrete provider via GroupServiceProvider', function () {
    expect(app(GroupFinancialsProviderInterface::class))
        ->toBeInstanceOf(GroupFinancialsProvider::class);
});

it('emptyFinancials returns a stable structure with zeroed amounts', function () {
    $tenant = new Tenant();
    $tenant->name = 'Bar Institute';

    $empty = app(GroupFinancialsProvider::class)->emptyFinancials($tenant);

    expect($empty)
        ->toHaveKey('tenant_name', 'Bar Institute')
        ->toHaveKey('revenue_expected', 0)
        ->toHaveKey('revenue_collected', 0)
        ->toHaveKey('outstanding', 0)
        ->toHaveKey('surplus', 0)
        ->toHaveKey('collection_rate', 0)
        ->toHaveKey('monthly_revenue', [])
        ->toHaveKey('by_type', []);
});

it('implements GroupFinancialsProviderInterface', function () {
    expect(app(GroupFinancialsProvider::class))
        ->toBeInstanceOf(GroupFinancialsProviderInterface::class);
});
