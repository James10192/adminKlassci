<?php

use App\Services\Group\SubscriptionTierResolver;
use Illuminate\Support\Facades\Route;

/**
 * Structural + config tests for the PR7a subscription banner.
 * Behavior under an authenticated group founder (worst tier highlighted,
 * count of other tenants, dismiss flow) is covered by visual check.
 */

it('banner partial carries the alert role for critical tiers', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/partials/subscription-banner.blade.php')
    );

    expect($source)->toContain("'alert'");
    expect($source)->toContain("'status'");
    expect($source)->toContain("'assertive'");
    expect($source)->toContain("'polite'");
});

it('banner partial announces tone via data-tone', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/partials/subscription-banner.blade.php')
    );

    expect($source)->toContain('data-tone="{{ $tone }}"');
    expect($source)->toContain('data-tier="{{ $worstTier }}"');
});

it('banner partial short-circuits on the feature flag', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/partials/subscription-banner.blade.php')
    );

    expect($source)->toContain("config('group_portal.alerts_banner_enabled'");
});

it('banner partial honours the 4h session dismiss for non-critical tiers only', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/partials/subscription-banner.blade.php')
    );

    expect($source)->toContain('gp_subscription_banner_dismissed_until');

    // Only info + warning are allowed to be dismissed; critical tiers are never
    // added to that list. Match the dismissible array shape while tolerating
    // surrounding indentation.
    $pattern = '/\$dismissible\s*=\s*in_array\(\s*\$worstTier,\s*\[\s*'
        . 'SubscriptionTierResolver::TIER_INFO,\s*'
        . 'SubscriptionTierResolver::TIER_WARNING,\s*'
        . '\],\s*true\)/';

    expect(preg_match($pattern, $source))->toBe(1);
});

it('banner partial calls TenantAggregationService for the worst tier', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/partials/subscription-banner.blade.php')
    );

    expect($source)->toContain('TenantAggregationService');
    expect($source)->toContain("getGroupHealthMetrics");
    expect($source)->toContain('subscription_worst_tier');
});

it('banner partial points the action at the establishments list', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/partials/subscription-banner.blade.php')
    );

    expect($source)->toContain("route('filament.group.resources.establishments.index')");
});

it('GroupPanelProvider registers the banner on BODY_START', function () {
    $source = file_get_contents(
        app_path('Providers/Filament/GroupPanelProvider.php')
    );

    expect($source)->toContain('BODY_START');
    expect($source)->toContain('filament.group.partials.subscription-banner');
});

it('dismiss route is registered under the group auth guard', function () {
    $route = Route::getRoutes()->getByName('groupe.subscription-banner.dismiss');

    expect($route)->not->toBeNull();
    expect($route->methods())->toContain('POST');
    expect($route->middleware())->toContain('auth:group');
});

it('alert banner CSS ships the 3 tones', function () {
    $css = file_get_contents(public_path('css/groupe-portal.css'));

    expect($css)->toContain('.gp-alert-banner');
    expect($css)->toContain('.gp-alert-banner[data-tone="danger"]');
    expect($css)->toContain('.gp-alert-banner[data-tone="warning"]');
    expect($css)->toContain('.gp-alert-banner[data-tone="info"]');
});

it('feature flag defaults to enabled', function () {
    expect(config('group_portal.alerts_banner_enabled'))->toBe(true);
});

it('tier thresholds fall back to 7, 14, 30', function () {
    expect((int) config('group_portal.subscription_urgent_days'))->toBe(7);
    expect((int) config('group_portal.subscription_warning_days'))->toBe(14);
    expect((int) config('group_portal.subscription_info_days'))->toBe(30);
});

it('TenantAggregationService emits the worst-tier aggregate keys', function () {
    $source = file_get_contents(
        app_path('Services/TenantAggregationService.php')
    );

    expect($source)->toContain('subscription_worst_tier');
    expect($source)->toContain('subscription_expiring_total_count');
    expect($source)->toContain('subscription_urgent_count');
    expect($source)->toContain('subscription_warning_count');
    expect($source)->toContain('subscription_info_count');
    expect($source)->toContain('SubscriptionTierResolver');
});
