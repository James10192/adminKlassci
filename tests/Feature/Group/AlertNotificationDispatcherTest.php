<?php

use App\Models\Group;
use App\Models\GroupAlertNotificationLog;
use App\Models\GroupMember;
use App\Models\GroupMemberNotificationPreference;
use App\Services\Group\AlertFingerprintGenerator;
use App\Services\Group\AlertNotificationDispatcher;
use App\Services\Group\AlertRoleMatcher;
use App\Services\TenantAggregationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config()->set('group_portal.notifications_enabled', true);
    config()->set('mail.default', 'array');      // in-memory mailer
    config()->set('queue.default', 'sync');      // ShouldQueue mailables run inline

    $this->group = Group::create(['name' => 'Test Group', 'code' => 'test', 'status' => 'active']);
    $this->member = GroupMember::create([
        'group_id' => $this->group->id,
        'name' => 'Test Fondateur',
        'email' => 'fondateur@test.fr',
        'password' => bcrypt('secret'),
        'role' => 'fondateur',
        'is_active' => true,
    ]);
});

/**
 * Anonymous class stub — avoids Mockery (not installed) for the narrow
 * need of overriding one method's return value.
 */
function stubAggregation(array $alerts): TenantAggregationService
{
    return new class($alerts) extends TenantAggregationService {
        public function __construct(private array $alertsToReturn)
        {
            // Parent constructor deliberately not called — only getGroupHealthMetrics
            // is exercised by the dispatcher's code path.
        }

        public function getGroupHealthMetrics(\App\Models\Group $group): array
        {
            return ['alerts' => $this->alertsToReturn];
        }
    };
}

function makeDispatcher(TenantAggregationService $aggregation): AlertNotificationDispatcher
{
    return new AlertNotificationDispatcher(
        $aggregation,
        new AlertFingerprintGenerator(),
        new AlertRoleMatcher(),
    );
}

it('dispatches immediate mail for a Critical alert to a fondateur', function () {
    $alerts = [[
        'severity' => 'critical',
        'tenant_code' => 'rostan',
        'tenant_name' => 'ROSTAN',
        'type' => 'subscription_expired',
        'message' => 'Abonnement expiré',
    ]];

    $sent = makeDispatcher(stubAggregation($alerts))->dispatchImmediateForGroup($this->group);

    expect($sent)->toBe(1);

    // Log row written for future dedup
    expect(GroupAlertNotificationLog::count())->toBe(1);
    $log = GroupAlertNotificationLog::first();
    expect($log->channel)->toBe('immediate');
    expect($log->alert_type)->toBe('subscription_expired');
    expect($log->severity)->toBe('critical');
});

it('skips Warning severity on the immediate pass (those go to the digest)', function () {
    $alerts = [[
        'severity' => 'warning',
        'tenant_code' => 'rostan',
        'tenant_name' => 'ROSTAN',
        'type' => 'plan_mismatch',
        'message' => 'Plan dépassé',
    ]];

    $sent = makeDispatcher(stubAggregation($alerts))->dispatchImmediateForGroup($this->group);

    expect($sent)->toBe(0);
    expect(GroupAlertNotificationLog::count())->toBe(0);
});

it('honours the kill switch — sends nothing when notifications_enabled is false', function () {
    config()->set('group_portal.notifications_enabled', false);

    $alerts = [[
        'severity' => 'critical', 'tenant_code' => 'rostan', 'tenant_name' => 'ROSTAN',
        'type' => 'subscription_expired', 'message' => 'Expired',
    ]];

    $sent = makeDispatcher(stubAggregation($alerts))->dispatchImmediateForGroup($this->group);

    expect($sent)->toBe(0);
    expect(GroupAlertNotificationLog::count())->toBe(0);
});

