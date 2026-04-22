<?php

namespace App\Services\Group;

use Illuminate\Support\Str;

/**
 * Generates the one-time password emailed to a newly invited group member.
 *
 * Uses `Str::password()` (Laravel 10+) — cryptographically safe, unlike the
 * older `Str::random()` which predates the dedicated password helper. Mixes
 * letters + digits + symbols so the output meets "strong" requirements any
 * reasonable provider enforces.
 *
 * Wrapped in a service (not a static helper) so tests can swap it for a
 * deterministic stub — no point fighting real entropy in assertions.
 */
class TemporaryPasswordGenerator
{
    public function __construct(
        protected int $length = 16,
    ) {
    }

    public function generate(): string
    {
        return Str::password(
            length: $this->length,
            letters: true,
            numbers: true,
            symbols: true,
        );
    }
}
