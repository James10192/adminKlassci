<?php

namespace App\Services\Group;

use App\Models\GroupMember;
use App\Models\GroupMemberNotificationPreference;
use Illuminate\Support\Facades\Log;

/**
 * Records mail transport failures against a member's notification preferences.
 *
 * Extracted from AlertNotificationDispatcher to keep dispatch logic focused on
 * "who hears about alerts". Bounce policy (SMTP-code parsing, hard-vs-soft,
 * threshold-based auto-disable) lives here so a future switch to webhook-based
 * bounce detection (Mailgun/SES events) only touches this class.
 *
 * Rules:
 *   - 5xx SMTP codes → hard bounce, increment counter
 *   - 4xx SMTP codes → soft bounce, logged only (transient issue)
 *   - No code parseable → treated as soft (safer: don't count ambiguous errors)
 *   - bounce_count >= bounce_threshold → flip disabled_due_to_bounces = true
 *
 * Critical severity always bypasses the disable — that decision lives in the
 * dispatcher, not here.
 */
class BounceTracker
{
    public function __construct(
        protected int $threshold,
    ) {
    }

    /**
     * Called from the Mailable's `failed()` hook with the final exception
     * raised after all retries. Extract the SMTP code, classify the bounce,
     * update the member's preferences.
     */
    public function recordFailure(GroupMember $member, \Throwable $exception): void
    {
        if (! config('group_portal.bounce_auto_disable_enabled', false)) {
            return;
        }

        $prefs = GroupMemberNotificationPreference::forMember($member);
        $code = $this->parseSmtpCode($exception->getMessage());
        $type = $this->classify($code);

        $updates = [
            'last_bounce_at' => now(),
            'last_bounce_smtp_code' => $code,
            'last_bounce_type' => $type,
        ];

        if ($type === 'hard') {
            $updates['bounce_count'] = $prefs->bounce_count + 1;

            if ($updates['bounce_count'] >= $this->threshold) {
                $updates['disabled_due_to_bounces'] = true;

                Log::warning('[group-notifications] member auto-disabled after bounce threshold', [
                    'member' => $member->email,
                    'group_id' => $member->group_id,
                    'bounce_count' => $updates['bounce_count'],
                    'last_code' => $code,
                ]);
            }
        } else {
            Log::info('[group-notifications] soft bounce logged (no disable)', [
                'member' => $member->email,
                'smtp_code' => $code,
            ]);
        }

        $prefs->update($updates);
    }

    /**
     * Resets the bounce counter for a single member, re-enabling mail dispatch.
     * Called by the reset Artisan command or after a manual investigation.
     */
    public function resetForMember(GroupMember $member): void
    {
        $prefs = GroupMemberNotificationPreference::forMember($member);

        $prefs->update([
            'bounce_count' => 0,
            'last_bounce_at' => null,
            'last_bounce_smtp_code' => null,
            'last_bounce_type' => null,
            'disabled_due_to_bounces' => false,
        ]);
    }

    /**
     * Extracts the last 3-digit SMTP code from the exception message.
     * Symfony Mailer propagates messages like:
     *   'Expected response code "250" but got code "550", with message "550 5.1.1 User unknown"'
     * We want 550 (server's actual response), not 250 (client's expected).
     *
     * Strategy: two anchored patterns that only match codes in SMTP context:
     *   1. `550 5.1.1` — code + enhanced status triple (RFC 3463)
     *   2. `got|response|code ... "550"` — code after a keyword
     * Port numbers (587, 465, 25), TLS versions (1.2), and timestamps (200ms)
     * are rejected because none of them carry an enhanced-status suffix or
     * one of the keyword prefixes.
     */
    public function parseSmtpCode(string $message): ?string
    {
        // Pattern 1: code + enhanced status (e.g. "550 5.7.1") — most reliable
        if (preg_match('/\b([45]\d{2})\s+\d+\.\d+\.\d+\b/', $message, $matches)) {
            return $matches[1];
        }

        // Pattern 2: keyword-prefixed code (catches "got code \"550\"" and
        // "response: \"421 temporary...\""). We prefer the LAST match so the
        // server's response wins over the client's expected code.
        if (preg_match_all('/(?:got|response|code)[^0-9]{0,20}?["\']?([45]\d{2})/i', $message, $matches)) {
            $codes = array_values(array_filter($matches[1]));
            if (! empty($codes)) {
                return end($codes);
            }
        }

        return null;
    }

    private function classify(?string $code): string
    {
        if ($code === null) {
            return 'soft';
        }

        return str_starts_with($code, '5') ? 'hard' : 'soft';
    }
}
