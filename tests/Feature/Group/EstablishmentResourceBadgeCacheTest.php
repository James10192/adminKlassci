<?php

use App\Filament\Group\Pages\Benchmarking;
use App\Filament\Group\Pages\FinancialOverview;
use App\Filament\Group\Resources\EstablishmentResource;
use Illuminate\Support\Facades\Cache;

/**
 * PR5 — Verify the badge N+1 fix (Cache::remember wrapping) and sidebar
 * navigation group placement. No DB fixtures needed: the badge method is
 * guarded by `auth('group')->user()?->group` which short-circuits to `[]`
 * when nobody is authenticated; we exercise the cache-forget paths in
 * isolation instead.
 */

it('alerts cache key includes the group id', function () {
    $groupId = 42;

    expect(function () use ($groupId) {
        EstablishmentResource::forgetAlertsCache($groupId);
    })->not->toThrow(Exception::class);

    // Priming then forgetting leaves the key empty.
    Cache::put("group:alerts_v1:{$groupId}", [['severity' => 'critical']], 60);
    expect(Cache::has("group:alerts_v1:{$groupId}"))->toBeTrue();

    EstablishmentResource::forgetAlertsCache($groupId);
    expect(Cache::has("group:alerts_v1:{$groupId}"))->toBeFalse();
});

it('forgetAlertsCache of one group does not touch other groups', function () {
    Cache::put('group:alerts_v1:10', [['severity' => 'warning']], 60);
    Cache::put('group:alerts_v1:20', [['severity' => 'critical']], 60);

    EstablishmentResource::forgetAlertsCache(10);

    expect(Cache::has('group:alerts_v1:10'))->toBeFalse();
    expect(Cache::has('group:alerts_v1:20'))->toBeTrue();
});

it('FinancialOverview lives under the Analytiques navigation group', function () {
    expect(FinancialOverview::getNavigationGroup())->toBe('Analytiques');
});

it('Benchmarking lives under the Analytiques navigation group', function () {
    expect(Benchmarking::getNavigationGroup())->toBe('Analytiques');
});

it('EstablishmentResource stays top-level (no navigation group)', function () {
    // Scope guard: grouping the main resource would hide it behind a collapsible
    // section, hurting discoverability. Must stay top-level.
    expect(EstablishmentResource::getNavigationGroup())->toBeNull();
});
