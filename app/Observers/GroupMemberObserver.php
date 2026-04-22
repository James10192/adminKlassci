<?php

namespace App\Observers;

use App\Models\GroupMember;
use App\Services\Group\GroupMemberInvitationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Triggers the invitation flow for newly created members. Runs via the
 * `created` hook (not `creating`) so the row has an id — the activation URL
 * needs it for the signed route.
 *
 * Admins who paste their own password in the form are respected: if the
 * member already has password_changed_at set, we skip the invitation.
 * That preserves the manual-entry escape hatch when an ops cadre prefers to
 * hand-hold a member face-to-face.
 */
class GroupMemberObserver
{
    public function created(GroupMember $member): void
    {
        if (! config('group_portal.invite_flow_enabled', false)) {
            return;
        }

        // Admin chose to set the password themselves — honor that path.
        if ($member->password_changed_at !== null) {
            return;
        }

        // If no email: defer — PR C will wire the username-only case via
        // an artisan command that prints the activation URL on-screen.
        if (empty($member->email)) {
            return;
        }

        app(GroupMemberInvitationService::class)->invite($member);
    }
}
