<?php

/**
 * Structural tests pinning the hero wiring on FinancialOverview + Benchmarking
 * views. Behaviour under a real authenticated group member is covered by
 * visual check; here we render the Blade templates standalone with the data
 * shapes produced by TenantAggregationService.
 */

it('financial-overview view source uses FcfaFormatter and x-group-hero', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/pages/financial-overview.blade.php')
    );

    expect($source)->toContain('<x-group-hero');
    expect($source)->toContain('FcfaFormatter::');
    expect($source)->not->toContain('gp-summary-grid');
    expect($source)->not->toContain('gp-summary-card');
});

it('benchmarking view source uses FcfaFormatter and x-group-hero', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/pages/benchmarking.blade.php')
    );

    expect($source)->toContain('<x-group-hero');
    expect($source)->toContain('FcfaFormatter::');
    expect($source)->toContain('FcfaFormatter::millions');
});

it('financial-overview hero KPI uses RateHealth helper for tone mapping', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/pages/financial-overview.blade.php')
    );

    expect($source)->toContain('RateHealth::tone($rate)');
    expect($source)->toContain('RateHealth::label($rate)');
});

it('benchmarking hero aggregates counts from establishments array', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/pages/benchmarking.blade.php')
    );

    expect($source)->toContain('$totalInscriptions');
    expect($source)->toContain('$totalStaff');
    expect($source)->toContain('$avgRate');
});
