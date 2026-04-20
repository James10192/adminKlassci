<?php

use App\Livewire\Group\PortalPeriodSelector;
use App\Support\Period\PeriodEventContract;
use Livewire\Livewire;

it('dispatches period-change event when periodType changes to current-month', function () {
    Livewire::test(PortalPeriodSelector::class)
        ->set('periodType', 'current-month')
        ->assertDispatched(PeriodEventContract::EVENT_NAME);
});

it('dispatches period-change event with expected payload keys (no cacheKey)', function () {
    Livewire::test(PortalPeriodSelector::class)
        ->set('periodType', 'current-year')
        ->assertDispatched(PeriodEventContract::EVENT_NAME, function (string $name, array $params) {
            // Livewire 3 named args → $params is assoc: ['type' => ..., 'start' => ..., ...]
            expect(array_keys($params))->toBe(PeriodEventContract::PAYLOAD_KEYS);
            expect($params)->not->toHaveKey('cacheKey');

            return true;
        });
});

it('dispatched payload contains ISO8601 dates from PeriodInterface', function () {
    Livewire::test(PortalPeriodSelector::class)
        ->set('periodType', 'current-year')
        ->assertDispatched(PeriodEventContract::EVENT_NAME, function (string $name, array $params) {
            expect($params['type'])->toBe('current-year');
            expect($params['start'])->toMatch('/^\d{4}-01-01T00:00:00/');
            expect($params['end'])->toMatch('/^\d{4}-12-31T23:59:59/');
            expect($params['label'])->toContain((string) date('Y'));

            return true;
        });
});

it('does NOT dispatch when custom-range selected with missing dates', function () {
    Livewire::test(PortalPeriodSelector::class)
        ->set('periodType', 'custom-range')
        ->assertNotDispatched(PeriodEventContract::EVENT_NAME);
});

it('does NOT dispatch when custom-range dates are malformed', function () {
    Livewire::test(PortalPeriodSelector::class)
        ->set('periodType', 'custom-range')
        ->set('customStart', 'not-a-date')
        ->set('customEnd', '<xss>')
        ->assertNotDispatched(PeriodEventContract::EVENT_NAME);
});

it('dispatches when custom-range dates are both valid', function () {
    Livewire::test(PortalPeriodSelector::class)
        ->set('periodType', 'custom-range')
        ->set('customStart', '2026-01-15')
        ->set('customEnd', '2026-03-20')
        ->assertDispatched(PeriodEventContract::EVENT_NAME, function (string $name, array $params) {
            expect($params['type'])->toBe('custom-range');
            expect($params['start'])->toContain('2026-01-15');
            expect($params['end'])->toContain('2026-03-20');

            return true;
        });
});

it('injecting a malicious URL period falls back to default WITHOUT leaking payload', function () {
    Livewire::withQueryParams(['period' => '<script>alert(1)</script>'])
        ->test(PortalPeriodSelector::class)
        ->assertSet('periodType', 'current-year')
        ->assertDontSee('<script>', false);
    // No dispatch here — mount() normalizes in place, does not trigger updated* lifecycle hooks.
});
