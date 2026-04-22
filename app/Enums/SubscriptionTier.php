<?php

namespace App\Enums;

/**
 * Subscription expiry tier — strictly-typed alternative to the string
 * constants on `SubscriptionTierResolver`. Values are identical to
 * `SubscriptionTierResolver::TIER_*` so cached `$health['subscription_worst_tier']`
 * strings still match when callers do `->value` comparison.
 *
 * The constants on the resolver class remain in place as string aliases —
 * Blade `match` expressions against cached tier strings would crash with
 * `UnhandledMatchError` if the constants were removed (see critic audit
 * on PR-D for the full rationale).
 */
enum SubscriptionTier: string
{
    case Expired = 'expired';
    case Urgent = 'urgent';
    case Warning = 'warning';
    case Info = 'info';

    /**
     * Lower rank = more urgent — drives `worstTier()` aggregation.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Expired => 0,
            self::Urgent => 1,
            self::Warning => 2,
            self::Info => 3,
        };
    }

    public function severity(): AlertSeverity
    {
        return match ($this) {
            self::Expired, self::Urgent => AlertSeverity::Critical,
            self::Warning => AlertSeverity::Warning,
            self::Info => AlertSeverity::Info,
        };
    }
}
