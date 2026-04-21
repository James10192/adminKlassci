<?php

use App\Services\Group\EnrollmentTrendAnalyzer;

beforeEach(function () {
    config()->set('group_portal.enrollment_decline_threshold_pct', 10);
});

it('flags a decline when both consecutive months drop above the threshold', function () {
    $analyzer = new EnrollmentTrendAnalyzer();

    // 100 → 80 (20% drop) → 60 (25% drop) — both exceed 10%
    $result = $analyzer->detectDecline(60, 80, 100);

    expect($result)->not->toBeNull();
    expect($result['declining'])->toBeTrue();
    expect($result['drop_pct_current'])->toBe(25.0);
    expect($result['drop_pct_previous'])->toBe(20.0);
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

    expect($result)->not->toBeNull();
    expect($result['declining'])->toBeTrue();
});

it('returns null for exactly the threshold value (strict comparison)', function () {
    config()->set('group_portal.enrollment_decline_threshold_pct', 10);

    $analyzer = new EnrollmentTrendAnalyzer();

    // Exactly 10% drop on both — strict < threshold means flagged
    $result = $analyzer->detectDecline(81, 90, 100);

    expect($result)->not->toBeNull();
});
