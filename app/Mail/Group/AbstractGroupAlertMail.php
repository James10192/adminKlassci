<?php

namespace App\Mail\Group;

use App\Mail\Concerns\TracksBounces;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Shared plumbing for every notification email the group portal sends.
 * Concrete subclasses decide only subject + view + payload shape; the retry
 * policy, queueability, and signed-unsubscribe URL construction live here.
 */
abstract class AbstractGroupAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, TracksBounces;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public Group $group,
        public GroupMember $member,
    ) {
    }

    /**
     * Every alert email footer offers a one-click opt-out. The `type` path
     * parameter decides what gets disabled when the link is clicked:
     *   - an AlertType::value string → adds to disabled_alert_types
     *   - 'digest' → flips daily_digest_warnings off
     *   - anything else → falls through to email_enabled = false (kill all)
     */
    protected function buildUnsubscribeUrl(string $type): string
    {
        return URL::signedRoute('groupe.notifications.unsubscribe', [
            'member' => $this->member->id,
            'type' => $type,
        ]);
    }
}
