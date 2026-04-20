<?php

namespace App\Services;

use RuntimeException;

/**
 * Signs short-lived HMAC-SHA256 tokens for cross-app SSO (master → tenant).
 *
 * Why custom HMAC instead of JWT: Laravel signed URLs depend on the local APP_KEY,
 * which differs between master and tenant apps. JWT libraries (firebase/php-jwt,
 * tymon/jwt-auth) add ~500+ LOC of dependency for needs we don't have (no external
 * interop, no complex claims). HMAC-SHA256 with a shared secret gives the same
 * security guarantee in ~30 lines and zero dependencies.
 *
 * Token format: base64url(payload_json) . "." . hex(hmac_sha256(payload_b64, secret))
 *
 * Payload claims:
 *   - tenant_code   : target tenant (tenant middleware checks it matches config('app.tenant_code'))
 *   - user_email    : user to log in as (tenant looks up by email)
 *   - redirect_to   : path to redirect after login (e.g. "/paiements/suivi")
 *   - exp           : unix timestamp, token rejected if past
 *   - nonce         : random bytes, makes each token unique (prevents log collision, not replay)
 *   - issued_by     : group_member email (for audit, not verified)
 *
 * Not single-use: an attacker intercepting a token can replay it within the 2min window.
 * Mitigation is short expiry + HTTPS transport.
 */
class SsoTokenSigner
{
    private const ALGO = 'sha256';
    private const DEFAULT_TTL_SECONDS = 120; // 2 minutes — matches critic review recommendation

    public function sign(array $payload, ?int $ttlSeconds = null): string
    {
        $secret = $this->getSecret();

        $payload = array_merge($payload, [
            'exp' => time() + ($ttlSeconds ?? self::DEFAULT_TTL_SECONDS),
            'nonce' => bin2hex(random_bytes(8)),
        ]);

        $payloadB64 = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac(self::ALGO, $payloadB64, $secret);

        return $payloadB64 . '.' . $signature;
    }

    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadB64, $signature] = $parts;

        $expected = hash_hmac(self::ALGO, $payloadB64, $this->getSecret());
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        try {
            $payload = json_decode($this->base64UrlDecode($payloadB64), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($payload) || ! isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private function getSecret(): string
    {
        $secret = env('GROUP_SSO_SHARED_SECRET');

        if (! $secret || strlen($secret) < 32) {
            throw new RuntimeException('GROUP_SSO_SHARED_SECRET must be set and at least 32 chars');
        }

        return $secret;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=');
        return base64_decode(strtr($padded, '-_', '+/'), true) ?: '';
    }
}
