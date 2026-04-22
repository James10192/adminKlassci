<?php

use App\Enums\AlertSeverity;
use App\Enums\SslTier;
use App\Enums\StaleTier;
use App\Enums\SubscriptionTier;
use App\Services\Group\HealthCheckAlertResolver;
use App\Services\Group\SubscriptionTierResolver;

/**
 * Value-parity tests between the backed enums and the string constants on
 * the resolver classes. The enums MUST preserve the same string values so
 * cached `$health[...]` arrays (Cache::remember for 5 min) stay readable
 * across deploys.
 */

it('SubscriptionTier enum values match SubscriptionTierResolver constants', function () {
    expect(SubscriptionTier::Expired->value)->toBe(SubscriptionTierResolver::TIER_EXPIRED);
    expect(SubscriptionTier::Urgent->value)->toBe(SubscriptionTierResolver::TIER_URGENT);
    expect(SubscriptionTier::Warning->value)->toBe(SubscriptionTierResolver::TIER_WARNING);
    expect(SubscriptionTier::Info->value)->toBe(SubscriptionTierResolver::TIER_INFO);
});

it('SslTier enum values match HealthCheckAlertResolver constants', function () {
    expect(SslTier::Critical->value)->toBe(HealthCheckAlertResolver::SSL_TIER_CRITICAL);
    expect(SslTier::Warning->value)->toBe(HealthCheckAlertResolver::SSL_TIER_WARNING);
});

it('StaleTier enum values match HealthCheckAlertResolver constants', function () {
    expect(StaleTier::Unhealthy->value)->toBe(HealthCheckAlertResolver::STALE_TIER_UNHEALTHY);
    expect(StaleTier::Stale->value)->toBe(HealthCheckAlertResolver::STALE_TIER_STALE);
});

it('SubscriptionTier::rank() orders expired as most urgent', function () {
    expect(SubscriptionTier::Expired->rank())->toBeLessThan(SubscriptionTier::Urgent->rank());
    expect(SubscriptionTier::Urgent->rank())->toBeLessThan(SubscriptionTier::Warning->rank());
    expect(SubscriptionTier::Warning->rank())->toBeLessThan(SubscriptionTier::Info->rank());
});

it('SubscriptionTier::severity() matches the legacy severityForTier mapping', function () {
    $resolver = new SubscriptionTierResolver();

    foreach (SubscriptionTier::cases() as $tier) {
        expect($tier->severity())->toBe($resolver->severityForTier($tier->value));
    }
});

it('SslTier::severity() matches the legacy severityForSslTier mapping', function () {
    $resolver = new HealthCheckAlertResolver();

    foreach (SslTier::cases() as $tier) {
        expect($tier->severity())->toBe($resolver->severityForSslTier($tier->value));
    }
});

it('StaleTier::severity() matches the legacy severityForStaleTier mapping', function () {
    $resolver = new HealthCheckAlertResolver();

    foreach (StaleTier::cases() as $tier) {
        expect($tier->severity())->toBe($resolver->severityForStaleTier($tier->value));
    }
});

it('SubscriptionTier::from() round-trips legacy string values', function () {
    // Proves that any cached tier string ever written by pre-enum code can
    // be re-hydrated into the new enum without breakage.
    foreach ([
        SubscriptionTierResolver::TIER_EXPIRED,
        SubscriptionTierResolver::TIER_URGENT,
        SubscriptionTierResolver::TIER_WARNING,
        SubscriptionTierResolver::TIER_INFO,
    ] as $legacyString) {
        $enum = SubscriptionTier::from($legacyString);
        expect($enum->value)->toBe($legacyString);
    }
});
