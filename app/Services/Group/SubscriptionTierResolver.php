<?php

namespace App\Services\Group;

use App\Enums\AlertSeverity;
use App\Models\Tenant;

/**
 * Resolves the subscription expiry tier for a tenant using the thresholds
 * configured in `config/group_portal.php`. Kept separate from the Tenant
 * model so it stays pure data and the resolver can be unit-tested without
 * hitting the database.
 */
class SubscriptionTierResolver
{
    public const TIER_EXPIRED = 'expired';
    public const TIER_URGENT = 'urgent';
    public const TIER_WARNING = 'warning';
    public const TIER_INFO = 'info';

    /**
     * Severity ordering used to aggregate a worst-case tier across tenants.
     * Lower rank = more urgent.
     */
    private const TIER_RANK = [
        self::TIER_EXPIRED => 0,
        self::TIER_URGENT => 1,
        self::TIER_WARNING => 2,
        self::TIER_INFO => 3,
    ];

    public function resolveTier(Tenant $tenant): ?string
    {
        $days = $tenant->daysRemaining();

        if ($days === null) {
            return null;
        }

        if ($days < 0) {
            return self::TIER_EXPIRED;
        }

        if ($days <= (int) config('group_portal.subscription_urgent_days', 7)) {
            return self::TIER_URGENT;
        }

        if ($days <= (int) config('group_portal.subscription_warning_days', 14)) {
            return self::TIER_WARNING;
        }

        if ($days <= (int) config('group_portal.subscription_info_days', 30)) {
            return self::TIER_INFO;
        }

        return null;
    }

    /**
     * Returns the most urgent tier among the provided values.
     * Null tiers (free tier, healthy subscription) are ignored.
     */
    public function worstTier(array $tiers): ?string
    {
        $ranked = array_filter($tiers, fn ($tier) => $tier !== null && isset(self::TIER_RANK[$tier]));

        if (empty($ranked)) {
            return null;
        }

        usort($ranked, fn ($a, $b) => self::TIER_RANK[$a] <=> self::TIER_RANK[$b]);

        return $ranked[0];
    }

    /**
     * Urgent and expired both escalate to Critical — aligns with the way
     * PaywallMiddleware treats them on the tenant side.
     */
    public function severityForTier(string $tier): AlertSeverity
    {
        return match ($tier) {
            self::TIER_EXPIRED, self::TIER_URGENT => AlertSeverity::Critical,
            self::TIER_WARNING => AlertSeverity::Warning,
            self::TIER_INFO => AlertSeverity::Info,
            default => AlertSeverity::Info,
        };
    }
}
