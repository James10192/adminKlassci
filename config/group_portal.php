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

    /*
    |--------------------------------------------------------------------------
    | Subscription alerts banner (PR7a)
    |--------------------------------------------------------------------------
    |
    | Permanent banner at the top of every /groupe page, mirroring the
    | KLASSCIv2 PaywallMiddleware pattern: surfaces the worst subscription
    | expiry tier across the group's tenants so the founder sees a single
    | actionable summary instead of digging into GroupAlertsWidget.
    |
    |   GROUP_PORTAL_ALERTS_BANNER_ENABLED=false   # kill switch
    |
    | Tier thresholds are expressed in days remaining on
    | `tenants.subscription_end_date`:
    |
    |   expired  : days < 0
    |   urgent   : days <= subscription_urgent_days    (maps to AlertSeverity::Critical)
    |   warning  : days <= subscription_warning_days   (maps to AlertSeverity::Warning)
    |   info     : days <= subscription_info_days      (maps to AlertSeverity::Info)
    |   null     : days > subscription_info_days OR end_date is null (free tier)
    |
    | Free-tier tenants (subscription_end_date = null) are always ignored —
    | they have no expiry to worry about.
    */
    'alerts_banner_enabled' => env('GROUP_PORTAL_ALERTS_BANNER_ENABLED', true),
    'subscription_urgent_days' => env('GROUP_PORTAL_SUBSCRIPTION_URGENT_DAYS', 7),
    'subscription_warning_days' => env('GROUP_PORTAL_SUBSCRIPTION_WARNING_DAYS', 14),
    'subscription_info_days' => env('GROUP_PORTAL_SUBSCRIPTION_INFO_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Health alerts (PR7b)
    |--------------------------------------------------------------------------
    |
    | Additional group-level alerts surfaced in GroupAlertsWidget (NOT the
    | permanent banner — that remains reserved for subscription expiry).
    | Four families: plan overage, stale tenant, SSL cert expiry, enrollment
    | decline. Single coarse kill switch (per-alert toggles proved ops burden
    | without operational gain in review).
    |
    |   GROUP_PORTAL_HEALTH_ALERTS_ENABLED=false
    |
    | PlanMismatch replaces the generic QuotaExceeded alert for the `students`
    | quota specifically — the message is richer (plan name + upgrade hint).
    | Other quotas (users, staff, storage) still flow through QuotaExceeded.
    */
    'health_alerts_enabled' => env('GROUP_PORTAL_HEALTH_ALERTS_ENABLED', true),

    'plan_overage_warning_pct' => env('GROUP_PORTAL_PLAN_OVERAGE_WARNING_PCT', 100),
    'plan_overage_critical_pct' => env('GROUP_PORTAL_PLAN_OVERAGE_CRITICAL_PCT', 110),

    'stale_tenant_days' => env('GROUP_PORTAL_STALE_TENANT_DAYS', 30),

    // Group-level SSL thresholds are intentionally more conservative than the
    // per-tenant TenantHealthCheck thresholds (30/7) so the founder is alerted
    // before any single tenant flips to degraded/unhealthy status.
    'ssl_expiry_warning_days' => env('GROUP_PORTAL_SSL_EXPIRY_WARNING_DAYS', 15),
    'ssl_expiry_critical_days' => env('GROUP_PORTAL_SSL_EXPIRY_CRITICAL_DAYS', 7),

    // Single-month dips are noise; the two-consecutive-months requirement in
    // EnrollmentTrendAnalyzer filters for genuine trends.
    'enrollment_decline_threshold_pct' => env('GROUP_PORTAL_ENROLLMENT_DECLINE_THRESHOLD_PCT', 10),

    // Unpaid invoices (PR7c): per-tenant balance_due threshold in FCFA.
    // Pre-aggregated from the master-DB `invoices` table (status sent|overdue)
    // so a group with 20 tenants incurs a single grouped SELECT, not 20 per-
    // tenant queries. Thresholds match the general KLASSCI spending bands.
    'unpaid_invoices_warning_fcfa' => env('GROUP_PORTAL_UNPAID_INVOICES_WARNING_FCFA', 200000),
    'unpaid_invoices_critical_fcfa' => env('GROUP_PORTAL_UNPAID_INVOICES_CRITICAL_FCFA', 500000),
];
