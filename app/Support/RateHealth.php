<?php

namespace App\Support;

/**
 * Maps a collection / attendance rate (0-100) to a semantic tone and a
 * French health label used across group portal hero KPIs.
 *
 * The thresholds are configuration, not constants. A group running an elite
 * private school and one running a community school do not call the same
 * recovery rate "healthy", and two schools should never be forced to share
 * one founder's opinion — so the values live in config/group_portal.php,
 * overridable per environment, with the constants below acting only as the
 * shipped defaults.
 *
 * Everything that colours a rate goes through here. A view that re-tests
 * `>= 70` inline drifts the day the threshold moves, and drifts silently.
 */
final class RateHealth
{
    /** Le barème par défaut : celui du recouvrement. */
    public const BAREME_RECOUVREMENT = 'rate_health';

    /**
     * Le barème de l'assiduité.
     *
     * Un taux de recouvrement de 72 % est confortable ; une assiduité de 72 %
     * ne l'est pas — c'est plus d'un quart des cours manqués. Faire lire à
     * l'assiduité les seuils du recouvrement (ce qu'un refactor de cette
     * session a fait par inadvertance) fait passer une tuile de « à
     * surveiller » à « sain » sans que personne ne l'ait décidé.
     */
    public const BAREME_ASSIDUITE = 'attendance_health';

    /** Shipped default — override with GROUP_PORTAL_RATE_HEALTHY. */
    public const HEALTHY = 70.0;

    /** Shipped default — override with GROUP_PORTAL_RATE_AT_RISK. */
    public const AT_RISK = 50.0;

    /** At or above this, a rate is considered healthy. */
    public static function healthyThreshold(string $bareme = self::BAREME_RECOUVREMENT): float
    {
        return (float) config("group_portal.{$bareme}.healthy", self::HEALTHY);
    }

    /** At or above this (but below healthy), a rate needs watching. */
    public static function atRiskThreshold(string $bareme = self::BAREME_RECOUVREMENT): float
    {
        return (float) config("group_portal.{$bareme}.at_risk", self::AT_RISK);
    }

    /** Returns one of: 'success' | 'warning' | 'danger'. Maps to `.gp-hero-kpi[data-tone]`. */
    public static function tone(float $rate, string $bareme = self::BAREME_RECOUVREMENT): string
    {
        return match (true) {
            $rate >= self::healthyThreshold($bareme) => 'success',
            $rate >= self::atRiskThreshold($bareme) => 'warning',
            default => 'danger',
        };
    }

    /** French health label displayed under a rate KPI. */
    public static function label(float $rate, string $bareme = self::BAREME_RECOUVREMENT): string
    {
        return match (true) {
            $rate >= self::healthyThreshold($bareme) => 'sain',
            $rate >= self::atRiskThreshold($bareme) => 'à surveiller',
            default => 'critique',
        };
    }
}
