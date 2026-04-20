<?php

namespace App\Support\Period;

use Carbon\CarbonImmutable;

final readonly class CurrentMonthPeriod implements PeriodInterface
{
    public function __construct(private CarbonImmutable $reference = new CarbonImmutable())
    {
    }

    public function startDate(): CarbonImmutable
    {
        return $this->reference->startOfMonth();
    }

    public function endDate(): CarbonImmutable
    {
        return $this->reference->endOfMonth();
    }

    public function cacheKey(): string
    {
        return 'month_' . $this->reference->format('Y_m');
    }

    public function label(): string
    {
        return $this->reference->locale('fr_FR')->isoFormat('MMMM YYYY');
    }
}
