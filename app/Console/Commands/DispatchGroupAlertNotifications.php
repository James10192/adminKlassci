<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Services\Group\AlertNotificationDispatcher;
use Illuminate\Console\Command;

class DispatchGroupAlertNotifications extends Command
{
    protected $signature = 'group:dispatch-alert-notifications {--group= : Limit to a single group code for testing}';

    protected $description = 'Dispatches immediate Critical email notifications for the group portal alerts pipeline (PR-C).';

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
            $count = $dispatcher->dispatchImmediateForGroup($group);
            $totalSent += $count;

            if ($count > 0) {
                $this->line("[{$group->code}] dispatched {$count} critical alert(s).");
            }
        }

        $this->info("Immediate dispatch complete — {$totalSent} email(s) sent across {$groups->count()} group(s).");

        return self::SUCCESS;
    }
}
