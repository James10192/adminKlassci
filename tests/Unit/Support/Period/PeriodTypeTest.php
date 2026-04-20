<?php

use App\Support\Period\PeriodFactory;
use App\Support\Period\PeriodType;

it('exposes the 3 expected cases with stable backing values', function () {
    expect(PeriodType::CurrentMonth->value)->toBe('current-month');
    expect(PeriodType::CurrentYear->value)->toBe('current-year');
    expect(PeriodType::CustomRange->value)->toBe('custom-range');
});

it('backing values match PeriodFactory type constants (contract)', function () {
    expect(PeriodType::CurrentMonth->value)->toBe(PeriodFactory::TYPE_CURRENT_MONTH);
    expect(PeriodType::CurrentYear->value)->toBe(PeriodFactory::TYPE_CURRENT_YEAR);
    expect(PeriodType::CustomRange->value)->toBe(PeriodFactory::TYPE_CUSTOM_RANGE);
});

it('default() returns CurrentYear (documented default)', function () {
    expect(PeriodType::default())->toBe(PeriodType::CurrentYear);
});

it('tryFromSafe(null) returns the default', function () {
    expect(PeriodType::tryFromSafe(null))->toBe(PeriodType::CurrentYear);
});

it('tryFromSafe("") returns the default', function () {
    expect(PeriodType::tryFromSafe(''))->toBe(PeriodType::CurrentYear);
});

it('tryFromSafe(valid value) returns the matching case', function () {
    expect(PeriodType::tryFromSafe('current-month'))->toBe(PeriodType::CurrentMonth);
    expect(PeriodType::tryFromSafe('custom-range'))->toBe(PeriodType::CustomRange);
});

it('tryFromSafe rejects invalid values silently with default fallback', function () {
    expect(PeriodType::tryFromSafe('quarterly'))->toBe(PeriodType::CurrentYear);
    expect(PeriodType::tryFromSafe('weekly'))->toBe(PeriodType::CurrentYear);
    expect(PeriodType::tryFromSafe('CURRENT-MONTH'))->toBe(PeriodType::CurrentYear); // case-sensitive
});

it('tryFromSafe rejects XSS payloads without throwing', function () {
    $xssPayloads = [
        '<script>alert(1)</script>',
        'javascript:alert(1)',
        '"><img src=x onerror=alert(1)>',
        "' OR 1=1 --",
        str_repeat('a', 10000),
    ];

    foreach ($xssPayloads as $payload) {
        expect(PeriodType::tryFromSafe($payload))->toBe(PeriodType::CurrentYear);
    }
});

it('label() returns french UI strings', function () {
    expect(PeriodType::CurrentMonth->label())->toBe('Mois en cours');
    expect(PeriodType::CurrentYear->label())->toBe('Année en cours');
    expect(PeriodType::CustomRange->label())->toBe('Plage personnalisée');
});
