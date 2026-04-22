<?php

use App\Enums\AlertSeverity;
use App\Models\Tenant;
use App\Models\TenantHealthCheck;
use App\Services\Group\HealthCheckAlertResolver;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-04-21 10:00:00');

    config()->set('group_portal.ssl_expiry_warning_days', 15);
    config()->set('group_portal.ssl_expiry_critical_days', 7);
    config()->set('group_portal.stale_tenant_days', 30);
});

afterEach(function () {
    Carbon::setTestNow();
});

function sslCheck(?int $daysRemaining): TenantHealthCheck
{
    $check = new TenantHealthCheck();
    $check->setRawAttributes([
        'check_type' => 'ssl_certificate',
        'status' => 'healthy',
        'metadata' => $daysRemaining !== null
            ? json_encode(['days_remaining' => $daysRemaining])
            : null,
        'checked_at' => '2026-04-21 09:00:00',
    ], true);

    return $check;
}

function tenantWithDeploy(?string $deployedAt, string $name = 'Fixture'): Tenant
{
    $tenant = new Tenant();
    $tenant->setRawAttributes([
        'name' => $name,
        'code' => 'fixture',
        'last_deployed_at' => $deployedAt,
    ], true);

    return $tenant;
}

it('SSL returns null when the latest ssl check is missing', function () {
    $resolver = new HealthCheckAlertResolver();

    expect($resolver->resolveSslTier(null))->toBeNull();
});

it('SSL returns null when metadata.days_remaining is missing', function () {
    $resolver = new HealthCheckAlertResolver();

    expect($resolver->resolveSslTier(sslCheck(null)))->toBeNull();
});

it('SSL returns null when metadata.days_remaining is non-numeric (corrupted payload)', function () {
    $resolver = new HealthCheckAlertResolver();

    // The health-check command should always write an integer, but defensive
    // guarding avoids a phantom Critical "expire dans 0 jour" if the payload
    // ever ships "expired" / null / "N/A" as the value.
    foreach (['expired', 'N/A', '', null] as $badValue) {
        $check = new TenantHealthCheck();
        $check->setRawAttributes([
            'check_type' => 'ssl_certificate',
            'status' => 'healthy',
            'metadata' => json_encode(['days_remaining' => $badValue]),
            'checked_at' => '2026-04-21 09:00:00',
        ], true);

        expect($resolver->resolveSslTier($check))->toBeNull();
    }
});

it('SSL returns null when days_remaining is beyond the warning threshold', function () {
    $resolver = new HealthCheckAlertResolver();

    expect($resolver->resolveSslTier(sslCheck(16)))->toBeNull();
    expect($resolver->resolveSslTier(sslCheck(45)))->toBeNull();
});

it('SSL returns warning between critical+1 and warning thresholds', function () {
    $resolver = new HealthCheckAlertResolver();

    expect($resolver->resolveSslTier(sslCheck(15)))
        ->toBe(HealthCheckAlertResolver::SSL_TIER_WARNING);
    expect($resolver->resolveSslTier(sslCheck(8)))
        ->toBe(HealthCheckAlertResolver::SSL_TIER_WARNING);
});

it('SSL returns critical at or below the critical threshold', function () {
    $resolver = new HealthCheckAlertResolver();

    expect($resolver->resolveSslTier(sslCheck(7)))
        ->toBe(HealthCheckAlertResolver::SSL_TIER_CRITICAL);
    expect($resolver->resolveSslTier(sslCheck(0)))
        ->toBe(HealthCheckAlertResolver::SSL_TIER_CRITICAL);
    expect($resolver->resolveSslTier(sslCheck(-3)))
        ->toBe(HealthCheckAlertResolver::SSL_TIER_CRITICAL);
});

it('SSL honours overridden thresholds from config', function () {
    config()->set('group_portal.ssl_expiry_warning_days', 45);
    config()->set('group_portal.ssl_expiry_critical_days', 20);

    $resolver = new HealthCheckAlertResolver();

    expect($resolver->resolveSslTier(sslCheck(30)))
        ->toBe(HealthCheckAlertResolver::SSL_TIER_WARNING);
    expect($resolver->resolveSslTier(sslCheck(20)))
        ->toBe(HealthCheckAlertResolver::SSL_TIER_CRITICAL);
});

it('stale returns null when last_deployed_at is unset (never deployed is not stale)', function () {
    $resolver = new HealthCheckAlertResolver();

    expect($resolver->resolveStaleTier(tenantWithDeploy(null), null))->toBeNull();
});

it('stale returns stale when last deployment is older than threshold', function () {
    $resolver = new HealthCheckAlertResolver();

    // 35 days ago
    expect($resolver->resolveStaleTier(tenantWithDeploy('2026-03-17 10:00:00'), null))
        ->toBe(HealthCheckAlertResolver::STALE_TIER_STALE);
});

it('stale returns null when last deployment is recent', function () {
    $resolver = new HealthCheckAlertResolver();

    // 5 days ago
    expect($resolver->resolveStaleTier(tenantWithDeploy('2026-04-16 10:00:00'), null))
        ->toBeNull();
});

it('unhealthy status takes precedence over stale deployment', function () {
    $resolver = new HealthCheckAlertResolver();

    $unhealthyCheck = new TenantHealthCheck();
    $unhealthyCheck->setRawAttributes([
        'check_type' => 'http_status',
        'status' => 'unhealthy',
        'checked_at' => '2026-04-21 09:00:00',
    ], true);

    // Deploy was 2 days ago (not stale) but latest check says unhealthy
    expect($resolver->resolveStaleTier(tenantWithDeploy('2026-04-19 10:00:00'), $unhealthyCheck))
        ->toBe(HealthCheckAlertResolver::STALE_TIER_UNHEALTHY);
});

it('maps SSL tiers to the expected severities', function () {
    $resolver = new HealthCheckAlertResolver();

    expect($resolver->severityForSslTier(HealthCheckAlertResolver::SSL_TIER_CRITICAL))
        ->toBe(AlertSeverity::Critical);
    expect($resolver->severityForSslTier(HealthCheckAlertResolver::SSL_TIER_WARNING))
        ->toBe(AlertSeverity::Warning);
});

it('maps stale tiers to the expected severities', function () {
    $resolver = new HealthCheckAlertResolver();

    expect($resolver->severityForStaleTier(HealthCheckAlertResolver::STALE_TIER_UNHEALTHY))
        ->toBe(AlertSeverity::Critical);
    expect($resolver->severityForStaleTier(HealthCheckAlertResolver::STALE_TIER_STALE))
        ->toBe(AlertSeverity::Warning);
});
