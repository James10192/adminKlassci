<?php

it('topbar period partial renders nothing when feature flag is OFF', function () {
    config(['group_portal.period_selector_enabled' => false]);

    $html = view('filament.group.partials.topbar-period')->render();

    expect(trim($html))->toBe('');
});

it('topbar period partial renders the Livewire component when feature flag is ON', function () {
    config(['group_portal.period_selector_enabled' => true]);

    $html = view('filament.group.partials.topbar-period')->render();

    expect($html)->toContain('gp-topbar-slot');
    expect($html)->toContain('gp-period-selector');
});

it('debounce ms config has a sensible default', function () {
    expect(config('group_portal.period_selector_debounce_ms'))->toBeInt();
    expect(config('group_portal.period_selector_debounce_ms'))->toBeGreaterThanOrEqual(100);
    expect(config('group_portal.period_selector_debounce_ms'))->toBeLessThanOrEqual(1000);
});
