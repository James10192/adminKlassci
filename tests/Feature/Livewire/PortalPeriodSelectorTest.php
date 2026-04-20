<?php

use App\Livewire\Group\PortalPeriodSelector;
use App\Support\Period\CurrentMonthPeriod;
use App\Support\Period\CurrentYearPeriod;
use App\Support\Period\CustomRangePeriod;
use App\Support\Period\PeriodType;
use Livewire\Livewire;

it('renders with the default period when no URL param is provided', function () {
    Livewire::test(PortalPeriodSelector::class)
        ->assertSet('periodType', PeriodType::CurrentYear->value)
        ->assertSee('Année en cours');
});

it('hydrates from a valid URL parameter', function () {
    Livewire::withQueryParams(['period' => 'current-month'])
        ->test(PortalPeriodSelector::class)
        ->assertSet('periodType', 'current-month')
        ->assertSee('Mois en cours');
});

it('falls back to default when URL parameter is malicious (XSS)', function () {
    $xssPayload = '<script>alert(1)</script>';

    Livewire::withQueryParams(['period' => $xssPayload])
        ->test(PortalPeriodSelector::class)
        ->assertSet('periodType', PeriodType::CurrentYear->value)
        ->assertDontSee($xssPayload, false);
});

it('falls back to default when URL parameter is unknown', function () {
    Livewire::withQueryParams(['period' => 'quarterly'])
        ->test(PortalPeriodSelector::class)
        ->assertSet('periodType', PeriodType::CurrentYear->value);
});

it('updating periodType to an invalid value re-normalizes to default', function () {
    Livewire::test(PortalPeriodSelector::class)
        ->set('periodType', 'invalid-type')
        ->assertSet('periodType', PeriodType::CurrentYear->value);
});

it('resolvedPeriod returns CurrentYearPeriod for current-year type', function () {
    $component = Livewire::test(PortalPeriodSelector::class)
        ->set('periodType', 'current-year');

    expect($component->instance()->resolvedPeriod)->toBeInstanceOf(CurrentYearPeriod::class);
});

it('resolvedPeriod returns CurrentMonthPeriod for current-month type', function () {
    $component = Livewire::test(PortalPeriodSelector::class)
        ->set('periodType', 'current-month');

    expect($component->instance()->resolvedPeriod)->toBeInstanceOf(CurrentMonthPeriod::class);
});

it('resolvedPeriod returns null for custom-range when dates incomplete', function () {
    $component = Livewire::test(PortalPeriodSelector::class)
        ->set('periodType', 'custom-range');

    expect($component->instance()->resolvedPeriod)->toBeNull();
});

it('resolvedPeriod returns CustomRangePeriod when both dates present and valid', function () {
    $component = Livewire::test(PortalPeriodSelector::class)
        ->set('periodType', 'custom-range')
        ->set('customStart', '2026-01-01')
        ->set('customEnd', '2026-03-31');

    expect($component->instance()->resolvedPeriod)->toBeInstanceOf(CustomRangePeriod::class);
});

it('resolvedPeriod returns null when custom dates are malformed (silent)', function () {
    $component = Livewire::test(PortalPeriodSelector::class)
        ->set('periodType', 'custom-range')
        ->set('customStart', 'not-a-date')
        ->set('customEnd', '<xss>');

    expect($component->instance()->resolvedPeriod)->toBeNull();
});
