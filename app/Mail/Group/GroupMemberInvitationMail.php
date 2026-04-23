<?php

namespace App\Mail\Group;

use App\Mail\Concerns\TracksBounces;
use App\Models\GroupMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Welcome email sent when an admin invites a new group member.
 *
 * Does NOT extend AbstractGroupAlertMail because (a) the recipient isn't
 * resolving an alert, and (b) we only track bounces against the member's
 * notification preferences — which don't exist yet for a brand-new invitee.
 * The failed() hook still fires for observability (log-only, no auto-disable).
 */
class GroupMemberInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, TracksBounces;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public GroupMember $member,
        public string $temporaryPassword,
        public string $activationUrl,
    ) {
    }

    public function build(): self
    {
        return $this->subject('[KLASSCI] Invitation — Portail groupe')
            ->view('emails.group.member-invitation')
            ->with([
                'member' => $this->member,
                'temporaryPassword' => $this->temporaryPassword,
                'activationUrl' => $this->activationUrl,
                'ttlHours' => (int) config('group_portal.invitation_ttl_hours', 24),
            ]);
    }

}
