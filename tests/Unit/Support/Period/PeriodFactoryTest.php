<?php

use App\Support\Period\CurrentMonthPeriod;
use App\Support\Period\CurrentYearPeriod;
use App\Support\Period\CustomRangePeriod;
use App\Support\Period\PeriodFactory;
use App\Support\Period\PeriodInterface;
use Carbon\CarbonImmutable;

it('make(current-month) returns a CurrentMonthPeriod', function () {
    expect(PeriodFactory::make(PeriodFactory::TYPE_CURRENT_MONTH))
        ->toBeInstanceOf(CurrentMonthPeriod::class)
        ->toBeInstanceOf(PeriodInterface::class);
});

it('make(current-year) returns a CurrentYearPeriod', function () {
    expect(PeriodFactory::make(PeriodFactory::TYPE_CURRENT_YEAR))
        ->toBeInstanceOf(CurrentYearPeriod::class);
});

it('make(custom-range) with ISO date strings builds a CustomRangePeriod', function () {
    $period = PeriodFactory::make(PeriodFactory::TYPE_CUSTOM_RANGE, [
        'start' => '2026-01-01',
        'end' => '2026-03-31',
    ]);

    expect($period)->toBeInstanceOf(CustomRangePeriod::class);
    expect($period->startDate()->toDateString())->toBe('2026-01-01');
    expect($period->endDate()->toDateString())->toBe('2026-03-31');
});

it('make(custom-range) accepts CarbonImmutable instances', function () {
    $period = PeriodFactory::make(PeriodFactory::TYPE_CUSTOM_RANGE, [
        'start' => CarbonImmutable::create(2026, 4, 1),
        'end' => CarbonImmutable::create(2026, 4, 30),
    ]);

    expect($period->cacheKey())->toBe('custom_20260401_20260430');
});

it('make(custom-range) throws without start/end params', function () {
    PeriodFactory::make(PeriodFactory::TYPE_CUSTOM_RANGE);
})->throws(InvalidArgumentException::class, "'start' and 'end'");

it('throws on unknown type with a helpful message', function () {
    PeriodFactory::make('quarterly');
})->throws(InvalidArgumentException::class, "Unknown period type 'quarterly'");

it('default() returns a CurrentYearPeriod (documented default)', function () {
    expect(PeriodFactory::default())->toBeInstanceOf(CurrentYearPeriod::class);
    expect(PeriodFactory::DEFAULT_TYPE)->toBe(PeriodFactory::TYPE_CURRENT_YEAR);
});

it('type constants are stable strings (public API)', function () {
    expect(PeriodFactory::TYPE_CURRENT_MONTH)->toBe('current-month');
    expect(PeriodFactory::TYPE_CURRENT_YEAR)->toBe('current-year');
    expect(PeriodFactory::TYPE_CUSTOM_RANGE)->toBe('custom-range');
});
