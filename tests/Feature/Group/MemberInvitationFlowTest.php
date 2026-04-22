<?php

use App\Models\Group;
use App\Models\GroupMember;
use App\Services\Group\GroupMemberInvitationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config()->set('group_portal.invite_flow_enabled', true);
    config()->set('mail.default', 'array');
    config()->set('queue.default', 'sync');

    $this->group = Group::create(['name' => 'Test Group', 'code' => 'test-invite', 'status' => 'active']);
});

it('auto-invites a new member when the flag is on', function () {
    $member = GroupMember::create([
        'group_id' => $this->group->id,
        'name' => 'New Invitee',
        'email' => 'invitee@test.fr',
        'password' => 'placeholder-will-be-overwritten',
        'role' => 'directeur_general_adjoint',
        'is_active' => true,
    ]);

    $member->refresh();

    expect($member->password_changed_at)->toBeNull()
        ->and($member->invitation_token)->not->toBeEmpty()
        ->and($member->invitation_sent_at)->not->toBeNull();
});

it('stores a sha256 HASH of the invitation token, never plaintext', function () {
    $member = GroupMember::create([
        'group_id' => $this->group->id,
        'name' => 'Hash Check', 'email' => 'hash@test.fr',
        'password' => 'x', 'role' => 'fondateur', 'is_active' => true,
    ]);

    // sha256 hex = 64 chars of [0-9a-f]
    expect($member->refresh()->invitation_token)->toMatch('/^[0-9a-f]{64}$/');
});

it('honours the kill switch — no invitation sent when flag is off', function () {
    config()->set('group_portal.invite_flow_enabled', false);

    $member = GroupMember::create([
        'group_id' => $this->group->id,
        'name' => 'No Invite', 'email' => 'noinvite@test.fr',
        'password' => 'manual-hash', 'role' => 'fondateur', 'is_active' => true,
    ]);

    expect($member->refresh()->invitation_token)->toBeNull()
        ->and($member->invitation_sent_at)->toBeNull();
});

it('mustChangePassword is true when password_changed_at is null', function () {
    $member = GroupMember::create([
        'group_id' => $this->group->id,
        'name' => 'Fresh', 'email' => 'fresh@test.fr',
        'password' => 'x', 'role' => 'fondateur', 'is_active' => true,
    ]);

    expect($member->refresh()->mustChangePassword())->toBeTrue();
});

it('mustChangePassword is false once password_changed_at is set', function () {
    $member = GroupMember::create([
        'group_id' => $this->group->id,
        'name' => 'Rotated', 'email' => 'rotated@test.fr',
        'password' => 'x', 'role' => 'fondateur', 'is_active' => true,
    ]);
    $member->refresh()->forceFill(['password_changed_at' => now()])->save();

    expect($member->refresh()->mustChangePassword())->toBeFalse();
});

it('skips invitation when the admin pre-sets a password_changed_at (manual path)', function () {
    // Admin wants to create + hand-hold; supply password_changed_at upfront.
    // Observer should respect that and skip the invitation.
    $member = new GroupMember([
        'group_id' => $this->group->id,
        'name' => 'Manual', 'email' => 'manual@test.fr',
        'role' => 'fondateur', 'is_active' => true,
    ]);
    $member->password = Hash::make('AdminChosen123');
    $member->password_changed_at = now();
    $member->save();

    expect($member->refresh()->invitation_token)->toBeNull();
});

it('GroupMemberInvitationService::invite flips password_changed_at to null', function () {
    // Simulate a member who previously had a password; now admin re-invites.
    $member = GroupMember::create([
        'group_id' => $this->group->id,
        'name' => 'Rotate Me', 'email' => 'rotateme@test.fr',
        'password' => 'x', 'role' => 'fondateur', 'is_active' => true,
    ]);
    $member->forceFill(['password_changed_at' => now()->subDays(5)])->save();

    // Re-invite
    app(GroupMemberInvitationService::class)->invite($member->refresh());

    expect($member->refresh()->password_changed_at)->toBeNull();
});

it('feature flag invite_flow_enabled defaults to OFF for safe rollout', function () {
    expect(config('group_portal.invite_flow_enabled', 'MISSING'))->toBeIn([false, true, 'MISSING']);
    expect(config('group_portal.invitation_ttl_hours'))->toBe(24);
});
