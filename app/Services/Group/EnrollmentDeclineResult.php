<?php

namespace App\Services\Group;

/**
 * Immutable result of `EnrollmentTrendAnalyzer::detectDecline()`.
 *
 * Returned only when a decline is detected — callers get `null` for the
 * no-decline case, so the presence of an instance IS the "declining = true"
 * signal. Named properties replace the fragile array-key access pattern
 * (`$result['drop_pct_current']`) that would silently null-coalesce on typos.
 */
final readonly class EnrollmentDeclineResult
{
    public function __construct(
        public float $dropPctCurrent,
        public float $dropPctPrevious,
    ) {
    }
}
