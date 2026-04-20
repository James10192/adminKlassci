<?php

namespace App\Support\Period;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PeriodFactory
{
    public const TYPE_CURRENT_MONTH = 'current-month';
    public const TYPE_CURRENT_YEAR = 'current-year';
    public const TYPE_CUSTOM_RANGE = 'custom-range';

    public const DEFAULT_TYPE = self::TYPE_CURRENT_YEAR;

    /**
     * Build a Period from a type string and optional params.
     *
     * @param  string  $type  one of TYPE_* constants
     * @param  array<string,mixed>  $params  optional: ['start' => '2026-01-01', 'end' => '2026-06-30']
     */
    public static function make(string $type, array $params = []): PeriodInterface
    {
        return match ($type) {
            self::TYPE_CURRENT_MONTH => new CurrentMonthPeriod(),
            self::TYPE_CURRENT_YEAR => new CurrentYearPeriod(),
            self::TYPE_CUSTOM_RANGE => self::makeCustomRange($params),
            default => throw new InvalidArgumentException(
                "Unknown period type '{$type}'. Expected one of: "
                . implode(', ', [self::TYPE_CURRENT_MONTH, self::TYPE_CURRENT_YEAR, self::TYPE_CUSTOM_RANGE])
            ),
        };
    }

    public static function default(): PeriodInterface
    {
        return self::make(self::DEFAULT_TYPE);
    }

    /**
     * @param  array<string,mixed>  $params
     */
    private static function makeCustomRange(array $params): CustomRangePeriod
    {
        if (!isset($params['start'], $params['end'])) {
            throw new InvalidArgumentException(
                "CustomRangePeriod requires 'start' and 'end' in params (ISO 8601 date strings or CarbonImmutable)"
            );
        }

        $start = $params['start'] instanceof CarbonImmutable
            ? $params['start']
            : CarbonImmutable::parse($params['start']);

        $end = $params['end'] instanceof CarbonImmutable
            ? $params['end']
            : CarbonImmutable::parse($params['end']);

        return new CustomRangePeriod($start, $end);
    }
}
