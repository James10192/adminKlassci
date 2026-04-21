<?php

namespace App\Support;

/**
 * Maps a collection / attendance rate (0-100) to a semantic tone and a
 * French health label used across group portal hero KPIs.
 *
 * Centralizing these thresholds means the directrice can tweak them in one
 * place — changing `HEALTHY` from 70 to 75 stays a one-line edit and can't
 * silently drift between the dashboard, financial overview, benchmarking,
 * and establishment pages.
 */
final class RateHealth
{
    public const HEALTHY = 70.0;
    public const AT_RISK = 50.0;

    /** Returns one of: 'success' | 'warning' | 'danger'. Maps to `.gp-hero-kpi[data-tone]`. */
    public static function tone(float $rate): string
    {
        return match (true) {
            $rate >= self::HEALTHY => 'success',
            $rate >= self::AT_RISK => 'warning',
            default => 'danger',
        };
    }

    /** French health label displayed under a rate KPI. */
    public static function label(float $rate): string
    {
        return match (true) {
            $rate >= self::HEALTHY => 'sain',
            $rate >= self::AT_RISK => 'à surveiller',
            default => 'critique',
        };
    }
}
