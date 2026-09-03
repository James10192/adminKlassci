<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Services\TenantAggregationService;
use App\Support\EtatMesure;
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
            $tenantName = $tenantKpis['tenant_name'] ?? $tenantCode;

            // Une ecole injoignable etait purement et simplement `continue`,
            // donc exemptee de TOUTES ses alertes financieres : elle paraissait
            // saine parce qu'on n'avait rien pu mesurer. L'absence de mesure
            // est elle-meme ce qu'il faut signaler.
            if (! EtatMesure::estMesure($tenantKpis['etat_finances'] ?? null)) {
                $motif = $tenantKpis['motif'] ?? EtatMesure::MOTIF_INJOIGNABLE;

                // `sans_annee` n'est pas une panne : la base a repondu, l'ecole
                // n'a pas encore ouvert son annee. On ne reveille pas un
                // fondateur pour ca.
                if ($motif === EtatMesure::MOTIF_INJOIGNABLE) {
                    // Le titre porte le code de l'ecole, et ce n'est pas
                    // cosmetique : `sendAlertIfNotDuplicate()` deduplique sur le
                    // TITRE SEUL. Avec un titre constant, trois ecoles tombant
                    // le meme matin n'auraient produit qu'UNE notification, les
                    // deux autres avalees pendant 24 h. Or les alertes
                    // financieres de ces tenants sont desormais passees : cette
                    // notification est le seul signal qu'il en reste.
                    if ($this->sendAlertIfNotDuplicate(
                        "Établissement non mesuré — {$tenantCode}",
                        "{$tenantName}: la base n'a pas répondu — aucun indicateur financier n'a pu être relevé",
                        'heroicon-o-signal-slash',
                        'warning',
                        $recipients
                    )) {
                        $alertCount++;
                        $this->line("  [UNMEASURED] {$tenantName}: database unreachable");
                    }
                }

                continue;
            }

            // --- Collection rate alerts ---
            $rate = $tenantKpis['collection_rate'] ?? 0;

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

        // ATTENTION : la deduplication porte sur le TITRE SEUL, pas sur le
        // corps. Une alerte qui concerne UN etablissement doit donc nommer cet
        // etablissement DANS SON TITRE, sinon la premiere ecole touchee fait
        // taire toutes les suivantes pendant 24 h.
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
