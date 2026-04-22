<?php

namespace App\Enums;

/**
 * Tenant staleness tier — typed alternative to
 * `HealthCheckAlertResolver::STALE_TIER_*` string constants. Values preserved
 * so string-based callers keep working unchanged.
 *
 * `Unhealthy` (latest health check failed) always supersedes `Stale` (deploy
 * age) so the UI surfaces a single alert per tenant — precedence is enforced
 * in `HealthCheckAlertResolver::resolveStaleTier()`, not at the enum level.
 */
enum StaleTier: string
{
    case Unhealthy = 'unhealthy';
    case Stale = 'stale';

    public function severity(): AlertSeverity
    {
        return match ($this) {
            self::Unhealthy => AlertSeverity::Critical,
            self::Stale => AlertSeverity::Warning,
        };
    }
}
