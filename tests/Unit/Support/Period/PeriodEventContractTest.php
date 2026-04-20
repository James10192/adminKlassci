<?php

use App\Support\Period\PeriodEventContract;

it('EVENT_NAME is a frozen stable string (consumer contract)', function () {
    expect(PeriodEventContract::EVENT_NAME)->toBe('klassci:group-portal:period-change');
});

it('EVENT_NAME uses a namespaced format to avoid collisions', function () {
    $name = PeriodEventContract::EVENT_NAME;

    expect($name)
        ->toStartWith('klassci:')
        ->toContain(':group-portal:')
        ->not->toContain(' ')
        ->not->toContain('_');
});

it('PAYLOAD_KEYS lists exactly the documented fields in order', function () {
    expect(PeriodEventContract::PAYLOAD_KEYS)
        ->toBe(['type', 'start', 'end', 'label']);
});

it('PAYLOAD_KEYS does NOT expose cacheKey (backend internal)', function () {
    expect(PeriodEventContract::PAYLOAD_KEYS)->not->toContain('cacheKey');
});

it('is final and cannot be subclassed (frozen contract)', function () {
    $reflection = new ReflectionClass(PeriodEventContract::class);

    expect($reflection->isFinal())->toBeTrue();
});

it('constants are public (consumable by widgets and tests)', function () {
    $reflection = new ReflectionClass(PeriodEventContract::class);

    foreach (['EVENT_NAME', 'PAYLOAD_KEYS'] as $constant) {
        $ref = $reflection->getReflectionConstant($constant);
        expect($ref)->not->toBeFalse();
        expect($ref->isPublic())->toBeTrue();
    }
});
