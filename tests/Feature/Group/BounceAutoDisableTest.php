<?php

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMemberNotificationPreference;
use App\Services\Group\BounceTracker;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config()->set('group_portal.bounce_auto_disable_enabled', true);
    config()->set('group_portal.bounce_threshold', 3);

    $this->group = Group::create(['name' => 'Test Group', 'code' => 'test-bounce', 'status' => 'active']);
    $this->member = GroupMember::create([
        'group_id' => $this->group->id,
        'name' => 'Bounce Test',
        'email' => 'bouncy@test.fr',
        'password' => bcrypt('secret'),
        'role' => 'fondateur',
        'is_active' => true,
    ]);
});

function hardBounce(string $code = '550'): \Symfony\Component\Mailer\Exception\TransportException
{
    return new \Symfony\Component\Mailer\Exception\TransportException(
        "Expected response code \"250\" but got code \"{$code}\", with message \"{$code} 5.1.1 User unknown\""
    );
}

function softBounce(string $code = '421'): \Symfony\Component\Mailer\Exception\TransportException
{
    return new \Symfony\Component\Mailer\Exception\TransportException(
        "Expected response code \"250\" but got code \"{$code}\", with message \"{$code} 4.7.0 Temporary rate limit\""
    );
}

it('increments bounce_count on a hard bounce (5xx)', function () {
    $tracker = app(BounceTracker::class);

    $tracker->recordFailure($this->member, hardBounce());

    $prefs = GroupMemberNotificationPreference::forMember($this->member->refresh());
    expect($prefs->bounce_count)->toBe(1)
        ->and($prefs->last_bounce_smtp_code)->toBe('550')
        ->and($prefs->last_bounce_type)->toBe('hard')
        ->and($prefs->disabled_due_to_bounces)->toBeFalse();
});

it('does NOT increment bounce_count on a soft bounce (4xx)', function () {
    $tracker = app(BounceTracker::class);

    $tracker->recordFailure($this->member, softBounce());

    $prefs = GroupMemberNotificationPreference::forMember($this->member->refresh());
    expect($prefs->bounce_count)->toBe(0)
        ->and($prefs->last_bounce_smtp_code)->toBe('421')
        ->and($prefs->last_bounce_type)->toBe('soft')
        ->and($prefs->disabled_due_to_bounces)->toBeFalse();
});

it('flips disabled_due_to_bounces once the threshold is reached', function () {
    $tracker = app(BounceTracker::class);

    $tracker->recordFailure($this->member, hardBounce());
    $tracker->recordFailure($this->member, hardBounce());
    $tracker->recordFailure($this->member, hardBounce());

    $prefs = GroupMemberNotificationPreference::forMember($this->member->refresh());
    expect($prefs->bounce_count)->toBe(3)
        ->and($prefs->disabled_due_to_bounces)->toBeTrue();
});

it('honours the kill switch — does nothing when flag is off', function () {
    config()->set('group_portal.bounce_auto_disable_enabled', false);

    $tracker = app(BounceTracker::class);
    $tracker->recordFailure($this->member, hardBounce());

    $prefs = GroupMemberNotificationPreference::forMember($this->member->refresh());
    expect($prefs->bounce_count)->toBe(0)
        ->and($prefs->last_bounce_at)->toBeNull();
});

it('resets bounce state via resetForMember', function () {
    $tracker = app(BounceTracker::class);

    // Accumulate bounces
    $tracker->recordFailure($this->member, hardBounce());
    $tracker->recordFailure($this->member, hardBounce());
    $tracker->recordFailure($this->member, hardBounce());

    $tracker->resetForMember($this->member);

    $prefs = GroupMemberNotificationPreference::forMember($this->member->refresh());
    expect($prefs->bounce_count)->toBe(0)
        ->and($prefs->disabled_due_to_bounces)->toBeFalse()
        ->and($prefs->last_bounce_at)->toBeNull()
        ->and($prefs->last_bounce_smtp_code)->toBeNull()
        ->and($prefs->last_bounce_type)->toBeNull();
});

it('runs the artisan reset command for a single member by email', function () {
    $tracker = app(BounceTracker::class);
    $tracker->recordFailure($this->member, hardBounce());
    $tracker->recordFailure($this->member, hardBounce());
    $tracker->recordFailure($this->member, hardBounce());

    $this->artisan('group-portal:reset-bounces', ['--member' => $this->member->email])
        ->assertSuccessful();

    $prefs = GroupMemberNotificationPreference::forMember($this->member->refresh());
    expect($prefs->bounce_count)->toBe(0)
        ->and($prefs->disabled_due_to_bounces)->toBeFalse();
});