it('dedups — same fingerprint within the window is not re-sent', function () {
    $alerts = [[
        'severity' => 'critical', 'tenant_code' => 'rostan', 'tenant_name' => 'ROSTAN',
        'type' => 'subscription_expired', 'message' => 'Expired',
    ]];

    $dispatcher = makeDispatcher(stubAggregation($alerts));

    $first = $dispatcher->dispatchImmediateForGroup($this->group);
    $second = $dispatcher->dispatchImmediateForGroup($this->group);

    expect($first)->toBe(1);
    expect($second)->toBe(0);  // deduped
    expect(GroupAlertNotificationLog::count())->toBe(1);  // only one log row
});

it('respects the member opt-out for a specific AlertType', function () {
    GroupMemberNotificationPreference::forMember($this->member)->update([
        'disabled_alert_types' => ['subscription_expired'],
    ]);

    $alerts = [[
        'severity' => 'critical', 'tenant_code' => 'rostan', 'tenant_name' => 'ROSTAN',
        'type' => 'subscription_expired', 'message' => 'Expired',
    ]];

    $sent = makeDispatcher(stubAggregation($alerts))->dispatchImmediateForGroup($this->group);

    expect($sent)->toBe(0);
    expect(GroupAlertNotificationLog::count())->toBe(0);
});

it('filters by role matrix — directeur_financier skips ops alerts', function () {
    $this->member->update(['role' => 'directeur_financier']);

    $alerts = [[
        'severity' => 'critical', 'tenant_code' => 'rostan', 'tenant_name' => 'ROSTAN',
        'type' => 'ssl_expiring', 'message' => 'SSL expiring',
    ]];

    $sent = makeDispatcher(stubAggregation($alerts))->dispatchImmediateForGroup($this->group);

    expect($sent)->toBe(0);  // SSL is an ops alert, not financial
    expect(GroupAlertNotificationLog::count())->toBe(0);
});

it('directeur_financier DOES receive financial alerts', function () {
    $this->member->update(['role' => 'directeur_financier']);

    $alerts = [[
        'severity' => 'critical', 'tenant_code' => 'rostan', 'tenant_name' => 'ROSTAN',
        'type' => 'unpaid_invoices', 'message' => 'Factures impayées',
    ]];

    $sent = makeDispatcher(stubAggregation($alerts))->dispatchImmediateForGroup($this->group);

    expect($sent)->toBe(1);
    expect(GroupAlertNotificationLog::count())->toBe(1);
});

it('skips members without an email address', function () {
    $this->member->update(['email' => '']);

    $alerts = [[
        'severity' => 'critical', 'tenant_code' => 'rostan', 'tenant_name' => 'ROSTAN',
        'type' => 'subscription_expired', 'message' => 'Expired',
    ]];

    $sent = makeDispatcher(stubAggregation($alerts))->dispatchImmediateForGroup($this->group);

    expect($sent)->toBe(0);
    expect(GroupAlertNotificationLog::count())->toBe(0);
});

it('skips inactive members', function () {
    $this->member->update(['is_active' => false]);

    $alerts = [[
        'severity' => 'critical', 'tenant_code' => 'rostan', 'tenant_name' => 'ROSTAN',
        'type' => 'subscription_expired', 'message' => 'Expired',
    ]];

    $sent = makeDispatcher(stubAggregation($alerts))->dispatchImmediateForGroup($this->group);

    expect($sent)->toBe(0);
    expect(GroupAlertNotificationLog::count())->toBe(0);
});

it('GroupAlertNotificationLog::wasRecentlyNotified returns true inside the window', function () {
    GroupAlertNotificationLog::create([
        'group_member_id' => $this->member->id,
        'group_id' => $this->group->id,
        'tenant_code' => 'rostan',
        'alert_type' => 'subscription_expired',
        'severity' => 'critical',
        'fingerprint' => 'abc123',
        'channel' => 'immediate',
        'sent_at' => now()->subHours(2),
    ]);

    expect(GroupAlertNotificationLog::wasRecentlyNotified($this->member->id, 'abc123', 24))->toBeTrue();
    expect(GroupAlertNotificationLog::wasRecentlyNotified($this->member->id, 'abc123', 1))->toBeFalse();
});
