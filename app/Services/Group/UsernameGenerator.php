<?php

namespace App\Services\Group;

use App\Models\GroupMember;
use Illuminate\Support\Str;

/**
 * Derives a login-friendly username from a member's display name.
 *
 *   "Jean Diomandé"    → "jean.diomande"
 *   "K. Marie Koffi"   → "k.marie.koffi" (fallback when we can't pick a
 *                         clean first+last pair)
 *   collision "jean.diomande" taken → "jean.diomande.2", .3, ...
 *
 * Accents are stripped via `Str::slug` (Intl-aware). Short names below two
 * chars collapse to the whole slugged string.
 */
class UsernameGenerator
{
    private const MAX_LENGTH = 80;

    public function generate(string $name): string
    {
        $base = $this->baseFromName($name);

        if ($base === '') {
            // Random 8-char suffix for pathological input (empty name,
            // punctuation-only). Not pretty but prevents NOT-NULL / UNIQUE
            // collisions from an obvious dev mistake.
            $base = 'membre.' . Str::lower(Str::random(6));
        }

        return $this->dedup($base);
    }

    private function baseFromName(string $name): string
    {
        $slug = Str::slug($name, '.');

        if ($slug === '') {
            return '';
        }

        // Trim to first two segments when the name has more — "Jean
        // Baptiste Diomandé" → "jean.diomande", not "jean.baptiste.diomande".
        // Keeps usernames short without losing identity.
        $parts = explode('.', $slug);
        if (count($parts) >= 3) {
            $slug = $parts[0] . '.' . end($parts);
        }

        return Str::limit($slug, self::MAX_LENGTH, '');
    }

    private function dedup(string $base): string
    {
        if (! $this->exists($base)) {
            return $base;
        }

        // Avoid an unbounded loop on weird concurrency — 1000 attempts is
        // already 1000 members with the same name, far past any realistic
        // tenant size. Falls back to a random tail after that.
        for ($i = 2; $i <= 1000; $i++) {
            $candidate = $base . '.' . $i;
            if (! $this->exists($candidate)) {
                return $candidate;
            }
        }

        return $base . '.' . Str::lower(Str::random(4));
    }

    private function exists(string $username): bool
    {
        return GroupMember::query()->where('username', $username)->exists();
    }
}
