<?php

use App\Contracts\Group\GroupFinancialsProviderInterface;
use App\Services\Group\GroupFinancialsProvider;

it('interface exposes computeGroupFinancials and computeTenantFinancials with optional Period', function () {
    $reflection = new ReflectionClass(GroupFinancialsProviderInterface::class);

    foreach (['computeGroupFinancials', 'computeTenantFinancials'] as $name) {
        $method = $reflection->getMethod($name);
        $params = $method->getParameters();

        expect($params)->toHaveCount(2);
        expect($params[1]->isOptional())->toBeTrue();
        expect($params[1]->getType()?->getName())->toBe('App\\Support\\Period\\PeriodInterface');
        expect($params[1]->allowsNull())->toBeTrue();
    }
});

it('concrete GroupFinancialsProvider matches the interface', function () {
    $reflection = new ReflectionClass(GroupFinancialsProvider::class);

    foreach (['computeGroupFinancials', 'computeTenantFinancials'] as $name) {
        $method = $reflection->getMethod($name);
        $params = $method->getParameters();

        expect(count($params))->toBeLessThanOrEqual(2);
        if (count($params) === 2) {
            expect($params[1]->isOptional())->toBeTrue();
        }
    }
});

it('emptyFinancials structure unchanged by PR4d', function () {
    $tenant = new App\Models\Tenant();
    $tenant->name = 'Test';

    $empty = app(GroupFinancialsProvider::class)->emptyFinancials($tenant);

    foreach (['tenant_name', 'revenue_expected', 'revenue_collected',
              'outstanding', 'surplus', 'collection_rate',
              'monthly_revenue', 'by_type'] as $key) {
        expect($empty)->toHaveKey($key);
    }
});
