<?php

use App\Services\Group\TenantBillingContext;

it('is registered as scoped in the container', function () {
    // Scoped bindings resolve to the same instance within a single container scope,
    // but that instance is NOT a global singleton — it resets between requests.
    $instance1 = app(TenantBillingContext::class);
    $instance2 = app(TenantBillingContext::class);

    expect($instance1)->toBe($instance2);
    expect($instance1)->toBeInstanceOf(TenantBillingContext::class);
});

it('reset() clears all internal caches (required for Octane/queue safety)', function () {
    $context = app(TenantBillingContext::class);

    // resolveCategoryAmount with collection arguments — exercises the non-DB path.
    $inscription = (object) ['filiere_id' => 1, 'niveau_id' => 1, 'affectation_status' => 'affecté'];
    $category = (object) ['id' => 99, 'is_mandatory' => false, 'default_amount' => 0];
    $inscSubs = collect();
    $configurations = collect();

    $amount = $context->resolveCategoryAmount($inscription, $category, $inscSubs, $configurations);
    expect($amount)->toBe(0.0);

    $context->reset();

    // After reset, another call still works (structure didn't break).
    $amount = $context->resolveCategoryAmount($inscription, $category, $inscSubs, $configurations);
    expect($amount)->toBe(0.0);
});

it('resolveCategoryAmount returns subscription amount when subscription exists', function () {
    $context = app(TenantBillingContext::class);

    $inscription = (object) ['filiere_id' => 1, 'niveau_id' => 1, 'affectation_status' => 'affecté'];
    $category = (object) ['id' => 42, 'is_mandatory' => true, 'default_amount' => 500];
    $inscSubs = collect([
        (object) ['frais_category_id' => 42, 'amount' => 1500],
    ]);
    $configurations = collect();

    expect($context->resolveCategoryAmount($inscription, $category, $inscSubs, $configurations))
        ->toBe(1500.0);
});

it('resolveCategoryAmount returns 0 for non-mandatory category without subscription', function () {
    $context = app(TenantBillingContext::class);

    $inscription = (object) ['filiere_id' => 1, 'niveau_id' => 1];
    $category = (object) ['id' => 42, 'is_mandatory' => false, 'default_amount' => 500];
    $inscSubs = collect();
    $configurations = collect();

    expect($context->resolveCategoryAmount($inscription, $category, $inscSubs, $configurations))
        ->toBe(0.0);
});

it('resolveCategoryAmount falls back to default_amount for mandatory category without config', function () {
    $context = app(TenantBillingContext::class);

    $inscription = (object) ['filiere_id' => 1, 'niveau_id' => 1, 'affectation_status' => 'affecté'];
    $category = (object) ['id' => 42, 'is_mandatory' => true, 'default_amount' => 750];
    $inscSubs = collect();
    $configurations = collect();

    expect($context->resolveCategoryAmount($inscription, $category, $inscSubs, $configurations))
        ->toBe(750.0);
});

it('resolveCategoryAmount uses amount_affecte when affectation_status is "affecté"', function () {
    $context = app(TenantBillingContext::class);

    $inscription = (object) ['filiere_id' => 1, 'niveau_id' => 2, 'affectation_status' => 'affecté'];
    $category = (object) ['id' => 42, 'is_mandatory' => true, 'default_amount' => 0];
    $inscSubs = collect();
    // Key format: category_id . '_' . filiere_id . '_' . niveau_id
    $configurations = collect([
        '42_1_2' => collect([
            (object) [
                'frais_category_id' => 42,
                'filiere_id' => 1,
                'niveau_id' => 2,
                'amount' => 1000,
                'amount_affecte' => 2000,
                'amount_reaffecte' => 1500,
                'amount_non_affecte' => 3000,
            ],
        ]),
    ]);

    expect($context->resolveCategoryAmount($inscription, $category, $inscSubs, $configurations))
        ->toBe(2000.0);
});
