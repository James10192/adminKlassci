<?php

use App\Enums\AlertType;
use App\Services\Group\AlertRoleMatcher;

it('fondateur receives every alert type', function () {
    $matcher = new AlertRoleMatcher();

    foreach (AlertType::cases() as $type) {
        expect($matcher->isSubscribed('fondateur', $type))->toBeTrue();
    }
});

it('directeur_general receives every alert type (full operational oversight)', function () {
    $matcher = new AlertRoleMatcher();

    foreach (AlertType::cases() as $type) {
        expect($matcher->isSubscribed('directeur_general', $type))->toBeTrue();
    }
});

it('directeur_financier receives financial alerts only', function () {
    $matcher = new AlertRoleMatcher();

    // Accepted:
    expect($matcher->isSubscribed('directeur_financier', AlertType::SubscriptionExpired))->toBeTrue();
    expect($matcher->isSubscribed('directeur_financier', AlertType::UnpaidInvoices))->toBeTrue();
    expect($matcher->isSubscribed('directeur_financier', AlertType::PlanMismatch))->toBeTrue();
    expect($matcher->isSubscribed('directeur_financier', AlertType::QuotaExceeded))->toBeTrue();
    expect($matcher->isSubscribed('directeur_financier', AlertType::ActiveReliquats))->toBeTrue();

    // Rejected (ops signals, CFO doesn't need them):
    expect($matcher->isSubscribed('directeur_financier', AlertType::SslExpiring))->toBeFalse();
    expect($matcher->isSubscribed('directeur_financier', AlertType::StaleTenant))->toBeFalse();
    expect($matcher->isSubscribed('directeur_financier', AlertType::TeacherOverload))->toBeFalse();
    expect($matcher->isSubscribed('directeur_financier', AlertType::HighAttrition))->toBeFalse();
});

it('unknown roles receive nothing (default-deny)', function () {
    $matcher = new AlertRoleMatcher();

    expect($matcher->isSubscribed('unknown_role', AlertType::SubscriptionExpired))->toBeFalse();
    expect($matcher->isSubscribed('', AlertType::UnpaidInvoices))->toBeFalse();
});

it('isSubscribedByValue handles invalid AlertType strings gracefully', function () {
    $matcher = new AlertRoleMatcher();

    expect($matcher->isSubscribedByValue('fondateur', 'not_a_real_type'))->toBeFalse();
    expect($matcher->isSubscribedByValue('fondateur', 'subscription_expired'))->toBeTrue();
});
