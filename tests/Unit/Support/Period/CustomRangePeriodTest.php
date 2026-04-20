<?php

use App\Support\Period\CustomRangePeriod;
use App\Support\Period\PeriodInterface;
use Carbon\CarbonImmutable;

it('implements PeriodInterface', function () {
    $period = new CustomRangePeriod(
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 3, 31),
    );

    expect($period)->toBeInstanceOf(PeriodInterface::class);
});

it('throws when start is after end', function () {
    new CustomRangePeriod(
        CarbonImmutable::create(2026, 4, 20),
        CarbonImmutable::create(2026, 4, 1),
    );
})->throws(InvalidArgumentException::class, 'start');

it('accepts start equal to end (single-day range)', function () {
    $day = CarbonImmutable::create(2026, 4, 20);
    $period = new CustomRangePeriod($day, $day);

    expect($period->startDate()->toDateString())->toBe('2026-04-20');
    expect($period->endDate()->toDateString())->toBe('2026-04-20');
});

it('startDate is normalized to start of day', function () {
    $period = new CustomRangePeriod(
        CarbonImmutable::create(2026, 4, 20, 15, 30),
        CarbonImmutable::create(2026, 4, 30, 8, 0),
    );

    expect($period->startDate()->toDateTimeString())->toBe('2026-04-20 00:00:00');
});

it('endDate is normalized to end of day', function () {
    $period = new CustomRangePeriod(
        CarbonImmutable::create(2026, 4, 1),
        CarbonImmutable::create(2026, 4, 30, 10, 0),
    );

    expect($period->endDate()->toDateTimeString())->toBe('2026-04-30 23:59:59');
});

it('cacheKey is unique per range', function () {
    $a = new CustomRangePeriod(
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 3, 31),
    );
    $b = new CustomRangePeriod(
        CarbonImmutable::create(2026, 4, 1),
        CarbonImmutable::create(2026, 6, 30),
    );

    expect($a->cacheKey())
        ->toBe('custom_20260101_20260331')
        ->and($b->cacheKey())->toBe('custom_20260401_20260630')
        ->and($a->cacheKey())->not->toBe($b->cacheKey());
});

it('label contains both dates in french format', function () {
    $period = new CustomRangePeriod(
        CarbonImmutable::create(2026, 1, 15),
        CarbonImmutable::create(2026, 3, 20),
    );

    expect($period->label())
        ->toContain('15')
        ->toContain('20')
        ->toContain('2026')
        ->toContain('→');
});
