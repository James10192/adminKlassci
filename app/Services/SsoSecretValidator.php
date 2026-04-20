<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Validates GROUP_SSO_SHARED_SECRET presence and length at app boot to prevent
 * silent SSO failures (widgets generating disabled buttons because config is missing).
 *
 * Called from AppServiceProvider::boot(). Logs critical if misconfigured.
 * SSO is optional at the tenant level — master app should still boot cleanly so
 * admins can troubleshoot.
 */
class SsoSecretValidator
{
    public static function validate(): void
    {
        $secret = config('services.group_sso.secret') ?: env('GROUP_SSO_SHARED_SECRET');

        if (empty($secret)) {
            Log::warning('[SSO] GROUP_SSO_SHARED_SECRET is not configured — cross-app SSO disabled');
            return;
        }

        if (strlen($secret) < 32) {
            Log::critical('[SSO] GROUP_SSO_SHARED_SECRET is too short (' . strlen($secret) . ' chars, 32 required) — SSO tokens will be rejected');
            return;
        }
    }
}
