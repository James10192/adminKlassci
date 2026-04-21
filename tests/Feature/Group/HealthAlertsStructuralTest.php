<?php

use App\Enums\AlertType;

/**
 * Structural tests for the PR7b health alerts integration. Behavior on real
 * tenants requires cross-database fixtures and is covered by the per-resolver
 * unit tests plus the visual check on `/groupe`.
 */

it('AlertType enum carries the 4 new PR7b cases', function () {
    expect(AlertType::PlanMismatch->value)->toBe('plan_mismatch');
    expect(AlertType::StaleTenant->value)->toBe('stale_tenant');
    expect(AlertType::SslExpiring->value)->toBe('ssl_expiring');
    expect(AlertType::EnrollmentDecline->value)->toBe('enrollment_decline');
});

it('health alerts config defaults match the PR7b contract', function () {
    expect(config('group_portal.health_alerts_enabled'))->toBe(true);
    expect((int) config('group_portal.plan_overage_warning_pct'))->toBe(100);
    expect((int) config('group_portal.plan_overage_critical_pct'))->toBe(110);
    expect((int) config('group_portal.stale_tenant_days'))->toBe(30);
    expect((int) config('group_portal.ssl_expiry_warning_days'))->toBe(15);
    expect((int) config('group_portal.ssl_expiry_critical_days'))->toBe(7);
    expect((int) config('group_portal.enrollment_decline_threshold_pct'))->toBe(10);
});

it('Tenant model exposes the two latestOfMany relations used by PR7b', function () {
    $source = file_get_contents(app_path('Models/Tenant.php'));

    expect($source)->toContain('public function latestHealthCheck()');
    expect($source)->toContain('latestOfMany(\'checked_at\')');
    expect($source)->toContain('public function latestSslHealthCheck()');
    expect($source)->toContain("'ssl_certificate'");
});

it('TenantAggregationService wires the 4 PR7b collectors behind the kill switch', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    expect($source)->toContain("config('group_portal.health_alerts_enabled'");
    expect($source)->toContain('collectPlanMismatchAlerts');
    expect($source)->toContain('collectStaleTenantAlerts');
    expect($source)->toContain('collectSslExpiryAlerts');
    expect($source)->toContain('collectEnrollmentDeclineAlerts');
    expect($source)->toContain('computeTenantMonthlyEnrollments');
});

it('PlanMismatch collector suppresses the duplicate QuotaExceeded for students', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    // collectQuotaAlerts accepts $planMismatchFired, forwarding to skipStudents
    $pattern = '/collectQuotaAlerts\(\s*Tenant\s+\$tenant,\s*array\s+&\$health,'
        . '\s*bool\s+\$planMismatchFired\s*=\s*false\)/';
    expect(preg_match($pattern, $source))->toBe(1);

    // computeQuotaPercentages honours the skipStudents flag
    expect($source)->toContain('skipStudents: $planMismatchFired');
    expect($source)->toContain('if (! $skipStudents && $tenant->max_students > 0)');
});

it('SSL collector reads metadata.days_remaining (not expires_at or response_metadata)', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    expect($source)->toContain("metadata['days_remaining']");
    expect($source)->not->toContain('response_metadata');
});

it('health alerts loop eager-loads the two relations to avoid N+1', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    expect($source)->toContain("load(['latestHealthCheck', 'latestSslHealthCheck'])");
});

it('HealthCheckAlertResolver exposes the expected tier constants', function () {
    expect(\App\Services\Group\HealthCheckAlertResolver::SSL_TIER_CRITICAL)->toBe('critical');
    expect(\App\Services\Group\HealthCheckAlertResolver::SSL_TIER_WARNING)->toBe('warning');
    expect(\App\Services\Group\HealthCheckAlertResolver::STALE_TIER_UNHEALTHY)->toBe('unhealthy');
    expect(\App\Services\Group\HealthCheckAlertResolver::STALE_TIER_STALE)->toBe('stale');
});

it('EnrollmentTrendAnalyzer is instantiable via the container', function () {
    $analyzer = app(\App\Services\Group\EnrollmentTrendAnalyzer::class);

    expect($analyzer)->toBeInstanceOf(\App\Services\Group\EnrollmentTrendAnalyzer::class);
});

it('TenantAggregationService auto-injects the two new resolvers via the constructor', function () {
    $service = app(\App\Services\TenantAggregationService::class);

    expect($service)->toBeInstanceOf(\App\Services\TenantAggregationService::class);
});
