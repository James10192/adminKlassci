<?php

use App\Support\FcfaFormatter;

it('formats amounts below 1000 without prefix', function () {
    expect(FcfaFormatter::compact(0))->toBe('0');
    expect(FcfaFormatter::compact(50))->toBe('50');
    expect(FcfaFormatter::compact(999))->toBe('999');
});

it('formats thousands with k suffix', function () {
    expect(FcfaFormatter::compact(1_000))->toBe('1 k');
    expect(FcfaFormatter::compact(43_000))->toBe('43 k');
    expect(FcfaFormatter::compact(750_000))->toBe('750 k');
});

it('formats millions with M suffix and 1 decimal', function () {
    expect(FcfaFormatter::compact(1_000_000))->toBe('1,0 M');
    expect(FcfaFormatter::compact(12_500_000))->toBe('12,5 M');
    expect(FcfaFormatter::compact(4_607_000))->toBe('4,6 M');
});

it('millions() always divides by 1_000_000', function () {
    expect(FcfaFormatter::millions(5_977_000))->toBe('6,0');
    expect(FcfaFormatter::millions(1_370_000))->toBe('1,4');
});

it('full() keeps thin-space thousand separator', function () {
    expect(FcfaFormatter::full(0))->toBe('0');
    expect(FcfaFormatter::full(1_234_567))->toBe('1 234 567');
    expect(FcfaFormatter::full(43_000))->toBe('43 000');
});
