<?php

namespace App\Services\Group;

use App\Enums\AlertSeverity;
use App\Mail\Group\CriticalAlertMail;
use App\Mail\Group\DailyAlertDigestMail;
use App\Models\Group;
use App\Models\GroupAlertNotificationLog;
use App\Models\GroupMember;
use App\Models\GroupMemberNotificationPreference;
use App\Services\TenantAggregationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Core of the founder email-notifications pipeline. Orchestrates:
 *
 *   1. Fetch current alerts for a group (via cached getGroupHealthMetrics)
 *   2. For each active member: filter by preferences + role matrix
 *   3. Per-alert dedup via fingerprint + sent_at window
 *   4. Immediate dispatch of Critical severity — digest-buffer the rest
 *   5. Log every dispatch (or skip reason) for audit + future retry
 *
 * Separated from `TenantAggregationService` on purpose — the service
 * computes alerts, the dispatcher decides who hears about them. No overlap,
 * no cross-cutting concerns.
 */
class AlertNotificationDispatcher
{
    public function __construct(
        protected TenantAggregationService $aggregationService,
        protected AlertFingerprintGenerator $fingerprints,
        protected AlertRoleMatcher $roleMatcher,
    ) {
    }

    /**
     * Run the immediate-critical pass for a group — dispatches individual
     * `CriticalAlertMail` for every (member, alert) pair that passes all
     * filters. Warnings and Info severity alerts are left for the digest
     * runner (SendGroupAlertDigests command).
     *
     * Returns the count of actual mails dispatched (post-dedup).
     */
    public function dispatchImmediateForGroup(Group $group): int
    {
        if (! config('group_portal.notifications_enabled', false)) {
            return 0;
        }

        $health = $this->aggregationService->getGroupHealthMetrics($group);
        $alerts = collect($health['alerts'] ?? [])
            ->filter(fn ($alert) => ($alert['severity'] ?? '') === AlertSeverity::Critical->value)
            ->values()
            ->all();

        if (empty($alerts)) {
            return 0;
        }

        $members = $group->members()->where('is_active', true)->get();
        $sentCount = 0;

        foreach ($members as $member) {
            $prefs = GroupMemberNotificationPreference::forMember($member);

            if (! $prefs->email_enabled || ! $prefs->immediate_critical) {
                continue;
            }
            if (empty($member->email)) {
                continue;
            }

            foreach ($alerts as $alert) {
                if (! $this->memberAcceptsAlert($member, $prefs, $alert)) {
                    continue;
                }

                $fingerprint = $this->fingerprints->generate($group->id, $alert);
                if (GroupAlertNotificationLog::wasRecentlyNotified($member->id, $fingerprint, $prefs->dedup_hours)) {
                    continue;
                }

                try {
                    Mail::to($member->email)->send(new CriticalAlertMail($group, $member, $alert));
                    $this->logDispatch($group, $member, $alert, $fingerprint, 'immediate');
                    $sentCount++;
                } catch (\Throwable $e) {
                    Log::error('[group-notifications] immediate dispatch failed', [
                        'group' => $group->code,
                        'member' => $member->email,
                        'alert_type' => $alert['type'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $sentCount;
    }

    /**
     * Runs the digest pass — one email per member summarising all Warning
     * alerts they haven't already been notified about. Respects
     * `digest_time` by sending only when the current time is within the
     * member's configured slot AND `last_digest_sent_at` is more than 20h
     * ago (avoids sending two digests in one day if the runner fires
     * every 30 min).
     */
    public function dispatchDigestForGroup(Group $group): int
    {
        if (! config('group_portal.notifications_enabled', false)) {
            return 0;
        }

        $health = $this->aggregationService->getGroupHealthMetrics($group);
        $allAlerts = $health['alerts'] ?? [];
        $warningAlerts = array_values(array_filter(
            $allAlerts,
            fn ($alert) => ($alert['severity'] ?? '') === AlertSeverity::Warning->value
        ));

        if (empty($warningAlerts)) {
            return 0;
        }

        $members = $group->members()->where('is_active', true)->get();
        $sentCount = 0;

        foreach ($members as $member) {
            $prefs = GroupMemberNotificationPreference::forMember($member);

            if (! $prefs->email_enabled || ! $prefs->daily_digest_warnings) {
                continue;
            }
            if (empty($member->email)) {
                continue;
            }
            if (! $this->isWithinDigestSlot($prefs)) {
                continue;
            }

            // Filter alerts by member's opt-outs and role matrix
            $deliverables = [];
            foreach ($warningAlerts as $alert) {
                if (! $this->memberAcceptsAlert($member, $prefs, $alert)) {
                    continue;
                }

                $fingerprint = $this->fingerprints->generate($group->id, $alert);
                if (GroupAlertNotificationLog::wasRecentlyNotified($member->id, $fingerprint, $prefs->dedup_hours)) {
                    continue;
                }

                $deliverables[] = ['alert' => $alert, 'fingerprint' => $fingerprint];
            }

            if (empty($deliverables)) {
                continue;
            }

            try {
                $alertsForMail = array_column($deliverables, 'alert');
                Mail::to($member->email)->send(new DailyAlertDigestMail($group, $member, $alertsForMail));

                foreach ($deliverables as $d) {
                    $this->logDispatch($group, $member, $d['alert'], $d['fingerprint'], 'digest');
                }

                $prefs->update(['last_digest_sent_at' => now()]);
                $sentCount++;
            } catch (\Throwable $e) {
                Log::error('[group-notifications] digest dispatch failed', [
                    'group' => $group->code,
                    'member' => $member->email,
                    'alert_count' => count($deliverables),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sentCount;
    }

    private function memberAcceptsAlert(GroupMember $member, GroupMemberNotificationPreference $prefs, array $alert): bool
    {
        $type = $alert['type'] ?? null;
        if ($type === null || ! $prefs->acceptsAlertType($type)) {
            return false;
        }

        return $this->roleMatcher->isSubscribedByValue($member->role, $type);
    }

    private function isWithinDigestSlot(GroupMemberNotificationPreference $prefs): bool
    {
        // Only send if we're in the configured HH window AND the last digest
        // was >20h ago (or never). Matches a "once per day" invariant.
        $digestHour = (int) substr($prefs->digest_time, 0, 2);
        $currentHour = now()->hour;

        if ($currentHour !== $digestHour) {
            return false;
        }

        if ($prefs->last_digest_sent_at !== null
            && $prefs->last_digest_sent_at->greaterThan(now()->subHours(20))) {
            return false;
        }

        return true;
    }

    private function logDispatch(Group $group, GroupMember $member, array $alert, string $fingerprint, string $channel): void
    {
        GroupAlertNotificationLog::create([
            'group_member_id' => $member->id,
            'group_id' => $group->id,
            'tenant_code' => $alert['tenant_code'] ?? null,
            'alert_type' => $alert['type'] ?? 'unknown',
            'severity' => $alert['severity'] ?? 'info',
            'fingerprint' => $fingerprint,
            'channel' => $channel,
            'sent_at' => now(),
        ]);
    }
}
