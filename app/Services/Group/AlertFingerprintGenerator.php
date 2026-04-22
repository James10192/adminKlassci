<?php

namespace App\Services\Group;

use App\Support\Alerts\AlertPayload;

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
    public function generate(int $groupId, AlertPayload $alert): string
    {
        $payload = implode('|', [
            $groupId,
            $alert->tenantCode ?? '',
            $alert->type->value,
            $alert->severity->value,
        ]);

        return hash('sha256', $payload);
    }
}
