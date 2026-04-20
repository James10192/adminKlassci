<?php

namespace App\Support\Period;

use Carbon\CarbonImmutable;

interface PeriodInterface
{
    public function startDate(): CarbonImmutable;

    public function endDate(): CarbonImmutable;

    /**
     * Stable, URL-safe identifier used as suffix in cache keys.
     * Same period instance must always return the same string.
     */
    public function cacheKey(): string;

    /**
     * Human-readable label in French, used in UI (topbar, export headers).
     */
    public function label(): string;
}
