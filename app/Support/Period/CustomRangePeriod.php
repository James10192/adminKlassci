<?php

namespace App\Support\Period;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class CustomRangePeriod implements PeriodInterface
{
    public function __construct(
        private CarbonImmutable $start,
        private CarbonImmutable $end,
    ) {
        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException(
                "CustomRangePeriod: start ({$start->toDateString()}) must be before or equal to end ({$end->toDateString()})"
            );
        }
    }

    public function startDate(): CarbonImmutable
    {
        return $this->start->startOfDay();
    }

    public function endDate(): CarbonImmutable
    {
        return $this->end->endOfDay();
    }

    public function cacheKey(): string
    {
        return 'custom_' . $this->start->format('Ymd') . '_' . $this->end->format('Ymd');
    }

    public function label(): string
    {
        return $this->start->locale('fr_FR')->isoFormat('D MMM YYYY')
            . ' → '
            . $this->end->locale('fr_FR')->isoFormat('D MMM YYYY');
    }
}
