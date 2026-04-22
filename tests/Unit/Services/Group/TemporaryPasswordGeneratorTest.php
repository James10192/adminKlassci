<?php

use App\Services\Group\TemporaryPasswordGenerator;

it('generates a password of the configured length', function () {
    $generator = new TemporaryPasswordGenerator(length: 16);

    expect(strlen($generator->generate()))->toBe(16);
});

it('generates a different password on each call (entropy sanity check)', function () {
    $generator = new TemporaryPasswordGenerator();

    $samples = collect(range(1, 5))->map(fn () => $generator->generate());

    expect($samples->unique()->count())->toBe(5);
});

it('includes letters, digits and symbols in the output', function () {
    $generator = new TemporaryPasswordGenerator(length: 32);

    // Over 32 chars with all three classes enabled, the probability of
    // missing any one class is vanishingly small — effectively 0. If this
    // test ever flakes, the generator is broken.
    $pwd = $generator->generate();

    expect($pwd)->toMatch('/[a-zA-Z]/')
        ->and($pwd)->toMatch('/[0-9]/')
        ->and($pwd)->toMatch('/[^a-zA-Z0-9]/');
});
