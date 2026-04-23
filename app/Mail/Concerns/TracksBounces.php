<?php

namespace App\Mail\Concerns;

use App\Services\Group\BounceTracker;

/**
 * Shared `failed()` hook for every Mailable addressed to a GroupMember.
 *
 * The consumer must expose `$this->member` — both `AbstractGroupAlertMail`
 * and `GroupMemberInvitationMail` do. Keeps the bounce-tracking policy in
 * one spot: if we later rewrite the tracker (webhook-based bounce, external
 * provider), only this trait and BounceTracker itself change.
 */
trait TracksBounces
{
    public function failed(\Throwable $exception): void
    {
        app(BounceTracker::class)->recordFailure($this->member, $exception);
    }
}
