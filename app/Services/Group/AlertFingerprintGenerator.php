<?php

namespace App\Services\Group;

/**
 * Deterministic fingerprint per alert, used by the dedup layer to avoid
 * spamming the same alert to the same recipient within a time window.
 *
 * `severity` is intentionally part of the key: a quota alert escalating from
 * Warning (90%) to Critical (100%) crosses fingerprints and re-notifies —
 * which is what the founder wants, the situation genuinely worsened.
 */
class AlertFingerprintGenerator
{
    /**
     * @param  array{tenant_code?: string, type: string, severity: string}  $alert
     */
    public function generate(int $groupId, array $alert): string
    {
        $payload = implode('|', [
            $groupId,
            $alert['tenant_code'] ?? '',
            $alert['type'] ?? '',
            $alert['severity'] ?? '',
        ]);

        return hash('sha256', $payload);
    }
}
