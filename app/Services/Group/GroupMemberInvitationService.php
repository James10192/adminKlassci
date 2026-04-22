<?php

namespace App\Services\Group;

use App\Mail\Group\GroupMemberInvitationMail;
use App\Models\GroupMember;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Orchestrates the member invitation flow:
 *
 *   1. Generate a secure temporary password (Str::password under the hood)
 *   2. Hash it + persist on the member (password column + password_changed_at nullable)
 *   3. Generate a raw 64-char invitation token, store its sha256 hash on the
 *      member, keep the raw token only in memory for the outgoing URL
 *   4. Build a Laravel signed activation URL pointing at /groupe/activate,
 *      with a TTL from config (default 24h)
 *   5. Send the GroupMemberInvitationMail which carries the activation URL
 *      and the temporary password (transport is SMTP; bounce tracker picks
 *      up failures via AbstractGroupAlertMail::failed())
 *
 * The raw token is returned from invite() for testing purposes — production
 * code should not need it outside the sent email.
 */
class GroupMemberInvitationService
{
    public function __construct(
        protected TemporaryPasswordGenerator $passwordGenerator,
    ) {
    }

    /**
     * Invite (or re-invite) a member. Returns the raw invitation token so
     * tests can reconstruct the signed URL without parsing the email.
     */
    public function invite(GroupMember $member): string
    {
        if (! config('group_portal.invite_flow_enabled', false)) {
            Log::info('[group-notifications] invite_flow disabled — skipping invitation', [
                'member_id' => $member->id,
            ]);

            return '';
        }

        $password = $this->passwordGenerator->generate();
        $rawToken = Str::random(64);

        $member->forceFill([
            'password' => Hash::make($password),
            'password_changed_at' => null,
            'invitation_token' => hash('sha256', $rawToken),
            'invitation_sent_at' => now(),
        ])->save();

        $activationUrl = $this->buildActivationUrl($member, $rawToken);

        try {
            Mail::to($member->email)->send(new GroupMemberInvitationMail(
                $member,
                $password,
                $activationUrl,
            ));
        } catch (\Throwable $e) {
            // Log but do not re-throw — the admin flow continues and the
            // member can be re-invited. Bounce tracker catches async failures
            // via the Mailable's failed() hook.
            Log::error('[group-notifications] invitation mail dispatch failed', [
                'member_id' => $member->id,
                'email' => $member->email,
                'error' => $e->getMessage(),
            ]);
        }

        return $rawToken;
    }

    private function buildActivationUrl(GroupMember $member, string $rawToken): string
    {
        return URL::temporarySignedRoute(
            'groupe.invitation.activate',
            now()->addHours((int) config('group_portal.invitation_ttl_hours', 24)),
            [
                'member' => $member->id,
                'token' => $rawToken,
            ],
        );
    }
}
