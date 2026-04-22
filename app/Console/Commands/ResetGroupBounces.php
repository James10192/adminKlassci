<?php

namespace App\Console\Commands;

use App\Models\GroupMember;
use App\Models\GroupMemberNotificationPreference;
use App\Services\Group\BounceTracker;
use Illuminate\Console\Command;

class ResetGroupBounces extends Command
{
    protected $signature = 'group-portal:reset-bounces
                            {--member= : Reset a single member by id or email}
                            {--all : Reset every member currently flagged disabled_due_to_bounces}';

    protected $description = 'Reset bounce counters + lift the auto-disable flag on group member email notifications.';

    public function handle(BounceTracker $tracker): int
    {
        if ($this->option('member')) {
            return $this->resetSingle($tracker, (string) $this->option('member'));
        }

        if ($this->option('all')) {
            return $this->resetAll($tracker);
        }

        $this->error('Specify either --member={id|email} or --all.');

        return self::INVALID;
    }

    private function resetSingle(BounceTracker $tracker, string $needle): int
    {
        $member = is_numeric($needle)
            ? GroupMember::find((int) $needle)
            : GroupMember::where('email', $needle)->first();

        if (! $member) {
            $this->error("No GroupMember matches {$needle}.");

            return self::FAILURE;
        }

        $tracker->resetForMember($member);
        $this->info("Reset bounces for {$member->email} (id={$member->id}).");

        return self::SUCCESS;
    }

    private function resetAll(BounceTracker $tracker): int
    {
        $flagged = GroupMemberNotificationPreference::query()
            ->where('disabled_due_to_bounces', true)
            ->pluck('group_member_id');

        if ($flagged->isEmpty()) {
            $this->info('No members currently flagged — nothing to reset.');

            return self::SUCCESS;
        }

        $members = GroupMember::whereIn('id', $flagged)->get();

        foreach ($members as $member) {
            $tracker->resetForMember($member);
            $this->line("  → reset {$member->email}");
        }

        $this->info("Reset bounces for {$members->count()} member(s).");

        return self::SUCCESS;
    }
}
