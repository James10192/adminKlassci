<?php

use App\Support\Period\CurrentMonthPeriod;
use App\Support\Period\PeriodInterface;
use Carbon\CarbonImmutable;

it('implements PeriodInterface', function () {
    expect(new CurrentMonthPeriod())->toBeInstanceOf(PeriodInterface::class);
});

it('startDate is the first day of the reference month at 00:00', function () {
    $reference = CarbonImmutable::create(2026, 4, 20, 15, 30, 45);
    $period = new CurrentMonthPeriod($reference);

    expect($period->startDate()->toDateTimeString())->toBe('2026-04-01 00:00:00');
});

it('endDate is the last day of the reference month at 23:59:59', function () {
    $reference = CarbonImmutable::create(2026, 2, 15);
    $period = new CurrentMonthPeriod($reference);

    // February 2026 has 28 days
    expect($period->endDate()->toDateTimeString())->toBe('2026-02-28 23:59:59');
});

it('cacheKey is stable for the same reference month', function () {
    $ref1 = CarbonImmutable::create(2026, 4, 1);
    $ref2 = CarbonImmutable::create(2026, 4, 30);

    expect((new CurrentMonthPeriod($ref1))->cacheKey())
        ->toBe((new CurrentMonthPeriod($ref2))->cacheKey())
        ->toBe('month_2026_04');
});

it('cacheKey differs across months', function () {
    $april = new CurrentMonthPeriod(CarbonImmutable::create(2026, 4, 15));
    $may = new CurrentMonthPeriod(CarbonImmutable::create(2026, 5, 15));

    expect($april->cacheKey())->not->toBe($may->cacheKey());
});

it('label returns french month name and year', function () {
    $period = new CurrentMonthPeriod(CarbonImmutable::create(2026, 4, 15));

    expect($period->label())->toContain('avril')->toContain('2026');
});