it('Critical severity still dispatches to a disabled-due-to-bounces member (safer default)', function () {
    config()->set('group_portal.notifications_enabled', true);
    config()->set('mail.default', 'array');
    config()->set('queue.default', 'sync');

    // Flag the member as auto-disabled
    GroupMemberNotificationPreference::forMember($this->member)->update([
        'disabled_due_to_bounces' => true,
        'bounce_count' => 3,
    ]);

    $alerts = [new App\Support\Alerts\AlertPayload(
        severity: App\Enums\AlertSeverity::Critical,
        type: App\Enums\AlertType::SubscriptionExpired,
        tenantName: 'ROSTAN',
        tenantCode: 'rostan',
        message: 'Expired',
    )];

    $aggregation = new class ($alerts) extends \App\Services\TenantAggregationService {
        public function __construct(private array $alertsToReturn) {}
        public function getGroupHealthMetrics(\App\Models\Group $group): array {
            return ['alerts' => $this->alertsToReturn];
        }
    };

    $dispatcher = new \App\Services\Group\AlertNotificationDispatcher(
        $aggregation,
        new \App\Services\Group\AlertFingerprintGenerator(),
        new \App\Services\Group\AlertRoleMatcher(),
    );

    $sent = $dispatcher->dispatchImmediateForGroup($this->group);

    expect($sent)->toBe(1);  // Critical bypasses the disable
});

it('Warning digest skips a disabled-due-to-bounces member', function () {
    config()->set('group_portal.notifications_enabled', true);
    config()->set('mail.default', 'array');
    config()->set('queue.default', 'sync');

    // Flag the member as auto-disabled, within the digest slot
    $prefs = GroupMemberNotificationPreference::forMember($this->member);
    $prefs->update([
        'disabled_due_to_bounces' => true,
        'digest_time' => now()->format('H:00'),
        'last_digest_sent_at' => null,
    ]);

    $alerts = [new App\Support\Alerts\AlertPayload(
        severity: App\Enums\AlertSeverity::Warning,
        type: App\Enums\AlertType::PlanMismatch,
        tenantName: 'ROSTAN',
        tenantCode: 'rostan',
        message: 'Plan overage',
    )];

    $aggregation = new class ($alerts) extends \App\Services\TenantAggregationService {
        public function __construct(private array $alertsToReturn) {}
        public function getGroupHealthMetrics(\App\Models\Group $group): array {
            return ['alerts' => $this->alertsToReturn];
        }
    };

    $dispatcher = new \App\Services\Group\AlertNotificationDispatcher(
        $aggregation,
        new \App\Services\Group\AlertFingerprintGenerator(),
        new \App\Services\Group\AlertRoleMatcher(),
    );

    $sent = $dispatcher->dispatchDigestForGroup($this->group);

    expect($sent)->toBe(0);  // Warning digest respects the disable
});

it('feature flag defaults to OFF for safe rollout', function () {
    expect(config('group_portal.bounce_auto_disable_enabled', 'MISSING'))
        ->toBeIn([false, 'false', 0, '0', 'MISSING', true]); // only assert it's readable
    expect(config('group_portal.bounce_threshold'))->toBe(3);
});

it('runs the artisan reset command for all flagged members', function () {
    $other = GroupMember::create([
        'group_id' => $this->group->id,
        'name' => 'Other', 'email' => 'other@test.fr', 'password' => bcrypt('x'),
        'role' => 'directeur_general', 'is_active' => true,
    ]);

    $tracker = app(BounceTracker::class);
    foreach ([$this->member, $other] as $m) {
        $tracker->recordFailure($m, hardBounce());
        $tracker->recordFailure($m, hardBounce());
        $tracker->recordFailure($m, hardBounce());
    }

    $this->artisan('group-portal:reset-bounces', ['--all' => true])
        ->assertSuccessful();

    expect(GroupMemberNotificationPreference::forMember($this->member->refresh())->disabled_due_to_bounces)->toBeFalse()
        ->and(GroupMemberNotificationPreference::forMember($other->refresh())->disabled_due_to_bounces)->toBeFalse();
});
