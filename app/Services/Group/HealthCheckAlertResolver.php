<?php

namespace App\Services\Group;

use App\Enums\AlertSeverity;
use App\Enums\SslTier;
use App\Enums\StaleTier;
use App\Models\Tenant;
use App\Models\TenantHealthCheck;

/**
 * Classifies a tenant's health-check snapshot into group-portal alert tiers.
 * Pure: no database access, no side effects — the caller is responsible for
 * eager-loading `latestHealthCheck` / `latestSslHealthCheck` to avoid N+1.
 *
 * Two independent decisions per tenant:
 *   - SSL tier: critical / warning / null, based on metadata.days_remaining
 *   - Staleness tier: unhealthy / stale / null, based on last_deployed_at +
 *                     latest health check status
 *
 * Tiers are resolved separately so the same tenant can surface both alerts
 * (e.g. deployed 40 days ago AND SSL expiring in 5 days).
 *
 * String constants + backed enums coexist: `SSL_TIER_*` / `STALE_TIER_*`
 * strings remain for legacy callers and any future cache-serialized value;
 * new callers should prefer the `SslTier` / `StaleTier` enum variants.
 */
class HealthCheckAlertResolver
{
    public const SSL_TIER_CRITICAL = 'critical';
    public const SSL_TIER_WARNING = 'warning';

    public const STALE_TIER_UNHEALTHY = 'unhealthy';
    public const STALE_TIER_STALE = 'stale';

    /**
     * Returns null when: no ssl_certificate check found, metadata missing
     * `days_remaining`, or days_remaining > warning threshold. The last SSL
     * check is considered authoritative — the caller should scope to the
     * tenant's latest row (via Tenant::latestSslHealthCheck).
     */
    public function resolveSslTier(?TenantHealthCheck $latestSslCheck): ?string
    {
        if ($latestSslCheck === null) {
            return null;
        }

        $metadata = $latestSslCheck->metadata;
        if (! is_array($metadata) || ! array_key_exists('days_remaining', $metadata)) {
            return null;
        }

        // Guard against corrupted metadata where `days_remaining` is a string
        // like "expired" or null — silent `(int)` coercion would produce 0 and
        // fire a phantom Critical alert ("expire dans 0 jour").
        if (! is_numeric($metadata['days_remaining'])) {
            return null;
        }

        $days = (int) $metadata['days_remaining'];

        if ($days <= (int) config('group_portal.ssl_expiry_critical_days', 7)) {
            return self::SSL_TIER_CRITICAL;
        }

        if ($days <= (int) config('group_portal.ssl_expiry_warning_days', 15)) {
            return self::SSL_TIER_WARNING;
        }

        return null;
    }

    /**
     * `unhealthy` (latest overall check failed) takes precedence over
     * `stale` (deployment age) — the two conditions can co-occur, but the
     * UI only surfaces one alert per tenant to avoid noise. `null` when
     * neither applies AND when `last_deployed_at` is unset (newly created
     * tenants aren't stale).
     */
    public function resolveStaleTier(Tenant $tenant, ?TenantHealthCheck $latestCheck): ?string
    {
        if ($latestCheck !== null && $latestCheck->status === 'unhealthy') {
            return self::STALE_TIER_UNHEALTHY;
        }

        if ($tenant->last_deployed_at === null) {
            return null;
        }

        $threshold = (int) config('group_portal.stale_tenant_days', 30);
        if ($tenant->last_deployed_at->diffInDays(now()) > $threshold) {
            return self::STALE_TIER_STALE;
        }

        return null;
    }

    public function severityForSslTier(string $tier): AlertSeverity
    {
        return match ($tier) {
            self::SSL_TIER_CRITICAL => AlertSeverity::Critical,
            self::SSL_TIER_WARNING => AlertSeverity::Warning,
            default => AlertSeverity::Info,
        };
    }

    public function severityForStaleTier(string $tier): AlertSeverity
    {
        return match ($tier) {
            self::STALE_TIER_UNHEALTHY => AlertSeverity::Critical,
            self::STALE_TIER_STALE => AlertSeverity::Warning,
            default => AlertSeverity::Info,
        };
    }

    /**
     * Typed alternatives — wrap the existing string-based methods. Callers
     * that want exhaustive `match` / IDE safety use these; legacy callers
     * keep the string API without any forced migration.
     */
    public function resolveSslTierEnum(?TenantHealthCheck $latestSslCheck): ?SslTier
    {
        $tier = $this->resolveSslTier($latestSslCheck);

        return $tier === null ? null : SslTier::from($tier);
    }

    public function resolveStaleTierEnum(Tenant $tenant, ?TenantHealthCheck $latestCheck): ?StaleTier
    {
        $tier = $this->resolveStaleTier($tenant, $latestCheck);

        return $tier === null ? null : StaleTier::from($tier);
    }
}
