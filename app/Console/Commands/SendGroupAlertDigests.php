<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Services\Group\AlertNotificationDispatcher;
use Illuminate\Console\Command;

class SendGroupAlertDigests extends Command
{
    protected $signature = 'group:send-alert-digests {--group= : Limit to a single group code for testing}';

    protected $description = 'Sends the daily warning-alerts digest for members whose preferences match the current hour slot (PR-C).';

    public function handle(AlertNotificationDispatcher $dispatcher): int
    {
        if (! config('group_portal.notifications_enabled', false)) {
            $this->info('group_portal.notifications_enabled is false — nothing to do.');
            return self::SUCCESS;
        }

        $query = Group::query()->where('status', 'active');
        if ($code = $this->option('group')) {
            $query->where('code', $code);
        }

        $groups = $query->get();
        $totalSent = 0;

        foreach ($groups as $group) {
            $count = $dispatcher->dispatchDigestForGroup($group);
            $totalSent += $count;

            if ($count > 0) {
                $this->line("[{$group->code}] sent digest to {$count} member(s).");
            }
        }

        $this->info("Digest dispatch complete — {$totalSent} email(s) sent across {$groups->count()} group(s).");

        return self::SUCCESS;
    }
}
