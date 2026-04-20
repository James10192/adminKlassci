<?php

namespace App\Support\Period;

use Carbon\CarbonImmutable;

final readonly class CurrentYearPeriod implements PeriodInterface
{
    public function __construct(private CarbonImmutable $reference = new CarbonImmutable())
    {
    }

    public function startDate(): CarbonImmutable
    {
        return $this->reference->startOfYear();
    }

    public function endDate(): CarbonImmutable
    {
        return $this->reference->endOfYear();
    }

    public function cacheKey(): string
    {
        return 'year_' . $this->reference->format('Y');
    }

    public function label(): string
    {
        return 'Année ' . $this->reference->format('Y');
    }
}
