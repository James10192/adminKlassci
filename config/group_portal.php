<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Period selector (PR4b foundation UI)
    |--------------------------------------------------------------------------
    |
    | When enabled, a Livewire period selector is injected in the group portal
    | topbar via PanelsRenderHook::TOPBAR_END. The selected period is persisted
    | in the URL query string as ?period=current-month|current-year|custom-range.
    |
    | Default OFF for production safety: the selector ships dark and is wired
    | to widget providers in PR4c. Activate progressively via .env:
    |
    |   GROUP_PORTAL_PERIOD_SELECTOR=true
    |
    */
    'period_selector_enabled' => env('GROUP_PORTAL_PERIOD_SELECTOR', false),

    /*
    | Debounce applied client-side (Alpine) before the Livewire state is updated.
    | Tuned for quick clicks without triggering one HTTP roundtrip per keystroke.
    */
    'period_selector_debounce_ms' => env('GROUP_PORTAL_PERIOD_DEBOUNCE_MS', 300),

    /*
    |--------------------------------------------------------------------------
    | Widgets period-aware (PR4e)
    |--------------------------------------------------------------------------
    |
    | When enabled, the 3 time-windowed widgets (KpiOverviewWidget +
    | RevenueComparisonWidget + GroupAgingWidget) listen to the period-change
    | event and re-render with the selected period. When disabled, they ignore
    | the event and always render with the default period (PR4c behaviour).
    |
    | Decoupled from `period_selector_enabled` so ops can roll out the event
    | producer and the consumers independently. Flip this only once the UI
    | selector has proved stable in production.
    |
    |   GROUP_PORTAL_WIDGETS_PERIOD_AWARE=true
    |
    */
    'widgets_period_aware' => env('GROUP_PORTAL_WIDGETS_PERIOD_AWARE', false),
];
