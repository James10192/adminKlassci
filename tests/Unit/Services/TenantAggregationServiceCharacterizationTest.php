<?php

use App\Services\TenantAggregationService;

it('class exists and is instantiable via container', function () {
    expect(class_exists(TenantAggregationService::class))->toBeTrue();

    $service = app(TenantAggregationService::class);
    expect($service)->toBeInstanceOf(TenantAggregationService::class);
});

it('exposes the 4 public aggregation methods that PR4a split will preserve', function () {
    $reflection = new ReflectionClass(TenantAggregationService::class);

    $expectedMethods = [
        'getGroupKpis',
        'getGroupFinancials',
        'getGroupOutstandingAging',
        'getGroupTrends',
    ];

    foreach ($expectedMethods as $methodName) {
        expect($reflection->hasMethod($methodName))
            ->toBeTrue("TenantAggregationService must expose public method {$methodName}()");

        $method = $reflection->getMethod($methodName);
        expect($method->isPublic())->toBeTrue("{$methodName}() must be public");
        expect($method->isStatic())->toBeFalse("{$methodName}() must be instance method");

        $params = $method->getParameters();
        expect(count($params))->toBeGreaterThanOrEqual(1, "{$methodName}() must accept Group as first param");
        expect(count($params))->toBeLessThanOrEqual(2, "{$methodName}() public surface: Group + optional ?PeriodInterface");
        expect($params[0]->getType()?->getName())
            ->toBe('App\\Models\\Group', "{$methodName}() must accept Group as first param");

        // PR4d: if a second param is present, it must be optional (LSP-safe default null).
        if (count($params) === 2) {
            expect($params[1]->isOptional())
                ->toBeTrue("{$methodName}() second param must be optional to preserve LSP");
        }
    }
});

it('exposes the health metrics method consumed by GroupAlertsWidget', function () {
    $reflection = new ReflectionClass(TenantAggregationService::class);

    expect($reflection->hasMethod('getGroupHealthMetrics'))
        ->toBeTrue('Health metrics method must be preserved');

    $method = $reflection->getMethod('getGroupHealthMetrics');
    expect($method->isPublic())->toBeTrue();
});

it('exposes the tenant-level methods reused by widgets', function () {
    $reflection = new ReflectionClass(TenantAggregationService::class);

    expect($reflection->hasMethod('getTenantKpis'))
        ->toBeTrue('getTenantKpis() is called per-establishment by EstablishmentCardsWidget');

    $method = $reflection->getMethod('getTenantKpis');
    expect($method->isPublic())->toBeTrue();

    $params = $method->getParameters();
    expect(count($params))->toBeGreaterThanOrEqual(1);
    expect(count($params))->toBeLessThanOrEqual(2);
    expect($params[0]->getType()?->getName())->toBe('App\\Models\\Tenant');

    // PR4d: if a second param is present, it must be optional (LSP-safe).
    if (count($params) === 2) {
        expect($params[1]->isOptional())->toBeTrue();
    }
});

it('has a refreshGroupCache method to invalidate cached aggregations', function () {
    $reflection = new ReflectionClass(TenantAggregationService::class);

    expect($reflection->hasMethod('refreshGroupCache'))->toBeTrue();
    expect($reflection->getMethod('refreshGroupCache')->isPublic())->toBeTrue();
});

/**
 * Full JSON byte-equality snapshot — skipped by default (requires seeded multi-tenant DB).
 * Enable locally by setting env CHARACTERIZATION_RUN=1 with tenants seeded on the master connection.
 * Re-run before/after PR4a-2 refactor to prove LSP rétro-compat byte-à-byte.
 */
it('snapshot: KPI output structure is stable (integration, skip by default)', function () {
    if (env('CHARACTERIZATION_RUN') !== '1') {
        test()->markTestSkipped('Set CHARACTERIZATION_RUN=1 to run against a seeded master DB');
    }

    $group = \App\Models\Group::with('tenants')->first();
    expect($group)->not->toBeNull('Seed at least one Group with tenants before running snapshot');

    $kpis = app(TenantAggregationService::class)->getGroupKpis($group);

    expect($kpis)->toBeArray()
        ->toHaveKey('totals')
        ->toHaveKey('establishments');

    $snapshotPath = base_path('tests/Fixtures/group_kpis_snapshot.json');
    if (!file_exists($snapshotPath)) {
        @mkdir(dirname($snapshotPath), 0755, true);
        file_put_contents($snapshotPath, json_encode($kpis, JSON_PRETTY_PRINT));
        test()->markTestIncomplete('Baseline snapshot created — re-run to validate equality');
    }

    $expected = json_decode(file_get_contents($snapshotPath), true);
    expect($kpis)->toEqual($expected);
});
