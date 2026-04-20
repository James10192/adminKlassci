<?php

namespace App\Support\Period;

/**
 * Whitelisted period types. Backing values match PeriodFactory::TYPE_* constants.
 * Used as the safe hydration boundary for user-provided input (#[Url] query strings).
 */
enum PeriodType: string
{
    case CurrentMonth = 'current-month';
    case CurrentYear = 'current-year';
    case CustomRange = 'custom-range';

    /**
     * Safe hydration from untrusted input — never throws, rejects invalid values silently.
     * Returns the default when the input is null, empty, or not in the enum.
     */
    public static function tryFromSafe(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::default();
        }

        return self::tryFrom($value) ?? self::default();
    }

    public static function default(): self
    {
        return self::CurrentYear;
    }

    public function label(): string
    {
        return match ($this) {
            self::CurrentMonth => 'Mois en cours',
            self::CurrentYear => 'Année en cours',
            self::CustomRange => 'Plage personnalisée',
        };
    }
}
