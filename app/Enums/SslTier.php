<?php

namespace App\Enums;

/**
 * SSL certificate expiry tier — typed alternative to
 * `HealthCheckAlertResolver::SSL_TIER_*` string constants. Values preserved
 * so string-based callers keep working unchanged.
 */
enum SslTier: string
{
    case Critical = 'critical';
    case Warning = 'warning';

    public function severity(): AlertSeverity
    {
        return match ($this) {
            self::Critical => AlertSeverity::Critical,
            self::Warning => AlertSeverity::Warning,
        };
    }
}
