<?php

namespace App\Support;

/**
 * Shared FCFA amount formatting for group portal UI. Kept as static helpers
 * to avoid container resolution in Blade views and keep call sites terse.
 */
final class FcfaFormatter
{
    /**
     * Compact thousands/millions notation — e.g. `1 234 567` → `1,2 M`,
     * `750_000` → `750 k`, `50` → `50`. Use in hero KPIs where space is tight.
     */
    public static function compact(float $amount): string
    {
        if ($amount >= 1_000_000) {
            return number_format($amount / 1_000_000, 1, ',', ' ') . ' M';
        }

        if ($amount >= 1_000) {
            return number_format($amount / 1_000, 0, ',', ' ') . ' k';
        }

        return number_format($amount, 0, ',', ' ');
    }

    /**
     * Millions-only, e.g. `1 234 567` → `1,2 M`. Use when the caller already
     * knows the amount is in the millions (KPI tables).
     */
    public static function millions(float $amount): string
    {
        return number_format($amount / 1_000_000, 1, ',', ' ');
    }

    /** Full formatted amount with thin-space thousand separator: `1 234 567`. */
    public static function full(float $amount): string
    {
        return number_format($amount, 0, ',', ' ');
    }
}
