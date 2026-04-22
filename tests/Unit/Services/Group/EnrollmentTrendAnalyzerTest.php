<?php

use App\Services\Group\EnrollmentTrendAnalyzer;

beforeEach(function () {
    config()->set('group_portal.enrollment_decline_threshold_pct', 10);
});

it('flags a decline when both consecutive months drop above the threshold', function () {
    $analyzer = new EnrollmentTrendAnalyzer();

    // 100 → 80 (20% drop) → 60 (25% drop) — both exceed 10%
    $result = $analyzer->detectDecline(60, 80, 100);

    expect($result)->toBeInstanceOf(\App\Services\Group\EnrollmentDeclineResult::class);
    expect($result->dropPctCurrent)->toBe(25.0);
    expect($result->dropPctPrevious)->toBe(20.0);
});

it('returns null when only the current month drops below the threshold', function () {
    $analyzer = new EnrollmentTrendAnalyzer();

    // 100 → 95 (5% drop) → 70 (26% drop in curr) — previous drop < 10%
    expect($analyzer->detectDecline(70, 95, 100))->toBeNull();
});

it('returns null when only the previous month drops below the threshold', function () {
    $analyzer = new EnrollmentTrendAnalyzer();

    // 100 → 80 (20% drop in prev) → 76 (5% drop in curr) — current drop < 10%
    expect($analyzer->detectDecline(76, 80, 100))->toBeNull();
});

it('returns null when the enrollment is growing', function () {
    $analyzer = new EnrollmentTrendAnalyzer();

    // 100 → 110 → 120 (growth, no decline)
    expect($analyzer->detectDecline(120, 110, 100))->toBeNull();
});

it('returns null when baseline months are zero (no data to compare)', function () {
    $analyzer = new EnrollmentTrendAnalyzer();

    expect($analyzer->detectDecline(0, 0, 0))->toBeNull();
    expect($analyzer->detectDecline(5, 0, 10))->toBeNull();
    expect($analyzer->detectDecline(5, 10, 0))->toBeNull();
});

it('honours overridden threshold from config', function () {
    config()->set('group_portal.enrollment_decline_threshold_pct', 5);

    $analyzer = new EnrollmentTrendAnalyzer();

    // 100 → 93 (7%) → 86 (7.5%) — above 5%, would not flag at default 10%
    $result = $analyzer->detectDecline(86, 93, 100);

    expect($result)->toBeInstanceOf(\App\Services\Group\EnrollmentDeclineResult::class);
});

it('flags exactly the threshold value (inclusive boundary, strict less-than filter)', function () {
    config()->set('group_portal.enrollment_decline_threshold_pct', 10);

    $analyzer = new EnrollmentTrendAnalyzer();

    // Exactly 10% drop on both months. Source filter is `$drop < $threshold`,
    // so a 10% drop is NOT filtered out — the boundary is inclusive on flag side.
    $result = $analyzer->detectDecline(81, 90, 100);

    expect($result)->toBeInstanceOf(\App\Services\Group\EnrollmentDeclineResult::class);
});

it('returns null just below the threshold (9.99%)', function () {
    config()->set('group_portal.enrollment_decline_threshold_pct', 10);

    $analyzer = new EnrollmentTrendAnalyzer();

    // 100 → 90.01 (9.99% drop) → 81.00 (10.00% drop) — first month just below threshold
    expect($analyzer->detectDecline(81, 9001, 10000))->toBeNull();
});
