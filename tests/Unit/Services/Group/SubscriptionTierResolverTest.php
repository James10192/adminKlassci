<?php

use App\Models\Tenant;
use App\Services\Group\SubscriptionTierResolver;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-04-21 10:00:00');

    config()->set('group_portal.subscription_urgent_days', 7);
    config()->set('group_portal.subscription_warning_days', 14);
    config()->set('group_portal.subscription_info_days', 30);
});

afterEach(function () {
    Carbon::setTestNow();
});

function tenantWithEndDate(?string $endDate): Tenant
{
    $tenant = new Tenant();
    $tenant->setRawAttributes([
        'name' => 'Fixture',
        'code' => 'fixture',
        'subscription_end_date' => $endDate,
    ], true);

    return $tenant;
}

it('returns null when the tenant has no subscription end date (free tier)', function () {
    $resolver = new SubscriptionTierResolver();

    expect($resolver->resolveTier(tenantWithEndDate(null)))->toBeNull();
});

it('returns expired when the end date is in the past', function () {
    $resolver = new SubscriptionTierResolver();

    expect($resolver->resolveTier(tenantWithEndDate('2026-04-20')))
        ->toBe(SubscriptionTierResolver::TIER_EXPIRED);
});

it('returns urgent within the 7 day window', function () {
    $resolver = new SubscriptionTierResolver();

    expect($resolver->resolveTier(tenantWithEndDate('2026-04-25')))
        ->toBe(SubscriptionTierResolver::TIER_URGENT);
    expect($resolver->resolveTier(tenantWithEndDate('2026-04-28')))
        ->toBe(SubscriptionTierResolver::TIER_URGENT);
});

it('returns warning between 8 and 14 days', function () {
    $resolver = new SubscriptionTierResolver();

    expect($resolver->resolveTier(tenantWithEndDate('2026-04-30')))
        ->toBe(SubscriptionTierResolver::TIER_WARNING);
    expect($resolver->resolveTier(tenantWithEndDate('2026-05-05')))
        ->toBe(SubscriptionTierResolver::TIER_WARNING);
});

it('returns info between 15 and 30 days', function () {
    $resolver = new SubscriptionTierResolver();

    expect($resolver->resolveTier(tenantWithEndDate('2026-05-06')))
        ->toBe(SubscriptionTierResolver::TIER_INFO);
    expect($resolver->resolveTier(tenantWithEndDate('2026-05-21')))
        ->toBe(SubscriptionTierResolver::TIER_INFO);
});

it('returns null when the end date is beyond the info window', function () {
    $resolver = new SubscriptionTierResolver();

    expect($resolver->resolveTier(tenantWithEndDate('2026-05-22')))->toBeNull();
    expect($resolver->resolveTier(tenantWithEndDate('2027-01-01')))->toBeNull();
});

it('honours overridden thresholds from config', function () {
    config()->set('group_portal.subscription_urgent_days', 3);
    config()->set('group_portal.subscription_warning_days', 6);
    config()->set('group_portal.subscription_info_days', 10);

    $resolver = new SubscriptionTierResolver();

    expect($resolver->resolveTier(tenantWithEndDate('2026-04-23')))
        ->toBe(SubscriptionTierResolver::TIER_URGENT);
    expect($resolver->resolveTier(tenantWithEndDate('2026-04-26')))
        ->toBe(SubscriptionTierResolver::TIER_WARNING);
    expect($resolver->resolveTier(tenantWithEndDate('2026-04-30')))
        ->toBe(SubscriptionTierResolver::TIER_INFO);
    expect($resolver->resolveTier(tenantWithEndDate('2026-05-05')))->toBeNull();
});

it('picks the most urgent tier from a mixed list', function () {
    $resolver = new SubscriptionTierResolver();

    expect($resolver->worstTier([
        SubscriptionTierResolver::TIER_INFO,
        SubscriptionTierResolver::TIER_WARNING,
        null,
    ]))->toBe(SubscriptionTierResolver::TIER_WARNING);

    expect($resolver->worstTier([
        SubscriptionTierResolver::TIER_INFO,
        SubscriptionTierResolver::TIER_EXPIRED,
        SubscriptionTierResolver::TIER_URGENT,
    ]))->toBe(SubscriptionTierResolver::TIER_EXPIRED);

    expect($resolver->worstTier([null, null]))->toBeNull();
});

it('maps each tier to the expected AlertSeverity enum', function () {
    $resolver = new SubscriptionTierResolver();

    expect($resolver->severityForTier(SubscriptionTierResolver::TIER_EXPIRED))
        ->toBe(\App\Enums\AlertSeverity::Critical);
    expect($resolver->severityForTier(SubscriptionTierResolver::TIER_URGENT))
        ->toBe(\App\Enums\AlertSeverity::Critical);
    expect($resolver->severityForTier(SubscriptionTierResolver::TIER_WARNING))
        ->toBe(\App\Enums\AlertSeverity::Warning);
    expect($resolver->severityForTier(SubscriptionTierResolver::TIER_INFO))
        ->toBe(\App\Enums\AlertSeverity::Info);
});
