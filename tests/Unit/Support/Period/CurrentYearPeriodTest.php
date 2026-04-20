<?php

use App\Support\Period\CurrentYearPeriod;
use App\Support\Period\PeriodInterface;
use Carbon\CarbonImmutable;

it('implements PeriodInterface', function () {
    expect(new CurrentYearPeriod())->toBeInstanceOf(PeriodInterface::class);
});

it('startDate is January 1st at 00:00', function () {
    $reference = CarbonImmutable::create(2026, 7, 12, 10, 30);
    $period = new CurrentYearPeriod($reference);

    expect($period->startDate()->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

it('endDate is December 31st at 23:59:59', function () {
    $reference = CarbonImmutable::create(2026, 4, 20);
    $period = new CurrentYearPeriod($reference);

    expect($period->endDate()->toDateTimeString())->toBe('2026-12-31 23:59:59');
});

it('cacheKey is year-qualified and stable within the same calendar year', function () {
    $jan = new CurrentYearPeriod(CarbonImmutable::create(2026, 1, 1));
    $dec = new CurrentYearPeriod(CarbonImmutable::create(2026, 12, 31));

    expect($jan->cacheKey())->toBe($dec->cacheKey())->toBe('year_2026');
});

it('cacheKey differs across years', function () {
    $y2026 = new CurrentYearPeriod(CarbonImmutable::create(2026, 6, 1));
    $y2027 = new CurrentYearPeriod(CarbonImmutable::create(2027, 6, 1));

    expect($y2026->cacheKey())->not->toBe($y2027->cacheKey());
});

it('label includes the year', function () {
    $period = new CurrentYearPeriod(CarbonImmutable::create(2026, 4, 20));

    expect($period->label())->toContain('2026');
});
