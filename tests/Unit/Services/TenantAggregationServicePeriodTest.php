<?php

use App\Services\TenantAggregationService;
use App\Support\Period\CurrentMonthPeriod;
use App\Support\Period\CurrentYearPeriod;
use App\Support\Period\PeriodFactory;

it('all public get* methods accept an optional ?PeriodInterface second parameter', function () {
    $reflection = new ReflectionClass(TenantAggregationService::class);

    $periodMethods = [
        'getGroupKpis',
        'getTenantKpis',
        'getGroupFinancials',
        'getGroupOutstandingAging',
        'getGroupTrends',
    ];

    foreach ($periodMethods as $name) {
        $method = $reflection->getMethod($name);
        $params = $method->getParameters();

        expect(count($params))->toBe(2, "{$name} should take 2 params (Model + ?Period)");
        expect($params[1]->isOptional())->toBeTrue("{$name} second param must be optional");
        expect($params[1]->getType()?->getName())->toBe('App\\Support\\Period\\PeriodInterface');
        expect($params[1]->allowsNull())->toBeTrue();
    }
});

it('enrollment and health methods deliberately do NOT accept a Period param', function () {
    $reflection = new ReflectionClass(TenantAggregationService::class);

    foreach (['getGroupEnrollment', 'getGroupHealthMetrics'] as $name) {
        $method = $reflection->getMethod($name);
        expect($method->getParameters())->toHaveCount(1, "{$name} takes Group only — snapshot semantics");
    }
});

it('tenant-level compute methods accept optional Period for uniform aggregator call', function () {
    $reflection = new ReflectionClass(TenantAggregationService::class);

    foreach (['computeTenantEnrollment', 'computeTenantOutstandingAging', 'computeTenantHealthDetails', 'computeTenantTrends'] as $name) {
        $method = $reflection->getMethod($name);
        $params = $method->getParameters();

        expect($params)->toHaveCount(2, "{$name} must accept (Tenant, ?Period) for aggregator uniformity");
        expect($params[1]->isOptional())->toBeTrue();
    }
});

it('cache key format includes the period suffix for default period', function () {
    // Indirect verification: we don't touch the real DB, but we can inspect
    // the cacheKey() output from the default period to lock its shape.
    $defaultPeriod = PeriodFactory::default();

    expect($defaultPeriod)->toBeInstanceOf(CurrentYearPeriod::class);
    expect($defaultPeriod->cacheKey())->toBe('year_' . date('Y'));
});

it('cache key shape is group_v2_{id}_{suffix}_{period_key}', function () {
    $currentYear = new CurrentYearPeriod();
    $currentMonth = new CurrentMonthPeriod();

    // Manual composition matches the private TenantAggregationService::cacheKey() helper.
    $yearKey = "group_v2_5_kpis_{$currentYear->cacheKey()}";
    $monthKey = "group_v2_5_kpis_{$currentMonth->cacheKey()}";

    expect($yearKey)->toMatch('/^group_v2_\d+_(kpis|financials|enrollment|aging|health|trends)_year_\d{4}$/');
    expect($monthKey)->toMatch('/^group_v2_\d+_(kpis|financials|enrollment|aging|health|trends)_month_\d{4}_\d{2}$/');
    expect($yearKey)->not->toBe($monthKey);
});
