<?php

use App\Contracts\Group\GroupKpiProviderInterface;
use App\Services\Group\GroupKpiProvider;

it('interface exposes computeGroupKpis and computeTenantKpis with optional Period param', function () {
    $reflection = new ReflectionClass(GroupKpiProviderInterface::class);

    foreach (['computeGroupKpis', 'computeTenantKpis'] as $name) {
        $method = $reflection->getMethod($name);
        $params = $method->getParameters();

        expect($params)->toHaveCount(2);
        expect($params[1]->isOptional())->toBeTrue("{$name} period param must be optional");
        expect($params[1]->getType()?->getName())->toBe('App\\Support\\Period\\PeriodInterface');
        expect($params[1]->allowsNull())->toBeTrue();
    }
});

it('concrete GroupKpiProvider matches the interface contract', function () {
    $reflection = new ReflectionClass(GroupKpiProvider::class);

    foreach (['computeGroupKpis', 'computeTenantKpis'] as $name) {
        $method = $reflection->getMethod($name);
        $params = $method->getParameters();

        expect(count($params))->toBeGreaterThanOrEqual(1);
        expect(count($params))->toBeLessThanOrEqual(2);

        if (count($params) === 2) {
            expect($params[1]->isOptional())->toBeTrue();
        }
    }
});

it('emptyKpis structure unchanged by PR4d (widgets read these keys)', function () {
    $tenant = new App\Models\Tenant();
    $tenant->id = 1;
    $tenant->code = 'test';
    $tenant->name = 'Test';
    $tenant->status = 'active';
    $tenant->plan = 'essentiel';

    $provider = app(GroupKpiProvider::class);
    $empty = $provider->emptyKpis($tenant);

    // Locked keys — breaking these is a widget-visible regression.
    foreach (['tenant_id', 'tenant_code', 'tenant_name', 'students', 'inscriptions',
              'revenue_expected', 'revenue_collected', 'collection_rate', 'staff',
              'attendance_rate', 'academic_year', 'status', 'plan', 'error'] as $key) {
        expect($empty)->toHaveKey($key);
    }
});
