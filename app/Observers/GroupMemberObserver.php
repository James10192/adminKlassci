<?php

namespace App\Observers;

use App\Models\GroupMember;
use App\Services\Group\GroupMemberInvitationService;
use App\Services\Group\UsernameGenerator;

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
    /**
     * Auto-generate a username before insert when the admin didn't supply
     * an email. Runs on `creating` (not `created`) so the username lands in
     * the same INSERT instead of a follow-up UPDATE.
     */
    public function creating(GroupMember $member): void
    {
        if (empty($member->username) && empty($member->email)) {
            $member->username = app(UsernameGenerator::class)->generate($member->name ?? '');
        }
    }

    public function created(GroupMember $member): void
    {
        if (! config('group_portal.invite_flow_enabled', false)) {
            return;
        }

        // Admin chose to set the password themselves — honor that path.
        if ($member->password_changed_at !== null) {
            return;
        }

        // Username-only members skip the email invitation. Admin must share
        // the temporary password via `php artisan group-portal:reset-password`
        // which prints the credentials on-screen.
        if (empty($member->email)) {
            return;
        }

        app(GroupMemberInvitationService::class)->invite($member);
    }
}
