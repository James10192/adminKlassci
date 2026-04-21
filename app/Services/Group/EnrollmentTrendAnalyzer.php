<?php

namespace App\Services\Group;

/**
 * Detects a sustained enrollment decline over two consecutive months.
 *
 * The requirement "two consecutive months" is a noise filter — a single-month
 * drop is frequently seasonal (holidays, exam weeks, transfer cycles). Only
 * flags tenants where BOTH the current-vs-previous AND the previous-vs-before
 * comparisons exceed the configured percentage threshold.
 *
 * Pure: takes monthly counts, returns a verdict. Production code feeds it the
 * result of a `SELECT MONTH(created_at), COUNT(*) ... GROUP BY MONTH` query.
 */
class EnrollmentTrendAnalyzer
{
    /**
     * Analyses a three-month window of new-inscription counts.
     *
     * @param  int  $currentMonth     New inscriptions in the current calendar month.
     * @param  int  $previousMonth    New inscriptions in the previous calendar month.
     * @param  int  $twoMonthsAgo     New inscriptions two calendar months back.
     *
     * @return array{declining: bool, drop_pct_current: ?float, drop_pct_previous: ?float}|null
     *         Null when no decline is detected. When declining, returns both
     *         drop percentages so the caller can build a precise alert message.
     */
    public function detectDecline(int $currentMonth, int $previousMonth, int $twoMonthsAgo): ?array
    {
        if ($previousMonth === 0 || $twoMonthsAgo === 0) {
            return null;
        }

        $dropCurrent = $this->percentageDrop($currentMonth, $previousMonth);
        $dropPrevious = $this->percentageDrop($previousMonth, $twoMonthsAgo);

        $threshold = (float) config('group_portal.enrollment_decline_threshold_pct', 10);

        if ($dropCurrent < $threshold || $dropPrevious < $threshold) {
            return null;
        }

        return [
            'declining' => true,
            'drop_pct_current' => round($dropCurrent, 1),
            'drop_pct_previous' => round($dropPrevious, 1),
        ];
    }

    /**
     * Positive return value = actual decline (new < old). Negative values
     * mean growth — the caller compares against the threshold, not against
     * zero, so a 5% decline is correctly filtered out at threshold 10%.
     */
    private function percentageDrop(int $newCount, int $oldCount): float
    {
        if ($oldCount === 0) {
            return 0.0;
        }

        return (($oldCount - $newCount) / $oldCount) * 100.0;
    }
}
