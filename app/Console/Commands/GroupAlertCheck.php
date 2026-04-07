<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Services\TenantAggregationService;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GroupAlertCheck extends Command
{
    protected $signature = 'group:alert-check {--group= : Check specific group by code}';

    protected $description = 'Check KPI thresholds for groups and send alert notifications to members';

    public function handle(TenantAggregationService $service): int
    {
        $query = Group::active();

        if ($groupCode = $this->option('group')) {
            $query->where('code', $groupCode);
        }

        $groups = $query->get();

        if ($groups->isEmpty()) {
            $this->warn('No active groups found.');

            return self::SUCCESS;
        }

        $totalAlerts = 0;

        foreach ($groups as $group) {
            $this->info("Checking group: {$group->name} ({$group->code})");
            $alertCount = $this->checkGroup($group, $service);
            $totalAlerts += $alertCount;
        }

        $this->info("Done. {$totalAlerts} alert(s) sent.");

        return self::SUCCESS;
    }

    private function checkGroup(Group $group, TenantAggregationService $service): int
    {
        $alertCount = 0;
        $recipients = $group->members()->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            $this->warn("  No active members for group {$group->code}, skipping.");

            return 0;
        }

        $kpis = $service->getGroupKpis($group);
        $establishments = $kpis['establishments'] ?? [];

        foreach ($establishments as $tenantCode => $tenantKpis) {
            if ($tenantKpis['error'] ?? false) {
                continue;
            }

            // --- Collection rate alerts ---
            $rate = $tenantKpis['collection_rate'] ?? 0;
            $tenantName = $tenantKpis['tenant_name'] ?? $tenantCode;

            if ($rate < 30 && ($tenantKpis['revenue_expected'] ?? 0) > 0) {
                if ($this->sendAlertIfNotDuplicate(
                    'ALERTE: recouvrement très faible',
                    "{$tenantName}: {$rate}% de recouvrement — situation critique",
                    'heroicon-o-exclamation-triangle',
                    'danger',
                    $recipients
                )) {
                    $alertCount++;
                    $this->line("  [CRITICAL] {$tenantName}: {$rate}% collection rate");
                }
            } elseif ($rate < 50 && ($tenantKpis['revenue_expected'] ?? 0) > 0) {
                if ($this->sendAlertIfNotDuplicate(
                    'Taux de recouvrement critique',
                    "{$tenantName}: {$rate}% de recouvrement",
                    'heroicon-o-exclamation-triangle',
                    'warning',
                    $recipients
                )) {
                    $alertCount++;
                    $this->line("  [WARNING] {$tenantName}: {$rate}% collection rate");
                }
            }
        }

        // --- Subscription alerts ---
        $tenants = $group->activeTenants()->get();

        foreach ($tenants as $tenant) {
            if (! $tenant->subscription_end_date) {
                continue;
            }

            $daysLeft = Carbon::now()->diffInDays($tenant->subscription_end_date, false);

            if ($daysLeft < 0) {
                // Expired
                if ($this->sendAlertIfNotDuplicate(
                    'Abonnement expiré',
                    "{$tenant->name}: abonnement expiré depuis " . abs((int) $daysLeft) . ' jour(s)',
                    'heroicon-o-x-circle',
                    'danger',
                    $recipients
                )) {
                    $alertCount++;
                    $this->line("  [EXPIRED] {$tenant->name}: subscription expired " . abs((int) $daysLeft) . ' days ago');
                }
            } elseif ($daysLeft <= 30) {
                // Expiring soon
                if ($this->sendAlertIfNotDuplicate(
                    'Abonnement expire bientôt',
                    "{$tenant->name}: abonnement expire dans {$daysLeft} jour(s)",
                    'heroicon-o-clock',
                    'warning',
                    $recipients
                )) {
                    $alertCount++;
                    $this->line("  [EXPIRING] {$tenant->name}: subscription expires in {$daysLeft} days");
                }
            }
        }

        return $alertCount;
    }

    /**
     * Send a notification only if a similar one (same title) was not sent in the last 24h to the first recipient.
     */
    private function sendAlertIfNotDuplicate(
        string $title,
        string $body,
        string $icon,
        string $iconColor,
        $recipients
    ): bool {
        // Check against the first recipient to avoid duplicate checks for all
        $firstRecipient = $recipients->first();

        if (! $firstRecipient) {
            return false;
        }

        $recentExists = DB::table('notifications')
            ->where('notifiable_type', get_class($firstRecipient))
            ->where('notifiable_id', $firstRecipient->id)
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->where('data->title', $title)
            ->exists();

        if ($recentExists) {
            return false;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->icon($icon)
            ->iconColor($iconColor);

        // Use notifyNow to bypass queue (no jobs table needed)
        $dbNotification = $notification->toDatabase();

        foreach ($recipients as $recipient) {
            $recipient->notifyNow($dbNotification);
        }

        Log::info("GroupAlertCheck: sent '{$title}' to {$recipients->count()} member(s)");

        return true;
    }
}
